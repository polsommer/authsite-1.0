<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();

if (!isset($_SESSION['user'])) {
    $_SESSION['urlredirect'] = basename($_SERVER['PHP_SELF']);
    header('Location: form_login.php');
    exit;
}

$csrfToken = getCsrfToken();
$config = require __DIR__ . '/includes/config.php';

$usernames = [];
$stmt = $mysqli->prepare('SELECT user_id, username FROM user_account ORDER BY username ASC');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $usernames[] = $row;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Update Password | <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        body {
            background-image: url("/images/grevious.jpg");
            background-color: #010409;
            background-size: cover;
            background-attachment: fixed;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #e2e8f0;
        }

        .password-card {
            max-width: 520px;
            margin: 4rem auto;
            padding: 2.5rem;
            background: rgba(2, 6, 23, 0.88);
            border-radius: 20px;
            box-shadow: 0 28px 52px rgba(0, 0, 0, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.25);
        }

        h1 {
            text-align: center;
            margin-bottom: 0.35rem;
            letter-spacing: 0.08em;
        }

        p.subtitle {
            text-align: center;
            color: #cbd5e1;
            margin-bottom: 2rem;
        }

        label {
            display: block;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            margin-bottom: 0.35rem;
            color: #94a3b8;
            text-transform: uppercase;
        }

        select,
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.85);
            color: #f8fafc;
            font-size: 1rem;
        }

        button[type="submit"] {
            width: 100%;
            padding: 0.9rem 1rem;
            border-radius: 999px;
            border: none;
            font-size: 1.05rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            background: linear-gradient(135deg, #f97316, #ef4444);
            color: #0f172a;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 32px rgba(249, 115, 22, 0.45);
        }

        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
        }

        .actions a {
            color: #38bdf8;
        }

        .actions a:hover {
            color: #34d399;
        }

        .requirements {
            font-size: 0.85rem;
            color: #cbd5e1;
            margin-top: -0.75rem;
            margin-bottom: 1.75rem;
        }
    </style>
</head>
<body>
    <div class="password-card">
        <h1>Reset Password</h1>
        <p class="subtitle">Keep your credentials secure with a strong passphrase.</p>
        <form action="post_changepassword.php" method="post" autocomplete="off">
            <label for="username">Account</label>
            <select name="username" id="username" required>
                <?php foreach ($usernames as $account) :
                    $selected = (strcasecmp($_SESSION['username'], $account['username']) === 0) ? 'selected' : '';
                    if ($selected || strcasecmp($_SESSION['accesslevel'] ?? '', 'superadmin') === 0) : ?>
                        <option value="<?php echo htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endif;
                endforeach; ?>
            </select>
            <label for="new_password">New Password</label>
            <input id="new_password" type="password" name="realpassword" required minlength="12" autocomplete="new-password">
            <div class="requirements">Minimum 12 characters with uppercase, lowercase, number and symbol.</div>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">Update Password</button>
        </form>
        <div class="actions">
            <a href="/index.php">Back to Control Center</a>
            <a href="/logout.php">Logout</a>
        </div>
    </div>
</body>
</html>
