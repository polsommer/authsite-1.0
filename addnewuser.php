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
    <title>Create a New Account | <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        body {
            background-image: url("/images/vaderdeathstar.jpg");
            background-color: black;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: center;
            background-size: cover;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            color: #f8f9fa;
        }

        .register-wrapper {
            max-width: 540px;
            margin: 4rem auto;
            background: rgba(12, 12, 16, 0.85);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(6px);
        }

        .register-wrapper h1 {
            font-size: 2rem;
            margin-bottom: 0.25rem;
            text-align: center;
            letter-spacing: 0.08em;
        }

        .register-wrapper p.subtitle {
            text-align: center;
            margin-bottom: 2rem;
            color: #e0e6ed;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.35rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: #9fb3c8;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid rgba(159, 179, 200, 0.25);
            background: rgba(10, 12, 18, 0.9);
            color: #ffffff;
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: #5bc0de;
            box-shadow: 0 0 0 3px rgba(91, 192, 222, 0.25);
            outline: none;
        }

        .form-actions {
            text-align: center;
            margin-top: 2rem;
        }

        button[type="submit"] {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #ffffff;
            border: none;
            padding: 0.85rem 2.5rem;
            font-size: 1.05rem;
            border-radius: 999px;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 15px 30px rgba(32, 201, 151, 0.35);
        }

        .requirements {
            font-size: 0.85rem;
            line-height: 1.5;
            color: #cbd5e1;
            margin-top: 0.5rem;
        }

        .terms {
            font-size: 0.85rem;
            color: #a0aec0;
        }

        a {
            color: #5bc0de;
        }

        a:hover {
            color: #63e6be;
            text-decoration: underline;
        }

        .nav-link {
            text-align: center;
            margin-top: 1rem;
        }

        .nav-link a {
            font-weight: 600;
            letter-spacing: 0.08em;
        }
    </style>
</head>
<body>
    <div class="register-wrapper" role="form">
        <h1>Join the Fight</h1>
        <p class="subtitle">Secure your SWG Source account and prepare for battle.</p>
        <form name="NewUser" action="newuserpost.php" method="post" novalidate>
            <div class="form-group">
                <label for="useraccountname">Account Name</label>
                <input type="text" id="useraccountname" name="useraccountname" required pattern="^[A-Za-z0-9_\-]{4,32}$" maxlength="32" autocomplete="username">
                <div class="requirements">Use 4-32 characters. Letters, numbers, hyphen and underscore only.</div>
            </div>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required maxlength="190" autocomplete="email">
                <div class="requirements">We will send a verification link to confirm your identity.</div>
            </div>
            <div class="form-group">
                <label for="realpassword">Password</label>
                <input type="password" id="realpassword" name="realpassword" required autocomplete="new-password" minlength="5">
                <div class="requirements">Minimum 5 characters including uppercase, lowercase, number and symbol.</div>
            </div>
            <div class="form-group">
                <label for="confirmpassword">Confirm Password</label>
                <input type="password" id="confirmpassword" name="confirmpassword" required autocomplete="new-password">
            </div>
            <div class="form-group terms">
                <label>
                    <input type="checkbox" name="terms" value="yes" required> I agree to follow the community code of conduct and accept the privacy policy.
                </label>
            </div>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-actions">
                <button type="submit">Create My Account</button>
            </div>
        </form>
        <div class="nav-link">
            <a href="/form_login.php">Already have an account? Sign in.</a>
        </div>
        <div class="nav-link">
            <a href="/index.php">Return to Command Center</a>
        </div>
    </div>
</body>
</html>
