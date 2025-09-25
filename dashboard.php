<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/friend_functions.php';
require_once __DIR__ . '/includes/server_status.php';

ensureSessionStarted();
requireAuthenticatedUser('/dashboard.php');

$userId = currentUserId();
$user = $userId !== null ? findUserById($mysqli, $userId) : null;

if (!$user) {
    $_SESSION = [];
    header('Location: /logout.php');
    exit;
}

$config = require __DIR__ . '/includes/config.php';

$serverHost = getenv('SWG_SERVER_HOST') ?: '127.0.0.1';
$loginPort = (int) (getenv('SWG_LOGIN_PORT') ?: 44453);
$gamePort = (int) (getenv('SWG_GAME_PORT') ?: 44463);
$mysqlPort = (int) (getenv('SWG_MYSQL_PORT') ?: 3306);
$timeoutSeconds = (int) (getenv('SWG_PORT_TIMEOUT') ?: 5);

$statuses = resolveServerStatuses($serverHost, $timeoutSeconds, [
    'Login Server' => $loginPort,
    'Game Server' => $gamePort,
    'Database' => $mysqlPort,
]);

$onlinePlayers = null;
$totalCharacters = null;

try {
    $stmt = $mysqli->query('SELECT COUNT(*) AS online_count FROM characters WHERE online = 1');
    $row = $stmt->fetch_assoc();
    $onlinePlayers = isset($row['online_count']) ? (int) $row['online_count'] : null;
} catch (Throwable $exception) {
    $onlinePlayers = null;
}

try {
    $totalResult = $mysqli->query('SELECT COUNT(*) AS total_count FROM characters');
    $totalRow = $totalResult->fetch_assoc();
    $totalCharacters = isset($totalRow['total_count']) ? (int) $totalRow['total_count'] : null;
} catch (Throwable $exception) {
    $totalCharacters = null;
}

$displayName = currentDisplayName();
$createdAt = $user['created_at'] ?? null;
$emailVerifiedAt = $user['email_verified_at'] ?? null;
$lastLoginAt = $user['last_login_at'] ?? null;
$faction = $user['faction'] ?: 'Independent';
$favoriteActivity = $user['favorite_activity'] ?: 'Explorer';
$discordHandle = $user['discord_handle'] ?: 'Not provided';
$timezone = $user['timezone'] ?: 'UTC';
$biography = trim((string) ($user['biography'] ?? ''));
$avatarUrl = $user['avatar_url'] ?: '/images/swgsource.png';

$friendFeedback = null;
$pendingFriendUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $friendFeedback = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'send_friend_request') {
            $pendingFriendUsername = trim((string) ($_POST['friend_username'] ?? ''));
            $friendFeedback = sendFriendRequest($mysqli, (int) $userId, $pendingFriendUsername);
            if (($friendFeedback['type'] ?? '') === 'success') {
                $pendingFriendUsername = '';
            }
        } elseif ($action === 'accept_friend_request') {
            $friendshipId = (int) ($_POST['friendship_id'] ?? 0);
            $friendFeedback = respondToFriendRequest($mysqli, $friendshipId, (int) $userId, 'accept');
        } elseif ($action === 'decline_friend_request') {
            $friendshipId = (int) ($_POST['friendship_id'] ?? 0);
            $friendFeedback = respondToFriendRequest($mysqli, $friendshipId, (int) $userId, 'decline');
        }
    }
}

$acceptedFriends = getAcceptedFriends($mysqli, (int) $userId);
$incomingFriendRequests = getIncomingFriendRequests($mysqli, (int) $userId);
$outgoingFriendRequests = getOutgoingFriendRequests($mysqli, (int) $userId);

$localTime = null;
try {
    $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $localTime = $utcNow->setTimezone(new DateTimeZone($timezone))->format('M j, Y \a\t g:i A');
} catch (Throwable $exception) {
    $localTime = null;
}

$profileFieldsTotal = 5;
$profileFieldsCompleted = 0;
if (trim((string) ($user['display_name'] ?? '')) !== '') {
    $profileFieldsCompleted++;
}
if (($user['faction'] ?? '') !== null && trim((string) $user['faction']) !== '') {
    $profileFieldsCompleted++;
}
if (($user['favorite_activity'] ?? '') !== null && trim((string) $user['favorite_activity']) !== '') {
    $profileFieldsCompleted++;
}
if (($user['discord_handle'] ?? '') !== null && trim((string) $user['discord_handle']) !== '') {
    $profileFieldsCompleted++;
}
if ($biography !== '') {
    $profileFieldsCompleted++;
}
$profileCompletion = (int) round(($profileFieldsCompleted / $profileFieldsTotal) * 100);
$profileCompletion = max(10, min(100, $profileCompletion));

