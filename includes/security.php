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

function currentIpAddress(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $ip;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function logSecurityEvent(mysqli $db, string $action, string $ipAddress): void
{
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = $db->prepare('INSERT INTO auth_events (action, ip_address, created_at) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $action, $ipAddress, $createdAt);
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
