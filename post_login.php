<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();
$config = require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: form_login.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    renderLoginResponse(['Security validation failed. Please try again.']);
    exit;
}

purgeOldSecurityEvents($mysqli);
$ipAddress = currentIpAddress();
$maxAttempts = max(1, $config['login']['max_attempts'] ?? 10);
$intervalSeconds = max(60, $config['login']['interval_seconds'] ?? 900);

if (hasExceededRateLimit($mysqli, 'login', $ipAddress, $maxAttempts, $intervalSeconds)) {
    renderLoginResponse(['Too many login attempts detected. Please wait before trying again.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    logSecurityEvent($mysqli, 'login', $ipAddress);
    renderLoginResponse(['Both username and password are required.']);
    exit;
}

$user = findUserByUsername($mysqli, $username);

if (!$user || !verifyPassword($password, $user['password_hash'])) {
    logSecurityEvent($mysqli, 'login', $ipAddress);
    renderLoginResponse(['Account does not exist or the password was incorrect.']);
    exit;
}

if (!requireVerifiedAccount($user)) {
    logSecurityEvent($mysqli, 'login', $ipAddress);
    renderLoginResponse(['Email verification pending. Please use the verification link sent to your inbox before logging in.']);
    exit;
}

if (strcasecmp($user['accesslevel'], 'banned') === 0) {
    logSecurityEvent($mysqli, 'login', $ipAddress);
    renderLoginResponse(['Your account has been banned. Contact a CSR team member for assistance.']);
    exit;
}

logSecurityEvent($mysqli, 'login', $ipAddress);
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['user_id'];
$_SESSION['user'] = $user['username'];
$_SESSION['username'] = $user['username'];
$_SESSION['accesslevel'] = $user['accesslevel'];

if (isset($_SESSION['urlredirect']) && $_SESSION['urlredirect'] !== '') {
    $redirectName = $_SESSION['urlredirect'];
    unset($_SESSION['urlredirect']);
    header('Location: ' . $redirectName);
    exit;
}

header('Location: index.php');
exit;

function renderLoginResponse(array $errors): void
{
    http_response_code(401);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login Error</title>
        <link rel="stylesheet" href="stylesheet.css">
        <style>
            body {
                background: linear-gradient(160deg, #0f172a 0%, #111827 45%, #020617 100%);
                color: #f8fafc;
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            .error-panel {
                max-width: 480px;
                width: 100%;
                padding: 2.5rem;
                background: rgba(15, 23, 42, 0.92);
                border-radius: 18px;
                box-shadow: 0 35px 60px rgba(2, 6, 23, 0.65);
                border: 1px solid rgba(148, 163, 184, 0.2);
            }

            h1 {
                margin-top: 0;
                font-size: 1.9rem;
                text-align: center;
                letter-spacing: 0.1em;
            }

            ul {
                list-style: none;
                padding: 0;
            }

            li {
                background: rgba(220, 38, 38, 0.16);
                border: 1px solid rgba(248, 113, 113, 0.45);
                padding: 0.85rem 1rem;
                border-radius: 12px;
                margin-bottom: 0.75rem;
            }

            .actions {
                text-align: center;
                margin-top: 2rem;
            }

            a.button {
                display: inline-block;
                padding: 0.75rem 2.5rem;
                border-radius: 999px;
                text-decoration: none;
                background: linear-gradient(135deg, #2563eb, #06b6d4);
                color: #0f172a;
                letter-spacing: 0.1em;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="error-panel">
            <h1>Access Denied</h1>
            <p>The login request could not be completed:</p>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="actions">
                <a class="button" href="/form_login.php">Return to Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
