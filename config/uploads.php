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

];
