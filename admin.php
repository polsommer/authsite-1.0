<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/admin_functions.php';
require_once __DIR__ . '/includes/server_status.php';
require_once __DIR__ . '/includes/market_functions.php';

ensureSessionStarted();
requireAccessLevel(['admin', 'superadmin'], '/admin.php');

$config = require __DIR__ . '/includes/config.php';

$flashMessages = $_SESSION['admin_flash'] ?? [];
unset($_SESSION['admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['admin_flash'] = [[
            'type' => 'error',
            'message' => 'Security token expired. Refresh the console and try again.',
        ]];
        header('Location: /admin.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $messages = [];
    $currentUserId = currentUserId();
    $isSuperAdmin = hasAccessLevel('superadmin');

    switch ($action) {
        case 'update_access_level':
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $newLevel = strtolower(trim((string) ($_POST['new_level'] ?? '')));
            $target = $targetId > 0 ? findUserById($mysqli, $targetId) : null;

            if (!$target) {
                $messages[] = ['type' => 'error', 'message' => 'The selected account could not be located.'];
                break;
            }

            if (!$isSuperAdmin && strcasecmp((string) ($target['accesslevel'] ?? ''), 'superadmin') === 0) {
                $messages[] = ['type' => 'error', 'message' => 'Only a super admin can modify another super admin.'];
                break;
            }

            $allowedLevels = $isSuperAdmin
                ? ['standard', 'moderator', 'admin', 'superadmin']
                : ['standard', 'moderator', 'admin'];

            if (!in_array($newLevel, $allowedLevels, true)) {
                $messages[] = ['type' => 'error', 'message' => 'The requested access level is not permitted.'];
                break;
            }

            if (!$isSuperAdmin && $newLevel === 'superadmin') {
                $messages[] = ['type' => 'error', 'message' => 'Only a super admin can promote another super admin.'];
                break;
            }

            if (updateUserAccessLevel($mysqli, $targetId, $newLevel)) {
                if ($targetId === $currentUserId) {
                    $_SESSION['accesslevel'] = $newLevel;
                }

                try {
                    logSecurityEvent($mysqli, 'admin_update_access', currentIpAddress());
                } catch (Throwable $exception) {
                    // Swallow logging issues to avoid breaking the workflow.
                }

                $messages[] = [
                    'type' => 'success',
                    'message' => sprintf(
                        'Access level for %s updated to %s.',
                        $target['username'] ?? 'unknown',
                        strtoupper($newLevel)
                    ),
                ];
            } else {
                $messages[] = ['type' => 'error', 'message' => 'The access level update failed.'];
            }
            break;

        case 'toggle_verification':
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $desiredState = $_POST['desired_state'] ?? 'verified';
            $shouldVerify = $desiredState === 'verified';
            $target = $targetId > 0 ? findUserById($mysqli, $targetId) : null;

            if (!$target) {
                $messages[] = ['type' => 'error', 'message' => 'The selected account could not be located.'];
                break;
            }

            if (setUserVerificationStatus($mysqli, $targetId, $shouldVerify)) {
                try {
                    logSecurityEvent($mysqli, 'admin_toggle_verification', currentIpAddress());
                } catch (Throwable $exception) {
                    // Ignore logging issues.
                }

                $messages[] = [
                    'type' => 'success',
                    'message' => $shouldVerify
                        ? sprintf('Email verification marked complete for %s.', $target['username'] ?? 'unknown')
                        : sprintf('Verification reset for %s. A new token has been generated.', $target['username'] ?? 'unknown'),
                ];
            } else {
                $messages[] = ['type' => 'error', 'message' => 'Unable to update the verification status.'];
            }
            break;

        case 'add_network_ban':
            $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($ipAddress === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                $messages[] = ['type' => 'error', 'message' => 'Provide a valid IPv4 or IPv6 address to ban.'];
                break;
            }

            if (addBannedNetwork($mysqli, $ipAddress, $reason)) {
                try {
                    logSecurityEvent($mysqli, 'admin_add_ban', currentIpAddress());
                } catch (Throwable $exception) {
                    // Ignore logging issues.
                }

                $messages[] = ['type' => 'success', 'message' => sprintf('Network %s has been banned.', $ipAddress)];
            } else {
                $messages[] = ['type' => 'error', 'message' => 'Unable to add the ban. The network might already be listed.'];
            }
            break;

        case 'remove_network_ban':
            $banId = (int) ($_POST['ban_id'] ?? 0);

            if ($banId <= 0) {
                $messages[] = ['type' => 'error', 'message' => 'Select a valid ban entry to remove.'];
                break;
            }

            if (removeBannedNetwork($mysqli, $banId)) {
                try {
                    logSecurityEvent($mysqli, 'admin_remove_ban', currentIpAddress());
                } catch (Throwable $exception) {
                    // Ignore logging issues.
                }

                $messages[] = ['type' => 'success', 'message' => 'The network ban has been removed.'];
            } else {
                $messages[] = ['type' => 'error', 'message' => 'Unable to remove the selected network ban.'];
            }
            break;

        case 'purge_security_events':
            $retentionDays = (int) ($_POST['retention_days'] ?? 30);
            $retentionDays = max(1, min($retentionDays, 365));

            try {
                purgeOldSecurityEvents($mysqli, $retentionDays);
                try {
                    logSecurityEvent($mysqli, 'admin_purge_security_events', currentIpAddress());
                } catch (Throwable $exception) {
                    // Ignore logging issues.
                }

                $messages[] = ['type' => 'success', 'message' => sprintf('Security logs older than %d days have been purged.', $retentionDays)];
            } catch (Throwable $exception) {
                $messages[] = ['type' => 'error', 'message' => 'Unable to purge security events.'];
            }
            break;

        default:
            $messages[] = ['type' => 'error', 'message' => 'Unknown administrative action requested.'];
            break;
    }

    if (!empty($messages)) {
        $_SESSION['admin_flash'] = $messages;
    }

    header('Location: /admin.php');
    exit;
}

