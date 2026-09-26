<?php

namespace App\Console\Commands;

use App\Actions\Membership\DeliverMemberNotification;
use App\Models\MembershipTerm;
use App\Notifications\Membership\Expired;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M11 — flip a current term to `expired` once its `expires_on` has passed
 * (docs/architecture/04 §9). Idempotent: the guarded update only ever matches
 * a term still `active`, so running this command again is a no-op for terms
 * it already expired — no duplicate history row, no duplicate email.
 *
 * The membership number is never touched (only `membership_terms` changes);
 * `users` is never touched either — the account is retained, per the approved
 * rule, so the member can still sign in far enough to renew during the grace
 * period (`DashboardController` — not the login gate itself — is what
 * withholds the ordinary member experience while no current term exists).
 */
class ExpireMembershipTerms extends Command
{
    protected $signature = 'membership:expire-terms';

    protected $description = 'Expire membership terms past their expiry date and notify the member (M11)';

    public function handle(DeliverMemberNotification $deliver): int
    {
        $today = Carbon::now(config('membership.business_timezone'))->startOfDay();

        $terms = MembershipTerm::query()
            ->where('status', MembershipTerm::STATUS_ACTIVE)
            ->whereDate('expires_on', '<', $today->toDateString())
            ->with(['membership.user'])
            ->get();

        $expired = 0;

        foreach ($terms as $term) {
            if (! $this->expire($term)) {
                continue;
            }

            $graceEndsOn = $term->expires_on->copy()
                ->addMonthsNoOverflow((int) config('membership.renewal_grace_period_months'))
                ->format('j F Y');

            $deliver->handle($term->membership->user, new Expired($term, $graceEndsOn));
            $expired++;
        }

        $this->info("Expired {$expired} membership term(s).");

        return self::SUCCESS;
    }

    /**
     * A guarded, row-locked transition: only a term still `active` is flipped,
     * so a concurrent/duplicate run affects it at most once.
     */
    private function expire(MembershipTerm $term): bool
    {
        return DB::transaction(function () use ($term): bool {
            $locked = MembershipTerm::query()->whereKey($term->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== MembershipTerm::STATUS_ACTIVE) {
                return false;
            }

            $now = now();
            $locked->forceFill(['status' => MembershipTerm::STATUS_EXPIRED, 'expired_at' => $now])->save();

            DB::table('membership_status_history')->insert([
                'membership_id' => $locked->membership_id,
                'membership_term_id' => $locked->id,
                'event' => 'membership.term_expired',
                'from_status' => MembershipTerm::STATUS_ACTIVE,
                'to_status' => MembershipTerm::STATUS_EXPIRED,
                'actor_type' => 'system',
                'created_at' => $now,
            ]);

            return true;
        });
    }
}
