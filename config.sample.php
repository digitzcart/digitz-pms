<?php
// Digitz PMS — server configuration.
// Copy to config.php (install.php does this for you) and fill in real values.
// This file is blocked from web access by .htaccess. Never expose it to the frontend.

return [
    'app_name'   => 'Digitz PMS',
    'app_url'    => 'https://pms.example.ae',          // HTTPS only
    'timezone_display' => 'Asia/Dubai',

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=digitz_pms;charset=utf8mb4',
        'user' => 'digitz',
        'pass' => 'change-me',
    ],

    // 64+ random hex chars. install.php generates one.
    'jwt_secret'      => 'CHANGE_ME',
    'access_ttl'      => 900,          // 15 minutes
    'refresh_ttl'     => 2592000,      // 30 days

    // WhatsApp Business Cloud API (Meta). Use an approved AUTHENTICATION template
    // that takes the code as body parameter {{1}} and in a copy-code/URL button.
    'whatsapp' => [
        'graph_version'   => 'v21.0',
        'phone_number_id' => '',
        'access_token'    => '',
        'template_name'   => 'digitz_otp',
        'template_lang'   => 'en',
        'button_param'    => true,     // set false if your template has no button
    ],
    // DEV ONLY: when true, OTP codes are written to the PHP error log and returned
    // in the API response as "dev_code". Must be false in production.
    'otp_dev_mode' => false,

    // Web Push (VAPID). install.php generates the key pair and stores it in the settings table.
    'vapid_subject' => 'mailto:admin@example.ae',

    // Email reminders (cron/reminders.php) use PHP mail(); configure sendmail/SMTP relay on the host.
    'mail_from' => 'Digitz PMS <no-reply@example.ae>',

    'sync_max_bytes'  => 1048576,      // 1 MB per push batch
    'sync_max_records'=> 500,
    'allowed_origins' => [],           // extra CORS origins if the API is on another host
];