$missions = [
    'Escort a convoy from Anchorhead to Mos Eisley.',
    'Assist a guild mate with a heroic instance run.',
    'Harvest rare resources on the forest moon of Endor.',
    'Host a cantina night for entertainers and crafters.',
];
shuffle($missions);
$highlightedMissions = array_slice($missions, 0, 3);

$activityLog = [];
if ($lastLoginAt) {
    $activityLog[] = 'Last login recorded on ' . (new DateTimeImmutable($lastLoginAt . ' UTC'))->format('M j, Y \a\t g:i A \U\T\C');
}
if ($emailVerifiedAt) {
    $activityLog[] = 'Email verified on ' . (new DateTimeImmutable($emailVerifiedAt . ' UTC'))->format('M j, Y');
}
$activityLog[] = 'Access level: ' . ucfirst(strtolower((string) ($user['accesslevel'] ?? 'standard')));

$csrfToken = getCsrfToken();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Command Dashboard</title>
    <link rel="stylesheet" href="/stylesheet.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, rgba(15, 23, 42, 0.95) 0%, rgba(2, 6, 23, 0.95) 45%, rgba(0, 0, 0, 0.98) 100%), url('/images/stormtrooper.jpg') no-repeat center/cover fixed;
            color: #e2e8f0;
        }

        .dashboard-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 1.5rem 4rem;
        }

        header {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            align-items: center;
            text-align: center;
        }

        header h1 {
            margin: 0;
            font-size: 2.5rem;
            letter-spacing: 0.12em;
        }

        header p {
            margin: 0;
            color: #cbd5e1;
        }

        .layout-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.75rem;
            margin-top: 2.5rem;
        }

        .card {
            background: rgba(15, 23, 42, 0.9);
            border-radius: 18px;
            padding: 1.75rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            box-shadow: 0 28px 48px rgba(2, 6, 23, 0.55);
        }

        .profile-card {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 1.25rem;
            align-items: center;
        }

        .profile-card img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(56, 189, 248, 0.4);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.5);
        }

        .profile-card h2 {
            margin: 0;
            letter-spacing: 0.08em;
        }

        .profile-meta {
            margin: 0.5rem 0 0;
            color: #94a3b8;
            line-height: 1.6;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: rgba(74, 222, 128, 0.15);
            color: #bbf7d0;
        }

        .progress-track {
            height: 10px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.25);
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #38bdf8, #34d399);
        }

        .server-status-list {
            list-style: none;
            padding: 0;
            margin: 1.25rem 0 0;
        }

        .server-status-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .server-status-list li:last-child {
            border-bottom: none;
        }

        .status-online {
            color: #4ade80;
            font-weight: 600;
        }

        .status-offline {
            color: #f87171;
            font-weight: 600;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .quick-links a {
            display: inline-block;
            padding: 0.85rem 1.15rem;
            border-radius: 12px;
            text-decoration: none;
            text-align: center;
            background: rgba(37, 99, 235, 0.18);
            color: #bfdbfe;
            font-weight: 600;
            letter-spacing: 0.05em;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .quick-links a:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 28px rgba(37, 99, 235, 0.35);
        }

        .alert {
            margin: 1.5rem auto 0;
            max-width: 820px;
            padding: 1rem 1.25rem;
            border-radius: 14px;
            border: 1px solid transparent;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-align: center;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            border-color: rgba(34, 197, 94, 0.4);
            color: #bbf7d0;
        }

        .alert-error {
            background: rgba(248, 113, 113, 0.12);
            border-color: rgba(248, 113, 113, 0.35);
            color: #fecaca;
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(59, 130, 246, 0.3);
            color: #bfdbfe;
        }

        .card h3 {
            margin-top: 0;
            letter-spacing: 0.1em;
        }

        .card h4 {
            margin: 1rem 0 0.5rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .card ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .card ul li {
            padding: 0.65rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .card ul li:last-child {
            border-bottom: none;
        }

        .biography {
            margin-top: 1rem;
            line-height: 1.6;
            color: #cbd5e1;
        }

        .friend-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .friend-form input[type="text"] {
            flex: 1 1 220px;
            padding: 0.65rem 0.85rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
        }

        .friend-form button {
            padding: 0.65rem 1.4rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            letter-spacing: 0.06em;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0f172a;
            cursor: pointer;
        }

        .friend-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-top: 1.5rem;
        }

        .friend-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
        }

        .friend-name {
            font-weight: 600;
            color: #e2e8f0;
        }

        .friend-handle {
            display: block;
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .friend-actions {
            display: flex;
            gap: 0.5rem;
        }

        .friend-actions form {
            margin: 0;
        }

        .friend-actions button {
            padding: 0.45rem 0.9rem;
            border-radius: 10px;
            border: none;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            cursor: pointer;
        }

        .friend-actions .approve {
            background: rgba(34, 197, 94, 0.2);
            color: #bbf7d0;
        }

        .friend-actions .decline {
            background: rgba(248, 113, 113, 0.2);
            color: #fecaca;
        }

        .friend-empty {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .cta-links {
            display: grid;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .cta-links a {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0.9rem 1.25rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: #0f172a;
            background: linear-gradient(135deg, #38bdf8, #22d3ee);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .cta-links a.secondary {
            background: linear-gradient(135deg, #f97316, #fb7185);
        }

        .cta-links a:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(14, 165, 233, 0.35);
        }

        footer {
            margin-top: 3rem;
            text-align: center;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        @media (max-width: 640px) {
            .profile-card {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .profile-card img {
                margin: 0 auto;
            }

            .quick-links {
                grid-template-columns: 1fr;
            }

            .friend-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-shell">
        <header>
            <h1>Welcome back, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Your personal SWG+ operations center is standing by.</p>
        </header>

        <?php if ($friendFeedback) : ?>
            <?php
            $feedbackClass = 'alert-info';
            if (($friendFeedback['type'] ?? '') === 'success') {
                $feedbackClass = 'alert-success';
            } elseif (($friendFeedback['type'] ?? '') === 'error') {
                $feedbackClass = 'alert-error';
            }
            ?>
            <div class="alert <?php echo $feedbackClass; ?>">
                <?php echo htmlspecialchars($friendFeedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="layout-grid">
            <section class="card profile-card" aria-label="Commander profile">
                <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
                <div>
                    <h2><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="profile-meta">
                        <div><strong>Faction:</strong> <?php echo htmlspecialchars($faction, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Role:</strong> <?php echo htmlspecialchars($favoriteActivity, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Discord:</strong> <?php echo htmlspecialchars($discordHandle, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($localTime) : ?>
                            <div><strong>Local Time:</strong> <?php echo htmlspecialchars($localTime, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="status-pill">Profile <?php echo $profileCompletion; ?>% complete</div>
                    <div class="progress-track" role="progressbar" aria-valuenow="<?php echo $profileCompletion; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-fill" style="width: <?php echo $profileCompletion; ?>%"></div>
                    </div>
                    <?php if ($biography !== '') : ?>
                        <p class="biography">“<?php echo nl2br(htmlspecialchars($biography, ENT_QUOTES, 'UTF-8')); ?>”</p>
                    <?php else : ?>
                        <p class="biography">Add a biography to tell fellow pilots about your adventures.</p>
                    <?php endif; ?>
                    <div class="quick-links">
                        <a href="/profile.php">Edit Profile</a>
                        <a href="/changepassword.php">Change Password</a>
                        <a href="/forums/index.php">Visit Forums</a>
                        <a href="https://discord.gg/nD57Y3Kk4t" target="_blank" rel="noopener">Join our Discord</a>
                        <a href="https://dl.patchkit.net/d/69qfzorvustf81ye6regx" target="_blank" rel="noopener">Download Launcher</a>
                    </div>
                </div>
            </section>

            <section class="card" aria-label="Server status">
                <h3>Server Diagnostics</h3>
                <ul class="server-status-list">
                    <?php foreach ($statuses as $label => $online) : ?>
                        <li>
                            <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="<?php echo $online ? 'status-online' : 'status-offline'; ?>"><?php echo $online ? 'Online' : 'Offline'; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <span>Pilots in Galaxy</span>
                        <span class="<?php echo $onlinePlayers !== null ? 'status-online' : 'status-offline'; ?>">
                            <?php echo $onlinePlayers !== null ? $onlinePlayers : 'Unavailable'; ?>
                        </span>
                    </li>
                    <?php if ($totalCharacters !== null) : ?>
                        <li>
                            <span>Total Registered Characters</span>
                            <span><?php echo $totalCharacters; ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </section>

            <section class="card" aria-label="Mission queue">
                <h3>Mission Briefings</h3>
                <ul>
                    <?php foreach ($highlightedMissions as $mission) : ?>
                        <li><?php echo htmlspecialchars($mission, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 1rem; color: #94a3b8;">Need inspiration? Roll a new objective every time you visit.</p>
            </section>

            <section class="card" aria-label="Account history">
                <h3>Account Timeline</h3>
                <ul>
                    <?php if ($createdAt) : ?>
                        <li>Account created on <?php echo htmlspecialchars((new DateTimeImmutable($createdAt . ' UTC'))->format('M j, Y'), ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endif; ?>
                    <?php foreach ($activityLog as $entry) : ?>
                        <li><?php echo htmlspecialchars($entry, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="card" aria-label="Allied pilots">
                <h3>Allied Pilots</h3>
                <p style="color: #94a3b8; margin-top: 0.5rem;">Signal fellow commanders by their SWG+ username to grow your squadron.</p>
                <form method="post" class="friend-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="send_friend_request">
                    <input type="text" name="friend_username" placeholder="Username (case-insensitive)" value="<?php echo htmlspecialchars($pendingFriendUsername, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Friend username">
                    <button type="submit">Send Friend Request</button>
                </form>

                <div class="friend-columns">
                    <div>
                        <h4>Incoming</h4>
                        <ul class="friend-list">
                            <?php if (empty($incomingFriendRequests)) : ?>
                                <li class="friend-empty">No transmissions yet.</li>
                            <?php else : ?>
                                <?php foreach ($incomingFriendRequests as $request) : ?>
                                    <?php
                                    $requesterName = trim((string) ($request['display_name'] ?? ''));
                                    if ($requesterName === '') {
                                        $requesterName = $request['username'] ?? 'Unknown';
                                    }
                                    ?>
                                    <li>
                                        <div>
                                            <span class="friend-name"><?php echo htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="friend-handle">@<?php echo htmlspecialchars($request['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="friend-actions">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="accept_friend_request">
                                                <input type="hidden" name="friendship_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                <button type="submit" class="approve">Accept</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="decline_friend_request">
                                                <input type="hidden" name="friendship_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                <button type="submit" class="decline">Decline</button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div>
                        <h4>Outgoing</h4>
                        <ul class="friend-list">
                            <?php if (empty($outgoingFriendRequests)) : ?>
                                <li class="friend-empty">Awaiting your next invite.</li>
                            <?php else : ?>
                                <?php foreach ($outgoingFriendRequests as $request) : ?>
                                    <?php
                                    $addresseeName = trim((string) ($request['display_name'] ?? ''));
                                    if ($addresseeName === '') {
                                        $addresseeName = $request['username'] ?? 'Unknown';
                                    }
                                    ?>
                                    <li>
                                        <div>
                                            <span class="friend-name"><?php echo htmlspecialchars($addresseeName, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="friend-handle">@<?php echo htmlspecialchars($request['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div>
                        <h4>Allies</h4>
                        <ul class="friend-list">
                            <?php if (empty($acceptedFriends)) : ?>
                                <li class="friend-empty">No allies yet — send some invites!</li>
                            <?php else : ?>
                                <?php foreach ($acceptedFriends as $friend) : ?>
                                    <?php
                                    $friendName = trim((string) ($friend['display_name'] ?? ''));
                                    if ($friendName === '') {
                                        $friendName = $friend['username'] ?? 'Unknown';
                                    }
                                    ?>
                                    <li>
                                        <div>
                                            <span class="friend-name"><?php echo htmlspecialchars($friendName, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="friend-handle">@<?php echo htmlspecialchars($friend['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="card" aria-label="Community launch bay">
                <h3>Community Launch Bay</h3>
                <p style="color: #94a3b8; margin-top: 0.5rem;">Coordinate ops in Discord and keep your launcher up to date.</p>
                <div class="cta-links">
                    <a href="https://discord.gg/nD57Y3Kk4t" target="_blank" rel="noopener">Enter the SWG+ Discord</a>
                    <a class="secondary" href="https://dl.patchkit.net/d/69qfzorvustf81ye6regx" target="_blank" rel="noopener">Download SWG+ Launcher</a>
                </div>
            </section>
        </div>

        <footer>
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?>. Harness the power of the SWG+ network.
        </footer>
    </div>
</body>
</html>
