<?php

namespace App\Console\Commands;

use App\Actions\Membership\DeliverMemberNotification;
use App\Models\MembershipTerm;
use App\Notifications\Membership\RenewalReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M10 — send a renewal reminder at each configured offset before a current
 * term's `expires_on` (docs/architecture/06 §2; approved offsets: 30/14/1
 * days). Idempotent: a `membership_status_history` marker per (term, offset)
 * is written inside the same lock that checks for one, so running this
 * command again — or twice in the same day — never sends a duplicate.
 */
class SendMembershipRenewalReminders extends Command
{
    protected $signature = 'membership:send-renewal-reminders';

    protected $description = 'Send M10 renewal reminders for terms expiring at the configured day offsets';

    public function handle(DeliverMemberNotification $deliver): int
    {
        $today = Carbon::now(config('membership.business_timezone'))->startOfDay();
        $sent = 0;

        foreach (config('membership.renewal_reminder_days') as $offset) {
            $targetDate = $today->copy()->addDays($offset)->toDateString();

            $terms = MembershipTerm::query()
                ->where('status', MembershipTerm::STATUS_ACTIVE)
                ->whereDate('expires_on', $targetDate)
                ->with(['membership.user'])
                ->get();

            foreach ($terms as $term) {
                // Already renewed/closed: a newer term exists, so this one's
                // expiry no longer matters to the member.
                $hasNewerTerm = MembershipTerm::query()
                    ->where('membership_id', $term->membership_id)
                    ->where('term_no', '>', $term->term_no)
                    ->exists();

                if ($hasNewerTerm) {
                    continue;
                }

                if (! $this->claim($term->membership_id, $term->id, $offset)) {
                    continue;
                }

                $deliver->handle($term->membership->user, new RenewalReminder($term, $offset));
                $sent++;
            }
        }

        $this->info("Sent {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }

    /**
     * Claim the (term, offset) reminder slot — locked read-then-insert, so two
     * overlapping runs cannot both send the same reminder.
     */
    private function claim(int $membershipId, int $termId, int $offset): bool
    {
        return DB::transaction(function () use ($membershipId, $termId, $offset): bool {
            $alreadySent = DB::table('membership_status_history')
                ->where('membership_term_id', $termId)
                ->where('event', 'membership.renewal_reminder_sent')
                ->where('note', (string) $offset)
                ->lockForUpdate()
                ->exists();

            if ($alreadySent) {
                return false;
            }

            DB::table('membership_status_history')->insert([
                'membership_id' => $membershipId,
                'membership_term_id' => $termId,
                'event' => 'membership.renewal_reminder_sent',
                'actor_type' => 'system',
                'note' => (string) $offset,
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
