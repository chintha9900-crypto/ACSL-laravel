<?php

namespace App\Actions\Membership;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Exceptions\ActivationEmailAlreadyInUseException;
use App\Exceptions\MembershipCannotBeActivatedException;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipNumberSequence;
use App\Models\MembershipSetting;
use App\Models\MembershipTerm;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class ActivateMembership
{
    public function __construct(private IssueAccountSetupToken $issueSetupToken) {}

    /**
     * Activate a newly approved application: provision the member's account (pending
     * setup), issue the permanent membership number, and start the free introductory
     * term (docs/database/04 §10.1). One transaction; on any failure nothing is kept,
     * including the sequence increment, so a retry is safe and numbers have no gaps.
     *
     * Creates no payment or plan, sends nothing, and never touches an existing user.
     * Exactly one setup token is issued, inside the transaction; its plaintext is
     * returned in memory only, so the caller can deliver the link once this method has
     * committed. It is never stored, audited or logged here.
     *
     * @throws MembershipCannotBeActivatedException
     * @throws ActivationEmailAlreadyInUseException
     */
    public function handle(MembershipApplication $application, User $admin): ActivatedMembership
    {
        try {
            return DB::transaction(fn (): ActivatedMembership => $this->activate($application, $admin), attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Backstops for a race that got past the row lock below.
            if (str_contains($exception->getMessage(), 'users_email_unique')) {
                throw new ActivationEmailAlreadyInUseException($this->emailMessage(), previous: $exception);
            }

            if (str_contains($exception->getMessage(), 'memberships_membership_application_id_unique')) {
                throw new MembershipCannotBeActivatedException($this->alreadyActivatedMessage(), previous: $exception);
            }

            throw $exception;
        }
    }

    private function activate(MembershipApplication $application, User $admin): ActivatedMembership
    {
        // Lock the application row: a second admin (or a double submit) waits here and
        // then sees the membership that the first one created.
        $locked = MembershipApplication::query()->whereKey($application->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== MembershipApplication::STATUS_APPROVED) {
            throw new MembershipCannotBeActivatedException('Only an approved application can be activated.');
        }

        if (Membership::query()->where('membership_application_id', $locked->id)->exists()) {
            throw new MembershipCannotBeActivatedException($this->alreadyActivatedMessage());
        }

        if (User::query()->where('email', $locked->email)->exists()) {
            throw new ActivationEmailAlreadyInUseException($this->emailMessage());
        }

        $category = MembershipCategory::query()->findOrFail($locked->membership_category_id);

        // The instant stays UTC; the business timezone only decides the calendar date and year.
        $now = now();
        $local = $now->copy()->setTimezone(config('membership.business_timezone'));
        $startsOn = $local->copy()->startOfDay();
        $months = (int) MembershipSetting::current()->introductory_period_months;
        $expiresOn = $startsOn->copy()->addMonthsNoOverflow($months)->subDay();

        $sequence = $this->nextSequence($category->id, $local->year);
        $number = $category->code
            .sprintf('%02d', $local->year % 100)
            .sprintf('%02d', random_int(0, 99))
            .sprintf('%04d', $sequence);

        $user = User::forceCreate([
            'name' => $locked->full_name,
            'email' => $locked->email,
            'password' => null,
            'role' => 'member',
            'status' => 'pending_setup',
        ]);

        $membership = Membership::forceCreate([
            'membership_application_id' => $locked->id,
            'user_id' => $user->id,
            'membership_category_id' => $category->id,
            'membership_number' => $number,
            'number_year' => $local->year,
            'number_sequence' => $sequence,
            'activated_at' => $now,
            'activated_on' => $startsOn->toDateString(),
        ]);

        $term = MembershipTerm::forceCreate([
            'membership_id' => $membership->id,
            'term_no' => 1,
            'term_kind' => MembershipTerm::KIND_INTRODUCTORY,
            'status' => MembershipTerm::STATUS_ACTIVE,
            'payment_status' => MembershipTerm::PAYMENT_NOT_REQUIRED,
            'duration_months' => $months,
            'starts_on' => $startsOn->toDateString(),
            'expires_on' => $expiresOn->toDateString(),
            'activated_at' => $now,
        ]);

        $setupToken = $this->issueSetupToken->handle($user, $membership->id, $admin);

        $this->record($locked, $membership, $term, $admin, $now);

        return new ActivatedMembership($membership, $setupToken);
    }

    /**
     * Take the next SSSS for the category and business year under a row lock — never
     * MAX()+1. The locking read comes first; only a missing row is inserted, then locked.
     */
    private function nextSequence(int $categoryId, int $year): int
    {
        $find = fn () => MembershipNumberSequence::query()
            ->where('membership_category_id', $categoryId)
            ->where('sequence_year', $year)
            ->lockForUpdate()
            ->first();

        $row = $find();

        if ($row === null) {
            $stamp = now()->toDateTimeString();
            DB::statement(
                'INSERT INTO membership_number_sequences (membership_category_id, sequence_year, last_number, created_at, updated_at)
                 VALUES (?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$categoryId, $year, $stamp, $stamp],
            );
            $row = $find();
        }

        $next = (int) $row->last_number + 1;

        if ($next > 9999) {
            throw new MembershipCannotBeActivatedException("The {$year} membership number sequence for this category is full.");
        }

        DB::table('membership_number_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }

    /**
     * The documented history events (docs/database/04 §11) and one audit row.
     */
    private function record(MembershipApplication $application, Membership $membership, MembershipTerm $term, User $admin, mixed $now): void
    {
        $history = fn (array $row) => DB::table('membership_status_history')->insert([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'created_at' => $now,
            ...$row,
        ]);

        $history(['membership_application_id' => $application->id, 'membership_id' => $membership->id, 'event' => 'membership.activated']);
        $history(['membership_application_id' => $application->id, 'membership_id' => $membership->id, 'event' => 'membership.number_issued', 'note' => $membership->membership_number]);
        $history([
            'membership_id' => $membership->id,
            'membership_term_id' => $term->id,
            'event' => 'membership.introductory_term_started',
            'to_status' => MembershipTerm::STATUS_ACTIVE,
            'note' => $term->starts_on->toDateString().' to '.$term->expires_on->toDateString(),
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'event' => 'membership.activated',
            'subject_type' => 'membership',
            'subject_id' => $membership->id,
            'new_values' => json_encode([
                'membership_application_id' => $application->id,
                'membership_number' => $membership->membership_number,
                'activated_on' => $membership->activated_on->toDateString(),
                'term_no' => 1,
                'expires_on' => $term->expires_on->toDateString(),
            ]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => $now,
        ]);
    }

    private function alreadyActivatedMessage(): string
    {
        return 'This application has already been activated.';
    }

    private function emailMessage(): string
    {
        return 'A user with this email address already exists. Activation was not performed and the existing account was not changed.';
    }
}
