<?php
declare(strict_types=1);

return [
    'site_name' => 'SWG Source Server',
    'base_url' => rtrim(getenv('SWG_BASE_URL') ?: 'http://localhost', '/'),
    'mail_from' => getenv('SWG_MAIL_FROM') ?: 'no-reply@swgsource.com',
    'mail_from_name' => getenv('SWG_MAIL_FROM_NAME') ?: 'SWG Source Security Team',
    'smtp' => [
        'host' => getenv('SWG_SMTP_HOST') ?: 'websmtp.simply.com',
        'port' => (int) (getenv('SWG_SMTP_PORT') ?: 587),
        'username' => getenv('SWG_SMTP_USERNAME') ?: (getenv('SWG_MAIL_FROM') ?: ''),
        'password' => getenv('SWG_SMTP_PASSWORD') ?: '',
        'encryption' => strtolower(getenv('SWG_SMTP_ENCRYPTION') ?: 'starttls'),
        'client_name' => getenv('SWG_SMTP_CLIENT_NAME') ?: ($_SERVER['SERVER_NAME'] ?? 'localhost'),
        'timeout' => (int) (getenv('SWG_SMTP_TIMEOUT') ?: 30),
    ],
    'registration' => [
        'max_attempts' => (int) (getenv('SWG_REG_MAX_ATTEMPTS') ?: 5),
        'interval_seconds' => (int) (getenv('SWG_REG_INTERVAL_SECONDS') ?: 3600),
    ],
    'login' => [
        'max_attempts' => (int) (getenv('SWG_LOGIN_MAX_ATTEMPTS') ?: 10),
        'interval_seconds' => (int) (getenv('SWG_LOGIN_INTERVAL_SECONDS') ?: 900),
    ],
];
