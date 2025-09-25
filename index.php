<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/server_status.php';

ensureSessionStarted();
$config = require __DIR__ . '/includes/config.php';

$serverHost = getenv('SWG_SERVER_HOST') ?: '127.0.0.1';
$loginPort = (int) (getenv('SWG_LOGIN_PORT') ?: 44453);
$gamePort = (int) (getenv('SWG_GAME_PORT') ?: 44463);
$mysqlPort = (int) (getenv('SWG_MYSQL_PORT') ?: 3306);
$timeoutSeconds = (int) (getenv('SWG_PORT_TIMEOUT') ?: 5);

$statuses = resolveServerStatuses($serverHost, $timeoutSeconds, [
    'login' => $loginPort,
    'game' => $gamePort,
    'database' => $mysqlPort,
]);

$loginOnline = $statuses['login'] ?? false;
$gameOnline = $statuses['game'] ?? false;
$mysqlOnline = $statuses['database'] ?? false;

$onlinePlayers = null;
try {
    $stmt = $mysqli->query('SELECT COUNT(*) AS online_count FROM characters WHERE online = 1');
    $row = $stmt->fetch_assoc();
    $onlinePlayers = isset($row['online_count']) ? (int) $row['online_count'] : null;
} catch (Throwable $exception) {
    $onlinePlayers = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> Command Center</title>
<link rel="stylesheet" type="text/css" href="stylesheet.css">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body {
        margin: 0;
        background-color: #000;
        background-image: url("/images/stormtrooper.jpg");
        background-repeat: no-repeat;
        background-attachment: fixed;
        background-position: center;
        background-size: cover;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: #e2e8f0;
    }

    .container {
        background: linear-gradient(180deg, rgba(2, 6, 23, 0.8) 0%, rgba(2, 6, 23, 0.6) 100%);
        min-height: 100vh;
    }

    header {
        padding: 2.5rem 1.5rem 2rem;
        text-align: center;
        color: #f8fafc;
    }

    header h1 {
        margin: 0;
        font-size: 2.6rem;
        letter-spacing: 0.12em;
    }

    header p {
        margin: 0.35rem 0 0;
        font-size: 1.05rem;
        color: #cbd5e1;
    }

    nav {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
    }

    .nav-greeting {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        border-radius: 999px;
        background: rgba(56, 189, 248, 0.12);
        color: #bae6fd;
        font-weight: 600;
        letter-spacing: 0.08em;
    }

    nav a {
        color: #38bdf8;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-decoration: none;
        padding: 0.75rem 1.5rem;
        border-radius: 999px;
        border: 1px solid rgba(56, 189, 248, 0.35);
        background: rgba(15, 23, 42, 0.6);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    nav a:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 35px rgba(56, 189, 248, 0.25);
    }

    main {
        max-width: 960px;
        margin: 0 auto;
        padding: 0 1.5rem 3rem;
    }

    .status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1.5rem;
        margin-top: 3rem;
    }

    .status-card {
        background: rgba(15, 23, 42, 0.85);
        border-radius: 16px;
        padding: 1.75rem;
        border: 1px solid rgba(148, 163, 184, 0.25);
        text-align: center;
        box-shadow: 0 25px 45px rgba(2, 6, 23, 0.45);
    }

    .status-card h2 {
        margin-top: 0;
        font-size: 1.2rem;
        letter-spacing: 0.08em;
        color: #f8fafc;
    }

    .status-indicator {
        font-size: 1.1rem;
        font-weight: 600;
        margin-top: 0.75rem;
    }

    .status-indicator.online {
        color: #4ade80;
    }

    .status-indicator.offline {
        color: #f87171;
    }

    .players-online {
        font-size: 1.5rem;
        font-weight: 700;
        margin-top: 0.5rem;
        color: #facc15;
    }

    .intel-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-top: 3rem;
    }

    .intel-card {
        background: rgba(15, 23, 42, 0.88);
        border-radius: 16px;
        padding: 1.75rem;
        border: 1px solid rgba(148, 163, 184, 0.25);
        box-shadow: 0 22px 40px rgba(2, 6, 23, 0.45);
    }

    .intel-card h3 {
        margin-top: 0;
        letter-spacing: 0.08em;
        color: #f8fafc;
    }

    .intel-card p {
        color: #cbd5e1;
        line-height: 1.6;
    }

    .intel-card ul {
        list-style: none;
        padding: 0;
        margin: 1rem 0 0;
        color: #e2e8f0;
    }

    .intel-card li {
        margin-bottom: 0.5rem;
        padding-left: 1.2rem;
        position: relative;
    }

    .intel-card li::before {
        content: '\2726';
        position: absolute;
        left: 0;
        color: #38bdf8;
    }

    footer {
        padding: 2rem 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }

    .audio-controls {
        margin-top: 2rem;
        text-align: center;
    }

    .audio-controls audio {
        width: 100%;
        max-width: 320px;
    }
