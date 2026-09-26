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

];
