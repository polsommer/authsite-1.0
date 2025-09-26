<?php
declare(strict_types=1);

/**
 * Ensure we are operating with a secure PHP session.
 */
function ensureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

function getCsrfToken(): string
{
    ensureSessionStarted();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    ensureSessionStarted();

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function isAuthenticated(): bool
{
    ensureSessionStarted();

    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
}

function requireAuthenticatedUser(?string $intendedUrl = null): void
{
    ensureSessionStarted();

    if (isAuthenticated()) {
        return;
    }

    $requested = $intendedUrl ?? ($_SERVER['REQUEST_URI'] ?? '/index.php');
    $_SESSION['urlredirect'] = $requested;
    header('Location: /form_login.php');
    exit;
}

function currentUserId(): ?int
{
    ensureSessionStarted();

    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function refreshAuthenticatedSession(array $user): void
{
    ensureSessionStarted();

    $_SESSION['user_id'] = (int) ($user['user_id'] ?? 0);
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['user'] = $user['username'] ?? '';
    $_SESSION['accesslevel'] = $user['accesslevel'] ?? 'standard';
    $displayName = trim((string) ($user['display_name'] ?? ''));
    $_SESSION['display_name'] = $displayName !== '' ? $displayName : ($user['username'] ?? '');
}

function currentDisplayName(): string
{
    ensureSessionStarted();

    $display = trim((string) ($_SESSION['display_name'] ?? ''));
    if ($display !== '') {
        return $display;
    }

    $username = trim((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? ''));
    return $username !== '' ? $username : 'Pilot';
}

function currentAccessLevel(): string
{
    ensureSessionStarted();

    $level = strtolower(trim((string) ($_SESSION['accesslevel'] ?? 'standard')));

    return $level !== '' ? $level : 'standard';
}

function hasAccessLevel(string ...$levels): bool
{
    if (empty($levels)) {
        return false;
    }

    $current = currentAccessLevel();

    foreach ($levels as $level) {
        if (strtolower(trim($level)) === $current) {
            return true;
        }
    }

    return false;
}

function requireAccessLevel(array $levels, ?string $intendedUrl = null): void
{
    requireAuthenticatedUser($intendedUrl);

    if (empty($levels)) {
        return;
    }

    $normalized = array_map(static fn ($level) => strtolower(trim((string) $level)), $levels);

    if (!in_array(currentAccessLevel(), $normalized, true)) {
        http_response_code(403);
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Access Denied</title>
            <link rel="stylesheet" href="/stylesheet.css">
            <style>
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: radial-gradient(circle at top, rgba(15, 23, 42, 0.95) 0%, rgba(2, 6, 23, 0.95) 45%, rgba(0, 0, 0, 0.98) 100%), url('/images/stormtrooper.jpg') no-repeat center/cover fixed;
                    color: #e2e8f0;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    text-align: center;
                    padding: 2rem;
                }

                .denied-card {
                    max-width: 520px;
                    width: 100%;
                    background: rgba(15, 23, 42, 0.92);
                    border-radius: 18px;
                    padding: 2.5rem 2rem;
                    box-shadow: 0 25px 60px rgba(2, 6, 23, 0.65);
                    border: 1px solid rgba(148, 163, 184, 0.25);
                }

                h1 {
                    margin: 0 0 1rem;
                    letter-spacing: 0.15em;
                    text-transform: uppercase;
                    font-size: 1.75rem;
                }

                p {
                    margin-bottom: 2rem;
                    line-height: 1.6;
                }

                a.button {
                    display: inline-block;
                    padding: 0.75rem 2.5rem;
                    border-radius: 999px;
                    background: linear-gradient(135deg, #22d3ee, #0ea5e9);
                    color: #0f172a;
                    text-decoration: none;
                    font-weight: 600;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                }
            </style>
        </head>
        <body>
            <div class="denied-card">
                <h1>Access Restricted</h1>
                <p>Your credentials do not grant access to this command console. If you believe this is an error, contact a super admin to review your clearance.</p>
                <a class="button" href="/dashboard.php">Return to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

function currentIpAddress(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $ip;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function truncateForStorage(string $value, int $length = 250): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    $clean = trim($clean);

    if ($clean === '') {
        return 'unknown';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($clean, 0, $length, 'UTF-8');
    }

    return substr($clean, 0, $length);
}

function currentUserAgent(): string
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return truncateForStorage($agent);
}

function networkOwnerFromIp(string $ipAddress): string
{
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return 'unknown';
    }

    $host = @gethostbyaddr($ipAddress);

    if ($host === false || $host === $ipAddress) {
        return 'unknown';
    }

    return truncateForStorage($host);
}

function logSecurityEvent(mysqli $db, string $action, string $ipAddress): void
{
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $userAgent = currentUserAgent();
    $networkHost = networkOwnerFromIp($ipAddress);

    $stmt = $db->prepare('INSERT INTO auth_events (action, ip_address, user_agent, network_host, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $action, $ipAddress, $userAgent, $networkHost, $createdAt);
    $stmt->execute();
}

function hasExceededRateLimit(mysqli $db, string $action, string $ipAddress, int $maxAttempts, int $intervalSeconds): bool
{
    $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('PT' . $intervalSeconds . 'S'))
        ->format('Y-m-d H:i:s');

    $stmt = $db->prepare('SELECT COUNT(*) FROM auth_events WHERE action = ? AND ip_address = ? AND created_at >= ?');
    $stmt->bind_param('sss', $action, $ipAddress, $threshold);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();

    return (int) $count >= $maxAttempts;
}

function purgeOldSecurityEvents(mysqli $db, int $retentionDays = 30): void
{
    $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . max($retentionDays, 1) . 'D'))
        ->format('Y-m-d H:i:s');

    $stmt = $db->prepare('DELETE FROM auth_events WHERE created_at < ?');
    $stmt->bind_param('s', $threshold);
    $stmt->execute();
}

function findBannedNetwork(mysqli $db, string $ipAddress): ?array
{
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return null;
    }

    $stmt = $db->prepare('SELECT ip_address, network_host, reason FROM banned_networks WHERE ip_address = ? LIMIT 1');
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
    $result = $stmt->get_result();

    $record = $result->fetch_assoc();

    if (!$record) {
        return null;
    }

    if (empty($record['network_host']) || $record['network_host'] === 'unknown') {
        $resolved = networkOwnerFromIp($ipAddress);

        if ($resolved !== 'unknown') {
            $update = $db->prepare('UPDATE banned_networks SET network_host = ?, updated_at = CURRENT_TIMESTAMP WHERE ip_address = ?');
            $update->bind_param('ss', $resolved, $ipAddress);
            $update->execute();
            $record['network_host'] = $resolved;
        } else {
            $record['network_host'] = 'unknown';
        }
    }

    return $record;
}