$searchQuery = trim((string) ($_GET['search'] ?? ''));
$searchResults = $searchQuery !== '' ? findUsersByQuery($mysqli, $searchQuery, 25) : [];

$summary = fetchUserSummary($mysqli);
$playerCounts = fetchPlayerActivityCounts($mysqli);
$recentRegistrations = fetchRecentUsers($mysqli, 8);
$recentEvents = fetchRecentSecurityEvents($mysqli, 12);
$eventBreakdown = fetchSecurityEventBreakdown($mysqli, 24);
$bannedNetworks = fetchBannedNetworks($mysqli, 50);
$marketSummary = summarizeMarketplace($mysqli);
$marketSpotlight = fetchActiveListings($mysqli, null, null, 6);
$marketCategories = getMarketCategories();

$serverHost = getenv('SWG_SERVER_HOST') ?: '127.0.0.1';
$loginPort = (int) (getenv('SWG_LOGIN_PORT') ?: 44453);
$gamePort = (int) (getenv('SWG_GAME_PORT') ?: 44463);
$mysqlPort = (int) (getenv('SWG_MYSQL_PORT') ?: 3306);
$timeoutSeconds = (int) (getenv('SWG_PORT_TIMEOUT') ?: 5);

$serviceStatuses = resolveServerStatuses($serverHost, $timeoutSeconds, [
    'Login Service' => $loginPort,
    'Galaxy Service' => $gamePort,
    'Database' => $mysqlPort,
]);

$totalEvents24h = array_sum(array_map(static fn ($row) => (int) ($row['total'] ?? 0), $eventBreakdown));
$csrfToken = getCsrfToken();
$isSuperAdmin = hasAccessLevel('superadmin');
$accessLevelOptions = $isSuperAdmin
    ? ['standard', 'moderator', 'admin', 'superadmin']
    : ['standard', 'moderator', 'admin'];

