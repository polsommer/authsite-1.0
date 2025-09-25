<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();
$config = require __DIR__ . '/includes/config.php';

$token = trim((string) ($_GET['token'] ?? ''));
$statusMessage = '';
$isSuccess = false;

if ($token === '') {
    $statusMessage = 'The verification link is invalid or missing.';
} else {
    $stmt = $mysqli->prepare('SELECT user_id, username, email_verified_at FROM user_account WHERE email_verification_token = ? LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        $statusMessage = 'Verification token not found or already used.';
    } elseif (!empty($user['email_verified_at'])) {
        $statusMessage = 'Your email has already been verified. You can log in now.';
        $isSuccess = true;
    } else {
        markEmailVerified($mysqli, (int) $user['user_id']);
        logSecurityEvent($mysqli, 'email_verified', currentIpAddress());
        $statusMessage = 'Success! Your email has been confirmed. You can now access your account.';
        $isSuccess = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Verification | <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        body {
            background: radial-gradient(circle at top, #0b1120, #020617 70%);
            color: #f8fafc;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 2rem;
        }

        .verification-card {
            max-width: 520px;
            width: 100%;
            padding: 2.5rem;
            background: rgba(15, 23, 42, 0.92);
            border-radius: 18px;
            box-shadow: 0 30px 55px rgba(2, 6, 23, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.25);
            text-align: center;
        }

        h1 {
            margin-top: 0;
            font-size: 2rem;
            letter-spacing: 0.1em;
        }

        .status-message {
            margin-top: 1.5rem;
            padding: 1.25rem 1.5rem;
            border-radius: 12px;
            font-size: 1.05rem;
            line-height: 1.6;
            background: <?php echo $isSuccess ? 'rgba(22, 163, 74, 0.18)' : 'rgba(220, 38, 38, 0.18)'; ?>;
            border: 1px solid <?php echo $isSuccess ? 'rgba(74, 222, 128, 0.45)' : 'rgba(248, 113, 113, 0.45)'; ?>;
            color: <?php echo $isSuccess ? '#bbf7d0' : '#fecaca'; ?>;
        }

        a.button {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.85rem 2.5rem;
            border-radius: 999px;
            text-decoration: none;
            background: linear-gradient(135deg, #22d3ee, #3b82f6);
            color: #0f172a;
            letter-spacing: 0.1em;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="verification-card">
        <h1>Email Verification</h1>
        <div class="status-message">
            <?php echo htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <a class="button" href="/form_login.php">Proceed to Login</a>
    </div>
</body>
</html>
