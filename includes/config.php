<?php
declare(strict_types=1);

return [
    'site_name' => 'SWG Source Server',
    'base_url' => rtrim(getenv('SWG_BASE_URL') ?: 'http://localhost', '/'),
    'mail_from' => getenv('SWG_MAIL_FROM') ?: 'no-reply@swgsource.com',
    'registration' => [
        'max_attempts' => (int) (getenv('SWG_REG_MAX_ATTEMPTS') ?: 5),
        'interval_seconds' => (int) (getenv('SWG_REG_INTERVAL_SECONDS') ?: 3600),
    ],
    'login' => [
        'max_attempts' => (int) (getenv('SWG_LOGIN_MAX_ATTEMPTS') ?: 10),
        'interval_seconds' => (int) (getenv('SWG_LOGIN_INTERVAL_SECONDS') ?: 900),
    ],
];
