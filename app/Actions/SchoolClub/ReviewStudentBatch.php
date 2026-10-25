<?php

namespace App\Actions\SchoolClub;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Exceptions\StudentBatchCannotBeReviewedException;
use App\Models\Membership;
use App\Models\MembershipTerm;
use App\Models\SchoolClubStudentBatch;
use App\Models\SchoolClubStudentEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewStudentBatch
{
    /**
     * Highest student suffix a School Club can issue (`/999`).
     */
    public const MAX_STUDENTS_PER_CLUB = 999;

    public function __construct(private IssueAccountSetupToken $issueSetupToken) {}

    /**
     * Decide a submitted student batch: approve, request more details or
     * decline. A compare-and-set on `status = submitted` means two admins can
     * never both decide it.
     *
     * Approval, in one transaction (all or nothing): for each student a
     * `pending_setup` member account, a free School Club membership linked to
     * the parent and numbered `<parent number>/<NNN>` from the parent's locked
     * `last_student_sequence` counter, its first free term, and a one-time
     * setup token. The plaintext tokens are returned for delivery after commit.
     *
     * @return list<array{user: User, membership: Membership, token: string}>
     *
     * @throws StudentBatchCannotBeReviewedException
     */
    public function handle(SchoolClubStudentBatch $batch, User $admin, string $decision, ?string $note = null, ?string $requestMessage = null): array
    {
        if (! in_array($decision, [SchoolClubStudentBatch::STATUS_APPROVED, SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED, SchoolClubStudentBatch::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException("Unsupported decision [{$decision}].");
        }

        $note = filled($note) ? trim($note) : null;
        $requestMessage = filled($requestMessage) ? trim($requestMessage) : null;

        if ($decision === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED && $requestMessage === null) {
            throw new InvalidArgumentException('Requesting more details needs a message for the representative.');
        }

        try {
            return DB::transaction(function () use ($batch, $admin, $decision, $note, $requestMessage): array {
                $now = now();
                $values = ['status' => $decision, 'decision_note' => $note, 'updated_at' => $now];
                if ($decision === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED) {
                    $values['request_message'] = $requestMessage;
                } else {
                    $values += ['decided_at' => $now, 'decided_by_user_id' => $admin->id];
                }

                $changed = DB::table('school_club_student_batches')
                    ->where('id', $batch->id)
                    ->where('status', SchoolClubStudentBatch::STATUS_SUBMITTED)
                    ->update($values);

                if ($changed !== 1) {
                    throw new StudentBatchCannotBeReviewedException('This student batch is no longer awaiting a decision. Reload the page to see its current status.');
                }

                $activated = $decision === SchoolClubStudentBatch::STATUS_APPROVED
                    ? $this->activateStudents($batch, $admin, $now)
                    : [];

                DB::table('audit_logs')->insert([
                    'user_id' => $admin->id,
                    'actor_type' => 'user',
                    'event' => 'school_club.student_batch_reviewed',
                    'subject_type' => 'school_club_student_batch',
                    'subject_id' => $batch->id,
                    'old_values' => json_encode(['status' => SchoolClubStudentBatch::STATUS_SUBMITTED]),
                    'new_values' => json_encode(['status' => $decision, 'students_activated' => count($activated)]),
                    'ip_address' => Request::ip(),
                    'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                    'created_at' => $now,
                ]);

                return $activated;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StudentBatchCannotBeReviewedException('One of the students could not be activated because their email address or number is already in use. Nothing was changed.', previous: $exception);
        }
    }

    /**
     * @return list<array{user: User, membership: Membership, token: string}>
     */
    private function activateStudents(SchoolClubStudentBatch $batch, User $admin, Carbon $now): array
    {
        $parent = Membership::query()->whereKey($batch->membership_id)->with(['category', 'terms'])->lockForUpdate()->first();

        if ($parent === null || ! $parent->isSchoolClubMain() || ! $parent->hasCurrentTerm()) {
            throw new StudentBatchCannotBeReviewedException('The School Club membership is not currently active, so its students cannot be activated.');
        }

        $entries = SchoolClubStudentEntry::query()->where('school_club_student_batch_id', $batch->id)->orderBy('id')->lockForUpdate()->get();

        $taken = User::query()->whereIn('email', $entries->pluck('email'))->pluck('email');
        if ($taken->isNotEmpty()) {
            throw new StudentBatchCannotBeReviewedException('These email addresses now belong to existing accounts: '.$taken->implode(', ').'. Request more details so the school can correct them. Nothing was changed.');
        }

        if ($parent->last_student_sequence + $entries->count() > self::MAX_STUDENTS_PER_CLUB) {
            throw new StudentBatchCannotBeReviewedException('This School Club cannot have more than '.self::MAX_STUDENTS_PER_CLUB.' student members. Nothing was changed.');
        }

        $local = $now->copy()->setTimezone(config('membership.business_timezone'));
        $startsOn = $local->copy()->startOfDay();
        $months = (int) config('membership.school_club_term_months');
        $sequence = (int) $parent->last_student_sequence;
        $activated = [];

        foreach ($entries as $entry) {
            $sequence++;

            $user = User::forceCreate([
                'name' => $entry->full_name,
                'email' => $entry->email,
                'password' => null,
                'role' => 'member',
                'status' => 'pending_setup',
            ]);

            $membership = Membership::forceCreate([
                'membership_application_id' => null,
                'user_id' => $user->id,
                'membership_category_id' => $parent->membership_category_id,
                'parent_membership_id' => $parent->id,
                'school_club_student_batch_id' => $batch->id,
                'membership_number' => $parent->membership_number.'/'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'number_year' => $parent->number_year,
                'number_sequence' => $parent->number_sequence,
                'student_sequence' => $sequence,
                'activated_at' => $now,
                'activated_on' => $startsOn->toDateString(),
                'verification_token' => Str::random(48),
            ]);

            $term = MembershipTerm::forceCreate([
                'membership_id' => $membership->id,
                'term_no' => 1,
                'term_kind' => MembershipTerm::KIND_INTRODUCTORY,
                'status' => MembershipTerm::STATUS_ACTIVE,
                'payment_status' => MembershipTerm::PAYMENT_NOT_REQUIRED,
                'duration_months' => $months,
                'starts_on' => $startsOn->toDateString(),
                'expires_on' => $startsOn->copy()->addMonthsNoOverflow($months)->subDay()->toDateString(),
                'activated_at' => $now,
            ]);

            $entry->forceFill(['membership_id' => $membership->id])->save();

            DB::table('membership_status_history')->insert([
                'membership_id' => $membership->id,
                'membership_term_id' => $term->id,
                'event' => 'membership.student_activated',
                'to_status' => MembershipTerm::STATUS_ACTIVE,
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'note' => $membership->membership_number,
                'created_at' => $now,
            ]);

            $token = $this->issueSetupToken->handle($user, $membership->id, $admin);

            $activated[] = ['user' => $user, 'membership' => $membership, 'token' => $token];
        }

        DB::table('memberships')->where('id', $parent->id)->update(['last_student_sequence' => $sequence, 'updated_at' => $now]);

        return $activated;
    }
}
