<?php

namespace App\Actions\Membership;

use App\Exceptions\RenewalCannotBeStartedException;
use App\Models\Membership;
use App\Models\MembershipTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class RenewSchoolClubMembership
{
    /**
     * Renew a School Club membership (the school or one of its students) free
     * of charge: an immediately active `free_renewal` term — no plan, payment
     * or admin confirmation. The membership row and its number never change.
     *
     * Allowed once the latest term is within the largest renewal-reminder
     * window of its end (or has ended), so terms cannot be stacked far ahead.
     * The new term is contiguous with a still-current term, otherwise it
     * starts today — the same rule as a confirmed paid renewal.
     *
     * @throws RenewalCannotBeStartedException
     */
    public function handle(Membership $membership): MembershipTerm
    {
        return DB::transaction(function () use ($membership): MembershipTerm {
            $locked = Membership::query()->whereKey($membership->id)->with('category')->lockForUpdate()->first();

            if ($locked === null || ! $locked->isSchoolClub()) {
                throw new RenewalCannotBeStartedException('Only a School Club membership can be renewed free of charge.');
            }

            $latest = MembershipTerm::query()->where('membership_id', $locked->id)->orderByDesc('term_no')->first();
            $timezone = (string) config('membership.business_timezone');
            $today = Carbon::now($timezone)->startOfDay();
            $windowDays = (int) max(config('membership.renewal_reminder_days'));

            if ($latest !== null && $latest->expires_on !== null) {
                $opensOn = Carbon::parse($latest->expires_on->toDateString(), $timezone)->subDays($windowDays);

                if ($today->lt($opensOn)) {
                    throw new RenewalCannotBeStartedException('Your School Club membership can be renewed from '.$opensOn->format('j F Y').'.');
                }
            }

            $latestEnd = $latest?->expires_on !== null ? Carbon::parse($latest->expires_on->toDateString(), $timezone) : null;
            $startsOn = $latestEnd !== null && $latestEnd->gte($today) ? $latestEnd->copy()->addDay() : $today;
            $months = (int) config('membership.school_club_term_months');
            $now = now();

            $term = MembershipTerm::forceCreate([
                'membership_id' => $locked->id,
                'term_no' => (int) ($latest?->term_no ?? 0) + 1,
                'term_kind' => MembershipTerm::KIND_FREE_RENEWAL,
                'status' => MembershipTerm::STATUS_ACTIVE,
                'payment_status' => MembershipTerm::PAYMENT_NOT_REQUIRED,
                'duration_months' => $months,
                'starts_on' => $startsOn->toDateString(),
                'expires_on' => $startsOn->copy()->addMonthsNoOverflow($months)->subDay()->toDateString(),
                'activated_at' => $now,
            ]);

            DB::table('membership_status_history')->insert([
                'membership_id' => $locked->id,
                'membership_term_id' => $term->id,
                'event' => 'membership.free_renewal',
                'to_status' => MembershipTerm::STATUS_ACTIVE,
                'actor_type' => 'member',
                'actor_user_id' => $locked->user_id,
                'note' => $term->starts_on->toDateString().' to '.$term->expires_on->toDateString(),
                'created_at' => $now,
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $locked->user_id,
                'actor_type' => 'user',
                'event' => 'membership.free_renewal',
                'subject_type' => 'membership',
                'subject_id' => $locked->id,
                'new_values' => json_encode(['term_no' => $term->term_no, 'expires_on' => $term->expires_on->toDateString()]),
                'ip_address' => Request::ip(),
                'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                'created_at' => $now,
            ]);

            return $term;
        });
    }
}
