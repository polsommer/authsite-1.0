<?php
declare(strict_types=1);

function env_str(string $k, ?string $d=null){ $v=getenv($k); return ($v===false||$v==='')?$d:$v; }
function env_int(string $k, int $d){ $v=getenv($k); return is_numeric($v)?(int)$v:$d; }
function norm_enc(?string $v): string { $v=strtolower(trim((string)$v)); return in_array($v,['tls','ssl','none'],true)?$v:'tls'; }

return [
    'site_name' => 'SWG Plus',
    'base_url'  => rtrim(env_str('SWG_BASE_URL', 'https://www.swgplus.com'), '/'),

    // Use a REAL mailbox you created in Simply’s Mail admin for both From and SMTP auth
    'mail_from'      => env_str('SWG_MAIL_FROM', 'noreply@swgplus.com'),
    'mail_from_name' => env_str('SWG_MAIL_FROM_NAME', 'SWG Plus Security Team'),

    'smtp' => [
        // On Simply hosting use websmtp.simply.com; elsewhere use smtp.simply.com
        'host'        => env_str('SWG_SMTP_HOST', 'websmtp.simply.com'),
        'port'        => env_int('SWG_SMTP_PORT', 587),      // 587 = STARTTLS
        'username'    => env_str('SWG_SMTP_USERNAME', 'noreply@swgplus.com'), // full email
        'password'    => env_str('SWG_SMTP_PASSWORD', ''),   // mailbox password
        'encryption'  => norm_enc(env_str('SWG_SMTP_ENCRYPTION', 'tls')), // STARTTLS
        'client_name' => env_str('SWG_SMTP_CLIENT_NAME', 'swgplus.com'),  // your domain is fine
        'timeout'     => env_int('SWG_SMTP_TIMEOUT', 30),
    ],

    'registration' => [
        'max_attempts'     => env_int('SWG_REG_MAX_ATTEMPTS', 5),
        'interval_seconds' => env_int('SWG_REG_INTERVAL_SECONDS', 3600),
    ],
    'login' => [
        'max_attempts'     => env_int('SWG_LOGIN_MAX_ATTEMPTS', 10),
        'interval_seconds' => env_int('SWG_LOGIN_INTERVAL_SECONDS', 900),
    ],
];

