<?php

/*
|--------------------------------------------------------------------------
| Upload limits
|--------------------------------------------------------------------------
|
| Per-upload-type limits (docs/architecture/07 §3). The concrete values for
| aviation proof are still to be confirmed by ACI; the size matches the
| reference application's 5 MB per file.
|
*/

return [

    'aviation_proof' => [
        // Maximum size of one file, in kilobytes.
        'max_kb' => 5120,

        // Maximum number of files per application. Together with `max_kb` this
        // is the per-application total-size ceiling.
        'max_files' => 5,

        // Accepted formats. Checked against the file content, not the filename.
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
    | Member profile avatar (docs/frontend/04 §3). Stored on the public disk.
    | The legacy reference claimed "max 2MB" in its UI but never enforced it
    | (docs/reverse-engineering/STORAGE.md); this makes that limit real.
    */
    'avatar' => [
        // Maximum size of the uploaded file, in kilobytes.
        'max_kb' => 2048,

        // Accepted formats. Checked against the file content, not the filename.
        'extensions' => ['jpg', 'jpeg', 'png'],
    ],

    /*
    | Renewal payment evidence (docs/architecture §0.9): "the same class of
    | validation/authorization/audit controls as aviation proof". Same limits.
    */
    'payment_evidence' => [
        'max_kb' => 5120,
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

];
