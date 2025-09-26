<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';

ensureSessionStarted();
$csrfToken = getCsrfToken();
$config = require __DIR__ . '/includes/config.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Secure Login</title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        body {
            background-image: url("/images/mfalcon.jpg");
            background-color: #010409;
            background-size: cover;
            background-attachment: fixed;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #f8fafc;
        }

        .login-container {
            max-width: 420px;
            margin: 5rem auto;
            padding: 2.75rem 2.5rem;
            background: rgba(2, 6, 23, 0.88);
            border-radius: 18px;
            box-shadow: 0 28px 50px rgba(0, 0, 0, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.2);
            text-align: center;
        }

        .login-container img {
            width: 180px;
            margin-bottom: 1.5rem;
        }

        .login-container h1 {
            font-size: 1.9rem;
            letter-spacing: 0.1em;
            margin-bottom: 0.25rem;
        }

        .login-container p {
            margin-top: 0;
            color: #cbd5e1;
            font-size: 0.95rem;
        }

        label {
            display: block;
            text-align: left;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            margin-bottom: 0.35rem;
            color: #94a3b8;
            text-transform: uppercase;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
            outline: none;
        }

        button[type="submit"] {
            width: 100%;
            padding: 0.9rem 1rem;
            border-radius: 999px;
            border: none;
            font-size: 1.05rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            background: linear-gradient(135deg, #2563eb, #06b6d4);
            color: #0f172a;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 30px rgba(37, 99, 235, 0.45);
        }

        .aux-links {
            margin-top: 1.75rem;
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .aux-links a {
            color: #38bdf8;
        }

        .aux-links a:hover {
            color: #34d399;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <img src="/images/swgsource.png" alt="SWG Source Logo" width="180" height="180">
        <h1>Welcome Back</h1>
        <p>Authenticate to access command consoles, mission updates, and account tools.</p>
        <form method="post" action="post_login.php" autocomplete="on">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required autocomplete="username" maxlength="32">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <button type="submit">Engage Secure Login</button>
            </div>
        </form>
        <div class="aux-links">
            <span><a href="/addnewuser.php">Need an account?</a></span>
            <span><a href="/index.php">Return to main hub</a></span>
        </div>
    </div>
</body>
</html>