</style>
</head>
<body>
<div class="container">
    <header>
        <h1>Welcome to <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Secure authentication gateway for our Star Wars Galaxies community.</p>
        <nav>
            <?php if (isAuthenticated()) : ?>
                <span class="nav-greeting">Greetings, <?php echo htmlspecialchars(currentDisplayName(), ENT_QUOTES, 'UTF-8'); ?></span>
                <a href="/dashboard.php">Dashboard</a>
                <a href="/profile.php">My Profile</a>
                <a href="/changepassword.php">Change Password</a>
                <a href="/logout.php">Logout</a>
            <?php else : ?>
                <a href="/form_login.php">Account Login</a>
                <a href="/addnewuser.php">Register</a>
            <?php endif; ?>
            <a href="/index.php">Home</a>
            <a href="/forums/index.php">Forums</a>
            <a href="http://www.swgsource.com" target="_blank" rel="noopener">SWG Source</a>
            <a href="https://discord.gg" target="_blank" rel="noopener">Discord Command</a>
            <a href="http://www.swgcraft.co.uk" target="_blank" rel="noopener">SWG Craft</a>
            <a href="http://www.galaxyharvester.net" target="_blank" rel="noopener">Galaxy Harvester</a>
        </nav>
    </header>

    <main>
        <div class="audio-controls">
            <audio controls preload="none">
                <source src="/music/Star Wars Main Theme.mp3" type="audio/mpeg">
            </audio>
        </div>

        <section class="status-grid">
            <div class="status-card">
                <h2>Login Server</h2>
                <div class="status-indicator <?php echo $loginOnline ? 'online' : 'offline'; ?>">
                    <?php echo $loginOnline ? 'Online' : 'Offline'; ?>
                </div>
            </div>
            <div class="status-card">
                <h2>Game Server</h2>
                <div class="status-indicator <?php echo $gameOnline ? 'online' : 'offline'; ?>">
                    <?php echo $gameOnline ? 'Online' : 'Offline'; ?>
                </div>
            </div>
            <div class="status-card">
                <h2>Database</h2>
                <div class="status-indicator <?php echo $mysqlOnline ? 'online' : 'offline'; ?>">
                    <?php echo $mysqlOnline ? 'Online' : 'Offline'; ?>
                </div>
            </div>
            <div class="status-card">
                <h2>Pilots in the Galaxy</h2>
                <?php if ($onlinePlayers !== null) : ?>
                    <div class="players-online"><?php echo $onlinePlayers; ?></div>
                <?php else : ?>
                    <div class="status-indicator offline">Unavailable</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="intel-grid">
            <div class="intel-card">
                <h3>Launch the SWG+ Dashboard</h3>
                <p>Chart your journey with personalized intel, activity logs, and quick links into every corner of the galaxy.</p>
                <ul>
                    <li>Live account insights and quick actions</li>
                    <li>Curated mission ideas to inspire your next session</li>
                    <li>Server diagnostics refreshed every visit</li>
                </ul>
            </div>
            <div class="intel-card">
                <h3>Showcase Your Legend</h3>
                <p>Craft a rich pilot profile with avatars, biographies, and social call signs so squad mates can recognize you instantly.</p>
                <ul>
                    <li>Highlight your favourite faction and playstyle</li>
                    <li>Share contact handles for squad coordination</li>
                    <li>Set a heroic display name across the site</li>
                </ul>
            </div>
            <div class="intel-card">
                <h3>Community Ready</h3>
                <p>Plug into the wider SWG+ network with one-click access to forums, crafting utilities, and galactic intel feeds.</p>
                <ul>
                    <li>Navigate seamlessly between every command hub</li>
                    <li>Stay informed with trusted external tools</li>
                    <li>Sync up with allies via our Discord command center</li>
                </ul>
            </div>
        </section>
    </main>

    <footer>
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?>. All systems monitored by Imperial Security Bureau.
    </footer>
</div>
</body>
</html>
