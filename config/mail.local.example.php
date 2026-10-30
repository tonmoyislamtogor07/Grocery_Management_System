<?php
// Copy this file to config/mail.local.php and fill in your own Gmail details.
// config/mail.local.php is git-ignored — never commit real credentials.
//
// Gmail setup:
//   1. Google Account → Security → turn on 2-Step Verification
//   2. Security → App passwords → create one for "Mail"
//   3. Paste the 16-character password below (spaces are fine)
return [
    'host'      => 'smtp.gmail.com',
    'port'      => 587,
    'secure'    => 'tls',             // tls (587) | ssl (465) | none (local test server)
    'user'      => 'your.shop@gmail.com',
    'pass'      => 'your 16 char app password',
    'from_name' => 'Sufian Store GMS',
];
