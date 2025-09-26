<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();
requireAuthenticatedUser('/changepassword.php');
$config = require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: changepassword.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    renderPasswordResponse($config, ['Security validation failed. Please try again.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$newPassword = (string) ($_POST['realpassword'] ?? '');
$errors = [];

if ($username === '') {
    $errors[] = 'A valid account must be selected.';
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}$/', $newPassword)) {
    $errors[] = 'Passwords must be at least 12 characters and include upper- and lower-case letters, a number, and a symbol.';
}

$user = $username !== '' ? findUserByUsername($mysqli, $username) : null;

if (!$user) {
    $errors[] = 'The selected account could not be found.';
}

$isSuperAdmin = strcasecmp($_SESSION['accesslevel'] ?? '', 'superadmin') === 0;

if ($user && !$isSuperAdmin && strcasecmp($_SESSION['username'] ?? '', $user['username']) !== 0) {
    $errors[] = 'You can only change your own password.';
}

if (!empty($errors)) {
    renderPasswordResponse($config, $errors);
    exit;
}

$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare('UPDATE user_account SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE username = ?');
$stmt->bind_param('ss', $passwordHash, $user['username']);
$stmt->execute();

logSecurityEvent($mysqli, 'password_reset', currentIpAddress());

renderPasswordResponse($config, []);
exit;

function renderPasswordResponse(array $config, array $errors): void
{
    http_response_code(empty($errors) ? 200 : 422);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Password Update</title>
        <link rel="stylesheet" href="stylesheet.css">
        <style>
            body {
                background: linear-gradient(145deg, #0f172a, #111827 60%, #020617);
                color: #f8fafc;
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding: 2rem;
            }

            .status-card {
                max-width: 520px;
                width: 100%;
                padding: 2.5rem;
                background: rgba(15, 23, 42, 0.92);
                border-radius: 18px;
                box-shadow: 0 30px 55px rgba(2, 6, 23, 0.65);
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

            .success {
                background: rgba(13, 148, 136, 0.18);
                border: 1px solid rgba(45, 212, 191, 0.45);
                color: #ccfbf1;
                border-radius: 12px;
                padding: 1rem 1.25rem;
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
                background: linear-gradient(135deg, #22d3ee, #0ea5e9);
                color: #0f172a;
                letter-spacing: 0.1em;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="status-card">
            <?php if (!empty($errors)) : ?>
                <h1>Password Update Failed</h1>
                <p>Please address the following items:</p>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <h1>Password Updated</h1>
                <div class="success">
                    <p>Your password has been refreshed. Use your new credentials on your next login.</p>
                </div>
            <?php endif; ?>
            <div class="actions">
                <a class="button" href="/index.php">Return to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
