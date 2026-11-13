<?php
// Copy this file to config.php and fill in the real values.
// config.php is listed in .gitignore and must never be committed.
// It has to sit next to run_update.php on the server.
return [
    // Long random string. run_update.php?key=... must match it.
    'secret_key'       => 'CHANGE-ME-to-a-long-random-string',
    // "Publish to web" CSV link of the Google Sheet that feeds the archive.
    'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/e/YOUR-SHEET-ID/pub?output=csv',
];
