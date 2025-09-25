<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('UTC');

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

$config = require __DIR__ . '/includes/config.php';

$ipAddress = currentIpAddress();
purgeOldSecurityEvents($mysqli);

$maxAttempts = max(1, $config['login']['max_attempts'] ?? 10);
$intervalSeconds = max(60, $config['login']['interval_seconds'] ?? 900);

if (hasExceededRateLimit($mysqli, 'api_auth', $ipAddress, $maxAttempts, $intervalSeconds)) {
    echo json_encode(['message' => 'Too many authentication attempts. Please slow down.']);
    exit;
}

$username = trim((string) ($_POST['user_name'] ?? ''));
$password = (string) ($_POST['user_password'] ?? '');
$suid = trim((string) ($_POST['stationID'] ?? ''));

if ($username === '' || $password === '') {
    logSecurityEvent($mysqli, 'api_auth', $ipAddress);
    echo json_encode(['message' => 'Username and password are required.']);
    exit;
}

$user = findUserByUsername($mysqli, $username);

if (!$user || !verifyPassword($password, $user['password_hash'])) {
    logSecurityEvent($mysqli, 'api_auth', $ipAddress);
    echo json_encode(['message' => 'Account does not exist or password was incorrect']);
    exit;
}

if (!requireVerifiedAccount($user)) {
    logSecurityEvent($mysqli, 'api_auth', $ipAddress);
    echo json_encode(['message' => 'Email verification pending']);
    exit;
}

if (strcasecmp($user['accesslevel'], 'banned') === 0) {
    logSecurityEvent($mysqli, 'api_auth', $ipAddress);
    echo json_encode(['message' => 'Your account has been banned. Contact CSR staff for more information.']);
    exit;
}

logSecurityEvent($mysqli, 'api_auth', $ipAddress);

echo json_encode(['message' => 'success']);

$authContent = sprintf('[%s] Username: %s, Station ID: %s, IP: %s%s',
    date('m/d/Y h:i:s a'),
    $username,
    $suid,
    $ipAddress,
    PHP_EOL
);

// Optional file logging can be re-enabled by configuring filesystem permissions.
// file_put_contents(__DIR__ . '/logs/auth_log.txt', $authContent, FILE_APPEND);

die();