function formatDate(?string $value, string $format = 'M j, Y \a\t g:i A \U\T\C'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format($format);
    } catch (Throwable $exception) {
        return '—';
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Operations Command</title>
    <link rel="stylesheet" href="/stylesheet.css">
    <style>
        body {
            margin: 0;
            background: linear-gradient(145deg, rgba(2, 6, 23, 0.95), rgba(15, 23, 42, 0.95) 55%, rgba(8, 47, 73, 0.95)), url('/images/starfield.jpg') no-repeat center/cover fixed;
            color: #e2e8f0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        a {
            color: #38bdf8;
        }

        .admin-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2.4rem;
            margin: 0;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .page-header p {
            margin: 0;
            max-width: 720px;
            line-height: 1.6;
            color: rgba(226, 232, 240, 0.85);
        }

        .back-link {
            width: fit-content;
            text-decoration: none;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgba(148, 163, 184, 0.9);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .back-link:before {
            content: '⟵';
        }

        .flash-area {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        .flash {
            padding: 1rem 1.25rem;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.75);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .flash.success {
            border-color: rgba(45, 212, 191, 0.6);
            background: rgba(20, 83, 45, 0.35);
        }

        .flash.error {
            border-color: rgba(248, 113, 113, 0.7);
            background: rgba(153, 27, 27, 0.35);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1.2rem;
            margin-bottom: 2.5rem;
        }

        .metric-card {
            padding: 1.5rem 1.25rem;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .metric-card span.label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgba(148, 163, 184, 0.9);
        }

        .metric-card span.value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-card small {
            color: rgba(226, 232, 240, 0.7);
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.2rem;
            margin-bottom: 3rem;
        }

        .status-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 1.4rem 1.25rem;
            background: rgba(15, 23, 42, 0.85);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .status-card.online {
            border-color: rgba(45, 212, 191, 0.65);
            box-shadow: 0 12px 30px rgba(20, 184, 166, 0.25);
        }

        .status-card.offline {
            border-color: rgba(248, 113, 113, 0.6);
            box-shadow: 0 12px 30px rgba(239, 68, 68, 0.2);
        }

        .status-icon {
            font-size: 1.8rem;
        }

        .status-details h3 {
            margin: 0;
            font-size: 1.1rem;
        }

        .status-details span {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgba(148, 163, 184, 0.85);
        }

        .panel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .panel {
            background: rgba(15, 23, 42, 0.82);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 18px;
            padding: 1.75rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .panel h2 {
            margin: 0;
            font-size: 1.3rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .search-form {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .search-form input[type="text"] {
            flex: 1;
            min-width: 180px;
            padding: 0.7rem 0.85rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(15, 23, 42, 0.7);
            color: #f8fafc;
        }

        .search-form button {
            padding: 0.7rem 1.6rem;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #22d3ee, #0ea5e9);
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }

        th, td {
            padding: 0.55rem 0.4rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            text-align: left;
        }

        th {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            color: rgba(148, 163, 184, 0.75);
        }

        td.actions {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .inline-form {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .inline-form select,
        .inline-form input[type="number"],
        .inline-form input[type="text"] {
            flex: 1;
            min-width: 140px;
            padding: 0.5rem 0.65rem;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.65);
            color: #e2e8f0;
        }

        .inline-form button {
            padding: 0.55rem 1.2rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            color: #0f172a;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .inline-form.danger button {
            background: linear-gradient(135deg, #f87171, #ef4444);
            color: #fff5f5;
        }

        ul.event-list,
        ul.registration-list,
        ul.market-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        ul.event-list li,
        ul.registration-list li,
        ul.market-list li {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 14px;
            padding: 0.75rem 0.9rem;
            line-height: 1.5;
        }

        .market-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: rgba(148, 163, 184, 0.85);
        }

        .market-stats span {
            background: rgba(15, 118, 110, 0.25);
            border: 1px solid rgba(45, 212, 191, 0.35);
            border-radius: 999px;
            padding: 0.35rem 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(94, 234, 212, 0.9);
        }

        .list-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(148, 163, 184, 0.7);
        }

        .list-primary {
            font-weight: 600;
        }

        .breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.85rem;
        }

        .breakdown-card {
            background: rgba(15, 23, 42, 0.68);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
        }

        .panel-footer {
            margin-top: auto;
            font-size: 0.75rem;
            color: rgba(148, 163, 184, 0.7);
        }

        .ban-form {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .ban-form textarea {
            min-height: 70px;
            padding: 0.65rem 0.8rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.65);
            color: #e2e8f0;
            resize: vertical;
        }

        .ban-form button {
            align-self: flex-start;
            padding: 0.65rem 1.4rem;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #facc15, #f59e0b);
            color: #0f172a;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .bans-table td.actions {
            flex-direction: row;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .status-grid,
            .metrics-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }

            .inline-form {
                flex-direction: column;
                align-items: stretch;
            }

            .inline-form button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <div class="page-header">
        <a class="back-link" href="/dashboard.php">Back to Pilot Dashboard</a>
        <h1>Operations Command</h1>
        <p>Monitor service uptime, manage pilot access, and triage incidents from a single command console tailored for SWG+ staff.</p>
    </div>

    <?php if (!empty($flashMessages)) : ?>
        <div class="flash-area">
            <?php foreach ($flashMessages as $flash) : ?>
                <?php $type = $flash['type'] ?? 'info'; ?>
                <div class="flash <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
                    <span><?php echo htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="list-label"><?php echo date('M j, Y g:i A'); ?> UTC</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="metrics-grid">
        <div class="metric-card">
            <span class="label">Total Accounts</span>
            <span class="value"><?php echo number_format($summary['total']); ?></span>
            <small><?php echo number_format($summary['verified']); ?> verified · <?php echo number_format($summary['pending']); ?> pending</small>
        </div>
        <div class="metric-card">
            <span class="label">Leadership</span>
            <span class="value"><?php echo number_format($summary['admin'] + $summary['superadmin']); ?></span>
            <small><?php echo number_format($summary['admin']); ?> admins · <?php echo number_format($summary['superadmin']); ?> super admins</small>
        </div>
        <div class="metric-card">
            <span class="label">Active Pilots</span>
            <span class="value"><?php echo $playerCounts['online'] !== null ? number_format((int) $playerCounts['online']) : 'n/a'; ?></span>
            <small><?php echo $playerCounts['total'] !== null ? number_format((int) $playerCounts['total']) . ' characters' : 'Galaxy data unavailable'; ?></small>
        </div>
        <div class="metric-card">
            <span class="label">Banned Networks</span>
            <span class="value"><?php echo number_format(count($bannedNetworks)); ?></span>
            <small>Guarding hyperspace lanes</small>
        </div>
        <div class="metric-card">
            <span class="label">Security Events (24h)</span>
            <span class="value"><?php echo number_format($totalEvents24h); ?></span>
            <small>Across <?php echo count($eventBreakdown); ?> action types</small>
        </div>
        <div class="metric-card">
            <span class="label">Holonet Exchange</span>
            <span class="value"><?php echo number_format((int) ($marketSummary['active'] ?? 0)); ?></span>
            <small><?php echo number_format((int) ($marketSummary['traders'] ?? 0)); ?> traders · <?php echo number_format((int) ($marketSummary['closed'] ?? 0)); ?> closed</small>
        </div>
    </section>

    <section class="status-grid">
        <?php foreach ($serviceStatuses as $service => $online) : ?>
            <?php $isOnline = $online === true; ?>
            <div class="status-card <?php echo $isOnline ? 'online' : 'offline'; ?>">
                <div class="status-icon"><?php echo $isOnline ? '🟢' : '🔴'; ?></div>
                <div class="status-details">
                    <h3><?php echo htmlspecialchars($service, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <span><?php echo $isOnline ? 'Operational' : 'Offline'; ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel-grid" aria-label="Administrative tools">
        <article class="panel">
            <h2>Pilot Lookup &amp; Access Control</h2>
            <form class="search-form" method="get" action="/admin.php">
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by username or email">
                <button type="submit">Scan Directory</button>
            </form>
            <?php if ($searchQuery !== '') : ?>
                <?php if (!empty($searchResults)) : ?>
                    <table aria-label="Search results">
                        <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Access</th>
                            <th>Verification</th>
                            <th>Last Login</th>
                            <th class="actions">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($searchResults as $user) : ?>
                            <?php
                            $userLevel = strtolower((string) ($user['accesslevel'] ?? 'standard'));
                            $userVerified = !empty($user['email_verified_at']);
                            $isLockedTarget = !$isSuperAdmin && $userLevel === 'superadmin';
                            ?>
                            <tr>
                                <td class="list-primary"><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo strtoupper($userLevel); ?></td>
                                <td><?php echo $userVerified ? 'Verified' : 'Pending'; ?></td>
                                <td><?php echo htmlspecialchars(formatDate($user['last_login_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="actions">
                                    <form class="inline-form" method="post" action="/admin.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="update_access_level">
                                        <input type="hidden" name="user_id" value="<?php echo (int) ($user['user_id'] ?? 0); ?>">
                                        <select name="new_level" <?php echo $isLockedTarget ? 'disabled' : ''; ?>>
                                            <?php foreach ($accessLevelOptions as $level) : ?>
                                                <option value="<?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $userLevel === $level ? 'selected' : ''; ?>><?php echo strtoupper($level); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" <?php echo $isLockedTarget ? 'disabled' : ''; ?>>Update Access</button>
                                    </form>
                                    <form class="inline-form" method="post" action="/admin.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="toggle_verification">
                                        <input type="hidden" name="user_id" value="<?php echo (int) ($user['user_id'] ?? 0); ?>">
                                        <input type="hidden" name="desired_state" value="<?php echo $userVerified ? 'pending' : 'verified'; ?>">
                                        <button type="submit" class="<?php echo $userVerified ? 'secondary' : ''; ?>">
                                            <?php echo $userVerified ? 'Reset Verification' : 'Mark Verified'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>No pilots matched your search. Try a different call sign.</p>
                <?php endif; ?>
            <?php else : ?>
                <p class="panel-footer">Enter a username or email to begin orchestrating account changes.</p>
            <?php endif; ?>
        </article>

        <article class="panel">
            <h2>Recent Security Activity</h2>
            <div class="breakdown-grid">
                <?php if (!empty($eventBreakdown)) : ?>
                    <?php foreach ($eventBreakdown as $row) : ?>
                        <div class="breakdown-card">
                            <div class="list-label"><?php echo htmlspecialchars($row['action'] ?? 'unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="list-primary"><?php echo number_format((int) ($row['total'] ?? 0)); ?> events</div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p>No recent security telemetry recorded.</p>
                <?php endif; ?>
            </div>
            <ul class="event-list">
                <?php if (!empty($recentEvents)) : ?>
                    <?php foreach ($recentEvents as $event) : ?>
                        <li>
                            <div class="list-label"><?php echo htmlspecialchars(formatDate($event['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="list-primary"><?php echo htmlspecialchars($event['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div>IP: <?php echo htmlspecialchars($event['ip_address'] ?? 'unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div style="font-size: 0.78rem; color: rgba(148, 163, 184, 0.75);">UA: <?php echo htmlspecialchars($event['user_agent'] ?? 'unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                        </li>
                    <?php endforeach; ?>
                <?php else : ?>
                    <li>No security events logged yet.</li>
                <?php endif; ?>
            </ul>
            <div class="panel-footer">Logs update in real time as registration, login, and moderation actions occur.</div>
        </article>

        <article class="panel">
            <h2>Network Shield</h2>
            <form class="ban-form" method="post" action="/admin.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_network_ban">
                <input type="text" name="ip_address" placeholder="Add IPv4 or IPv6 address" required>
                <textarea name="reason" placeholder="Reason (optional)"></textarea>
                <button type="submit">Add Network Ban</button>
            </form>
            <?php if (!empty($bannedNetworks)) : ?>
                <table class="bans-table" aria-label="Banned networks">
                    <thead>
                    <tr>
                        <th>Address</th>
                        <th>Owner</th>
                        <th>Reason</th>
                        <th>Added</th>
                        <th class="actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bannedNetworks as $ban) : ?>
                        <tr>
                            <td class="list-primary"><?php echo htmlspecialchars($ban['ip_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($ban['network_host'] ?? 'unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($ban['reason'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(formatDate($ban['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="actions">
                                <form class="inline-form danger" method="post" action="/admin.php" onsubmit="return confirm('Remove this network ban?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="remove_network_ban">
                                    <input type="hidden" name="ban_id" value="<?php echo (int) ($ban['id'] ?? 0); ?>">
                                    <button type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="panel-footer">No networks are currently banned. Use this shield to block hostile traffic instantly.</p>
            <?php endif; ?>
        </article>

        <article class="panel">
            <h2>Fresh Registrations</h2>
            <ul class="registration-list">
                <?php if (!empty($recentRegistrations)) : ?>
                    <?php foreach ($recentRegistrations as $recentUser) : ?>
                        <li>
                            <div class="list-primary"><?php echo htmlspecialchars($recentUser['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div><?php echo htmlspecialchars($recentUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(formatDate($recentUser['created_at'] ?? null, 'M j, Y g:i A \U\T\C'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="list-label"><?php echo !empty($recentUser['email_verified_at']) ? 'Verified' : 'Pending verification'; ?></div>
                        </li>
                    <?php endforeach; ?>
                <?php else : ?>
                    <li>No pilots have enlisted recently.</li>
                <?php endif; ?>
            </ul>
            <div class="panel-footer">Keep an eye on new recruits and confirm their verification status promptly.</div>
        </article>

        <article class="panel">
            <h2>Holonet Exchange Monitor</h2>
            <div class="market-stats">
                <span><?php echo number_format((int) ($marketSummary['active'] ?? 0)); ?> Active</span>
                <span><?php echo number_format((int) ($marketSummary['traders'] ?? 0)); ?> Traders</span>
                <span><?php echo number_format((int) ($marketSummary['closed'] ?? 0)); ?> Closed</span>
            </div>
            <?php if (!empty($marketSpotlight)) : ?>
                <ul class="market-list">
                    <?php foreach ($marketSpotlight as $listing) : ?>
                        <li>
                            <div class="list-primary"><?php echo htmlspecialchars($listing['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div><?php echo htmlspecialchars($marketCategories[$listing['category']] ?? ucfirst((string) ($listing['category'] ?? 'unknown')), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(number_format((float) ($listing['price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($listing['currency'] ?? 'CREDITS', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="list-label">Merchant: <?php echo htmlspecialchars($listing['display_name'] ?: $listing['seller_name'], ENT_QUOTES, 'UTF-8'); ?> · Posted <?php echo htmlspecialchars(formatDate($listing['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div style="font-size: 0.8rem; color: rgba(148, 163, 184, 0.8);">Contact: <?php echo htmlspecialchars($listing['contact_channel'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="panel-footer">No market activity detected yet. Encourage players to list their offerings.</p>
            <?php endif; ?>
        </article>

        <article class="panel">
            <h2>Security Maintenance</h2>
            <p>Trim historical telemetry to keep the command center fast and compliant with data retention policies.</p>
            <form class="inline-form" method="post" action="/admin.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="purge_security_events">
                <input type="number" name="retention_days" value="30" min="1" max="365">
                <button type="submit">Purge Logs</button>
            </form>
            <div class="panel-footer">This operation removes events older than the specified number of days.</div>
        </article>
    </section>
</div>
</body>
</html>
