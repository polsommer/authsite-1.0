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
    :root {
        color-scheme: dark;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background-color: #020617;
        background-image: linear-gradient(140deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.8)),
            url("/images/vaderdeathstar.jpg");
        background-repeat: no-repeat;
        background-attachment: fixed;
        background-position: center;
        background-size: cover;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: #e2e8f0;
    }

    .container {
        min-height: 100vh;
        backdrop-filter: blur(4px);
        background: rgba(2, 6, 23, 0.6);
    }

    header {
        padding: 3rem 1.5rem 2.5rem;
        text-align: center;
        color: #f8fafc;
    }

    header h1 {
        margin: 0;
        font-size: clamp(2.4rem, 4vw, 3.2rem);
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    header p {
        margin: 0.75rem auto 0;
        font-size: 1.1rem;
        max-width: 720px;
        color: #cbd5e1;
        line-height: 1.6;
    }

    nav {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        align-items: center;
        gap: 0.75rem;
        margin-top: 2.5rem;
    }

    .nav-greeting {
        display: inline-flex;
        align-items: center;
        padding: 0.6rem 1.1rem;
        border-radius: 999px;
        background: rgba(94, 234, 212, 0.15);
        color: #99f6e4;
        font-weight: 600;
        letter-spacing: 0.06em;
    }

    .nav-links {
        display: inline-flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        justify-content: center;
    }

    nav a {
        color: #f8fafc;
        font-weight: 600;
        letter-spacing: 0.05em;
        text-decoration: none;
        padding: 0.7rem 1.35rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: rgba(15, 23, 42, 0.7);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    nav a:hover {
        transform: translateY(-1px);
        border-color: rgba(94, 234, 212, 0.6);
        box-shadow: 0 18px 35px rgba(45, 212, 191, 0.25);
    }

    main {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1.5rem 4rem;
    }

    .status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-top: 3rem;
    }

    .status-card {
        background: rgba(15, 23, 42, 0.88);
        border-radius: 18px;
        padding: 1.75rem;
        border: 1px solid rgba(148, 163, 184, 0.25);
        text-align: center;
        box-shadow: 0 25px 45px rgba(2, 6, 23, 0.45);
    }

    .status-card h2 {
        margin-top: 0;
        font-size: 1.1rem;
        letter-spacing: 0.08em;
        color: #f8fafc;
        text-transform: uppercase;
    }

    .status-indicator {
        font-size: 1.05rem;
        font-weight: 600;
        margin-top: 0.75rem;
    }

    .status-indicator.online {
        color: #34d399;
    }

    .status-indicator.offline {
        color: #f97316;
    }

    .players-online {
        font-size: 1.5rem;
        font-weight: 700;
        margin-top: 0.5rem;
        color: #facc15;
    }

    .hero-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-top: 3.5rem;
    }

    .hero-card {
        background: rgba(15, 23, 42, 0.92);
        border-radius: 20px;
        padding: 2rem;
        border: 1px solid rgba(56, 189, 248, 0.25);
        box-shadow: 0 24px 48px rgba(8, 47, 73, 0.35);
    }

    .hero-card h3 {
        margin: 0 0 0.75rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #e0f2fe;
    }

    .hero-card p {
        margin: 0 0 0.75rem;
        color: #cbd5e1;
        line-height: 1.6;
    }

    .hero-card ul {
        list-style: none;
        padding: 0;
        margin: 0;
        color: #e2e8f0;
    }

    .hero-card li {
        margin-bottom: 0.55rem;
        padding-left: 1.2rem;
        position: relative;
    }

    .hero-card li::before {
        content: '\2727';
        position: absolute;
        left: 0;
        color: #38bdf8;
    }

    .species-section {
        margin-top: 4rem;
    }

    .species-header {
        text-align: center;
    }

    .species-header h2 {
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.14em;
    }

    .species-header p {
        margin: 0 auto;
        max-width: 620px;
        color: #cbd5e1;
        line-height: 1.6;
    }

    .species-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1.25rem;
        margin-top: 2rem;
    }

    .species-card {
        background: rgba(15, 23, 42, 0.9);
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.25);
        box-shadow: 0 18px 32px rgba(2, 6, 23, 0.35);
        transition: transform 0.15s ease;
    }

    .species-card:hover {
        transform: translateY(-4px);
    }

    .species-card img {
        display: block;
        width: 100%;
        height: 200px;
        object-fit: cover;
        object-position: center top;
    }

    .species-card strong {
        display: block;
        padding: 0.85rem 1rem;
        text-align: center;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #e0f2fe;
    }

    footer {
        padding: 2rem 1rem 3rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }

    @media (max-width: 640px) {
        header {
            padding-top: 2.5rem;
        }

        .nav-links {
            width: 100%;
            justify-content: center;
        }

        .species-card img {
            height: 160px;
        }
    }
