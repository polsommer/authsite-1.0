<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();
$config = require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: addnewuser.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    $errors = ['Security token mismatch. Please refresh the page and try again.'];
    renderResponse($config, $errors);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'create') {
    http_response_code(400);
    $errors = ['Invalid registration request.'];
    renderResponse($config, $errors);
    exit;
}

purgeOldSecurityEvents($mysqli);
$ipAddress = currentIpAddress();
$maxAttempts = max(1, $config['registration']['max_attempts'] ?? 5);
$intervalSeconds = max(60, $config['registration']['interval_seconds'] ?? 3600);

if (hasExceededRateLimit($mysqli, 'register', $ipAddress, $maxAttempts, $intervalSeconds)) {
    $errors = ['Too many registration attempts detected. Please try again later or contact an administrator.'];
    renderResponse($config, $errors);
    exit;
}

$username = trim((string) ($_POST['useraccountname'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['realpassword'] ?? '');
$confirmPassword = (string) ($_POST['confirmpassword'] ?? '');
$acceptedTerms = ($_POST['terms'] ?? '') === 'yes';

$errors = [];

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $username)) {
    $errors[] = 'Account names must be 4-32 characters and can only include letters, numbers, underscores, or hyphens.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}

if (strlen($email) > 190) {
    $errors[] = 'Email addresses must be 190 characters or fewer.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Password confirmation does not match.';
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}$/', $password)) {
    $errors[] = 'Passwords must be at least 12 characters long and include upper- and lower-case letters, a number, and a symbol.';
}

if (!$acceptedTerms) {
    $errors[] = 'You must accept the community code of conduct before creating an account.';
}

if (findUserByUsername($mysqli, $username)) {
    $errors[] = 'That account name is already in use.';
}

if (findUserByEmail($mysqli, $email)) {
    $errors[] = 'An account with that email address already exists.';
}

if (!empty($errors)) {
    logSecurityEvent($mysqli, 'register', $ipAddress);
    renderResponse($config, $errors);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$verificationToken = bin2hex(random_bytes(32));
$accessLevel = 'standard';

$stmt = $mysqli->prepare('INSERT INTO user_account (accesslevel, username, email, password_hash, email_verification_token) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sssss', $accessLevel, $username, $email, $passwordHash, $verificationToken);
$stmt->execute();

logSecurityEvent($mysqli, 'register', $ipAddress);

$verificationLink = $config['base_url'] . '/verify_email.php?token=' . urlencode($verificationToken);
$subject = 'Verify your ' . $config['site_name'] . ' account';
$message = <<<MAIL
Greetings Commander {$username},

Welcome to {$config['site_name']}! Please confirm your email address to activate your account:
{$verificationLink}

If you did not request this account, ignore this email and the request will expire.

For the Empire,
{$config['site_name']} Security Team
MAIL;

$headers = 'From: ' . $config['mail_from'] . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
$mailSent = @mail($email, $subject, $message, $headers);

renderResponse($config, [], $mailSent);
exit;

function renderResponse(array $config, array $errors, bool $mailSent = false): void
{
    http_response_code(empty($errors) ? 200 : 422);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Registration Status | <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="stylesheet" href="stylesheet.css">
        <style>
            body {
                background: radial-gradient(circle at top, #0f172a, #020617 65%);
                color: #f8fafc;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
            }

            .status-card {
                background: rgba(15, 23, 42, 0.92);
                border-radius: 18px;
                max-width: 560px;
                width: 100%;
                padding: 2.5rem;
                box-shadow: 0 30px 60px rgba(2, 6, 23, 0.6);
                border: 1px solid rgba(148, 163, 184, 0.2);
            }

            h1 {
                margin-top: 0;
                font-size: 2rem;
                text-align: center;
                letter-spacing: 0.1em;
            }

            .status-card ul {
                list-style: none;
                padding: 0;
            }

            .status-card li {
                background: rgba(220, 38, 38, 0.18);
                border: 1px solid rgba(248, 113, 113, 0.4);
                border-radius: 10px;
                padding: 0.85rem 1rem;
                margin-bottom: 0.75rem;
            }

            .success {
                background: rgba(13, 148, 136, 0.18);
                border: 1px solid rgba(45, 212, 191, 0.45);
                color: #ccfbf1;
                border-radius: 12px;
                padding: 1rem 1.25rem;
                font-size: 1rem;
                line-height: 1.6;
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
                text-transform: uppercase;
                letter-spacing: 0.08em;
                font-weight: 600;
                background: linear-gradient(135deg, #3b82f6, #22d3ee);
                color: #0f172a;
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }

            a.button:hover {
                transform: translateY(-1px);
                box-shadow: 0 18px 35px rgba(59, 130, 246, 0.35);
            }
        </style>
    </head>
    <body>
        <div class="status-card">
            <?php if (!empty($errors)) : ?>
                <h1>Registration Needs Attention</h1>
                <p>Please review the following items:</p>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <h1>Registration Received</h1>
                <div class="success">
                    <p>Your account request has been recorded. <?php echo $mailSent
                        ? 'Check your inbox for a verification link to activate your access.'
                        : 'We were unable to send a verification email automatically. Please contact support with your username to complete activation.'; ?></p>
                </div>
            <?php endif; ?>
            <div class="actions">
                <a class="button" href="/addnewuser.php">Back to Registration</a>
                <a class="button" href="/form_login.php" style="margin-left: 1rem;">Go to Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
