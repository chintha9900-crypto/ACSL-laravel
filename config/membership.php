<?php

/*
|--------------------------------------------------------------------------
| Membership workflow configuration
|--------------------------------------------------------------------------
|
| Domain configuration that is not per-record data (docs/architecture/03 §).
|
*/

return [

    /*
     | How long the signed application-status link stays valid, in days. The link
     | is the applicant's only credential (there is no account), so it expires.
     | ACI has not confirmed a lifetime (docs/frontend/08 C-05): this is a default.
     */
    'applicant_link_days' => 30,

    /*
     | The business timezone (docs/database/17 OD-09, decided: Asia/Colombo). Used ONLY to
     | derive calendar dates and the number's year at activation: `activated_on`, `starts_on`
     | and the `YY` of the membership number. Instants (`activated_at` and every other
     | `*_at`) stay UTC.
     |
     | Term length rule: `expires_on = starts_on + N calendar months - 1 calendar day`, with
     | Carbon's no-overflow month handling, so a start on 31 Aug with N = 6 gives 28 Feb
     | (not 3 Mar) and therefore expires_on = 27 Feb; 15 Oct gives 14 Apr.
     */
    'business_timezone' => 'Asia/Colombo',

    /*
     | Renewal reminders (M10) — days before a term's `expires_on` to send one.
     | Approved exactly as listed; the scheduled command matches on the exact
     | day, not "within N days", so each interval fires once per term.
     */
    'renewal_reminder_days' => [30, 14, 1],

    /*
     | Grace period (M11, OD-22 resolved for this rule) — how long after a term
     | expires the member may still renew it through the normal bank-transfer
     | flow before... [what happens after the grace period is not itself defined
     | by this rule and is not invented here]. Calendar months, same
     | no-overflow convention as term lengths.
     */
    'renewal_grace_period_months' => 1,

];