</style>
</head>
<body>
<div class="container">
    <header>
        <h1>Welcome to <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Dive into SWG+ — the ultimate Star Wars Galaxies experience with custom content, curated events, and a passionate community ready to explore the stars with you.</p>
        <nav>
            <?php if (isAuthenticated()) : ?>
                <span class="nav-greeting">Greetings, <?php echo htmlspecialchars(currentDisplayName(), ENT_QUOTES, 'UTF-8'); ?></span>
                <div class="nav-links">
                    <a href="/dashboard.php">Dashboard</a>
                    <a href="/profile.php">My Profile</a>
                    <a href="/logout.php">Logout</a>
                </div>
            <?php else : ?>
                <div class="nav-links">
                    <a href="/form_login.php">Account Login</a>
                    <a href="/addnewuser.php">Join SWG+</a>
                </div>
            <?php endif; ?>
        </nav>
    </header>

    <main>
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

        <section class="hero-grid">
            <div class="hero-card">
                <h3>Play Your Way</h3>
                <p>From daring pilots to cunning smugglers, SWG+ gives every role the spotlight. Build your legend with enhanced professions and story-driven quests.</p>
                <ul>
                    <li>Custom progression paths and seasonal events</li>
                    <li>Unique loot tables tuned for exploration</li>
                    <li>Epic space and ground encounters every week</li>
                </ul>
            </div>
            <div class="hero-card">
                <h3>Join a Living Community</h3>
                <p>Our veteran staff keep the galaxy evolving with fresh content drops, community spotlights, and nightly activity groups.</p>
                <ul>
                    <li>Friendly guild recruitment and mentorship</li>
                    <li>Server-wide events streamed via Discord</li>
                    <li>Player councils steering future updates</li>
                </ul>
            </div>
            <div class="hero-card">
                <h3>Command Center Access</h3>
                <p>Use the SWG+ dashboard to monitor server status, manage your account, and jump straight into the action with one click.</p>
                <ul>
                    <li>Real-time server intel and outage alerts</li>
                    <li>Profile tools to manage alts and allies</li>
                    <li>Direct launch links into the galaxy</li>
                </ul>
            </div>
        </section>

        <section class="species-section">
            <div class="species-header">
                <h2>Choose Your Species</h2>
                <p>Step into the boots, robes, or armor of iconic Star Wars races. From crafty Bothans to fearless Trandoshans, our server celebrates every origin story.</p>
            </div>
            <div class="species-grid">
                <?php
                $species = [
                    ['file' => 'r1.PNG', 'label' => 'Human'],
                    ['file' => 'r2.PNG', 'label' => 'Bothan'],
                    ['file' => 'r3.PNG', 'label' => 'Mon Calamari'],
                    ['file' => 'r4.PNG', 'label' => 'Rodian'],
                    ['file' => 'r5.PNG', 'label' => 'Trandoshan'],
                    ['file' => 'r6.PNG', 'label' => 'Twi\'lek'],
                    ['file' => 'r7.PNG', 'label' => 'Wookiee'],
                    ['file' => 'r8.PNG', 'label' => 'Zabrak'],
                    ['file' => 'r9.PNG', 'label' => 'Sullustan'],
                    ['file' => 'r10.PNG', 'label' => 'Ithorian'],
                    ['file' => 'r11.PNG', 'label' => 'Kel Dor'],
                    ['file' => 'r12.PNG', 'label' => 'Chiss'],
                ];
                foreach ($species as $data) :
                    $imagePath = '/images/species/' . $data['file'];
                    $label = $data['label'];
                ?>
                <div class="species-card">
                    <img src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
                    <strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <footer>
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?>. All systems monitored by Imperial Security Bureau.
    </footer>
</div>
</body>
</html>