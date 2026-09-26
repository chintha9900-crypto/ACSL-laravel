<?php

namespace App\Actions\Membership;

use App\Exceptions\MembershipApplicationCannotBeReviewedException;
use App\Models\MembershipApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewMembershipApplication
{
    /**
     * The only transitions this phase allows: from `submitted` to one of these.
     * The history event recorded for each (docs/database/04 §11).
     *
     * @var array<string, string>
     */
    public const DECISION_EVENTS = [
        MembershipApplication::STATUS_APPROVED => 'application.approved',
        MembershipApplication::STATUS_REJECTED => 'application.rejected',
        MembershipApplication::STATUS_MORE_DETAILS_REQUIRED => 'application.more_details_requested',
    ];

    /**
     * Record an admin decision on a `submitted` application.
     *
     * "More details required" also creates the details request (its message is shown to the
     * applicant); the internal `$note` is never shown to them.
     *
     * The status change is a compare-and-set (`WHERE status = 'submitted'`), so two
     * admins acting at once cannot both succeed or overwrite each other: the loser
     * changes nothing and gets an exception. The status update, history row and audit
     * row are written in one transaction. Nothing else is created (no membership,
     * term, payment, user, token or notification).
     *
     * @throws MembershipApplicationCannotBeReviewedException
     */
    public function handle(MembershipApplication $application, User $admin, string $decision, ?string $note = null, ?string $requestMessage = null): void
    {
        if (! array_key_exists($decision, self::DECISION_EVENTS)) {
            throw new InvalidArgumentException("Unsupported review decision [{$decision}].");
        }

        $note = filled($note) ? trim($note) : null;
        $requestMessage = filled($requestMessage) ? trim($requestMessage) : null;

        if ($decision === MembershipApplication::STATUS_MORE_DETAILS_REQUIRED && $requestMessage === null) {
            throw new InvalidArgumentException('Requesting more details needs a message for the applicant.');
        }

        DB::transaction(function () use ($application, $admin, $decision, $note, $requestMessage): void {
            $now = now();
            $values = ['status' => $decision, 'decision_note' => $note, 'updated_at' => $now];

            if ($decision === MembershipApplication::STATUS_APPROVED) {
                $values += ['proof_reviewed_at' => $now, 'proof_reviewed_by_user_id' => $admin->id];
            }

            if ($decision !== MembershipApplication::STATUS_MORE_DETAILS_REQUIRED) {
                $values += ['decided_at' => $now, 'decided_by_user_id' => $admin->id];
            }

            $changed = DB::table('membership_applications')
                ->where('id', $application->id)
                ->where('status', MembershipApplication::STATUS_SUBMITTED)
                ->update($values);

            if ($changed !== 1) {
                throw new MembershipApplicationCannotBeReviewedException(
                    'This application is no longer awaiting a decision. Reload the page to see its current status.'
                );
            }

            if ($decision === MembershipApplication::STATUS_MORE_DETAILS_REQUIRED) {
                DB::table('membership_details_requests')->insert([
                    'membership_application_id' => $application->id,
                    'requested_by_user_id' => $admin->id,
                    'request_message' => $requestMessage,
                    'requested_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('membership_status_history')->insert([
                'membership_application_id' => $application->id,
                'event' => self::DECISION_EVENTS[$decision],
                'from_status' => MembershipApplication::STATUS_SUBMITTED,
                'to_status' => $decision,
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'note' => $note,
                'created_at' => $now,
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'actor_type' => 'user',
                'event' => 'membership_application.reviewed',
                'subject_type' => 'membership_application',
                'subject_id' => $application->id,
                'old_values' => json_encode(['status' => MembershipApplication::STATUS_SUBMITTED]),
                'new_values' => json_encode(['status' => $decision]),
                'ip_address' => Request::ip(),
                'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                'created_at' => $now,
            ]);
        });
    }
}
