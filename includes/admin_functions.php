<?php
declare(strict_types=1);

/**
 * Administrative data helpers for the SWG+ Command Center.
 */
function fetchUserSummary(mysqli $db): array
{
    $summary = [
        'total' => 0,
        'verified' => 0,
        'pending' => 0,
        'admin' => 0,
        'superadmin' => 0,
    ];

    try {
        $result = $db->query(
            "SELECT
                COUNT(*) AS total_users,
                SUM(CASE WHEN email_verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified_users,
                SUM(CASE WHEN email_verified_at IS NULL THEN 1 ELSE 0 END) AS pending_users,
                SUM(CASE WHEN LOWER(accesslevel) = 'admin' THEN 1 ELSE 0 END) AS admin_users,
                SUM(CASE WHEN LOWER(accesslevel) = 'superadmin' THEN 1 ELSE 0 END) AS superadmin_users
            FROM user_account"
        );

        if ($result !== false) {
            $row = $result->fetch_assoc();
            if ($row) {
                $summary['total'] = (int) ($row['total_users'] ?? 0);
                $summary['verified'] = (int) ($row['verified_users'] ?? 0);
                $summary['pending'] = (int) ($row['pending_users'] ?? 0);
                $summary['admin'] = (int) ($row['admin_users'] ?? 0);
                $summary['superadmin'] = (int) ($row['superadmin_users'] ?? 0);
            }
        }
    } catch (Throwable $exception) {
        return $summary;
    }

    return $summary;
}

function fetchRecentUsers(mysqli $db, int $limit = 10): array
{
    $limit = max(1, min($limit, 50));
    $recent = [];

    try {
        $stmt = $db->prepare('SELECT user_id, username, email, accesslevel, email_verified_at, created_at FROM user_account ORDER BY created_at DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recent[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $recent;
}

function findUsersByQuery(mysqli $db, string $query, int $limit = 25): array
{
    $limit = max(1, min($limit, 50));
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $pattern = '%' . $query . '%';
    $users = [];

    try {
        $stmt = $db->prepare('SELECT user_id, username, email, accesslevel, email_verified_at, last_login_at, created_at FROM user_account WHERE username LIKE ? OR email LIKE ? ORDER BY username ASC LIMIT ?');
        $stmt->bind_param('ssi', $pattern, $pattern, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $users;
}

function updateUserAccessLevel(mysqli $db, int $userId, string $accessLevel): bool
{
    $allowed = ['standard', 'moderator', 'admin', 'superadmin'];
    $normalized = strtolower(trim($accessLevel));

    if (!in_array($normalized, $allowed, true)) {
        return false;
    }

    try {
        $stmt = $db->prepare('UPDATE user_account SET accesslevel = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
        $stmt->bind_param('si', $normalized, $userId);
        $stmt->execute();

        return $stmt->affected_rows >= 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function setUserVerificationStatus(mysqli $db, int $userId, bool $verified): bool
{
    try {
        if ($verified) {
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $stmt = $db->prepare('UPDATE user_account SET email_verified_at = ?, email_verification_token = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
            $stmt->bind_param('si', $timestamp, $userId);
        } else {
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare('UPDATE user_account SET email_verified_at = NULL, email_verification_token = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
            $stmt->bind_param('si', $token, $userId);
        }

        $stmt->execute();
        return $stmt->affected_rows >= 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function fetchRecentSecurityEvents(mysqli $db, int $limit = 15): array
{
    $limit = max(1, min($limit, 50));
    $events = [];

    try {
        $stmt = $db->prepare('SELECT action, ip_address, user_agent, created_at FROM auth_events ORDER BY created_at DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $events;
}

function fetchSecurityEventBreakdown(mysqli $db, int $hours = 24): array
{
    $hours = max(1, min($hours, 168));
    $breakdown = [];

    try {
        $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . $hours . 'H'))
            ->format('Y-m-d H:i:s');

        $stmt = $db->prepare('SELECT action, COUNT(*) AS total FROM auth_events WHERE created_at >= ? GROUP BY action ORDER BY total DESC LIMIT 10');
        $stmt->bind_param('s', $threshold);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $breakdown[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $breakdown;
}

function fetchBannedNetworks(mysqli $db, int $limit = 25): array
{
    $limit = max(1, min($limit, 100));
    $bans = [];

    try {
        $stmt = $db->prepare('SELECT id, ip_address, network_host, reason, created_at FROM banned_networks ORDER BY created_at DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $bans[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $bans;
}

function addBannedNetwork(mysqli $db, string $ipAddress, string $reason = ''): bool
{
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return false;
    }

    $networkHost = networkOwnerFromIp($ipAddress);
    $reason = trim($reason);

    try {
        $stmt = $db->prepare('INSERT INTO banned_networks (ip_address, network_host, reason, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE network_host = VALUES(network_host), reason = VALUES(reason), updated_at = CURRENT_TIMESTAMP');
        $stmt->bind_param('sss', $ipAddress, $networkHost, $reason);
        $stmt->execute();
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function removeBannedNetwork(mysqli $db, int $banId): bool
{
    if ($banId <= 0) {
        return false;
    }

    try {
        $stmt = $db->prepare('DELETE FROM banned_networks WHERE id = ?');
        $stmt->bind_param('i', $banId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function fetchPlayerActivityCounts(mysqli $db): array
{
    $data = [
        'online' => null,
        'total' => null,
    ];

    try {
        $onlineResult = $db->query('SELECT COUNT(*) AS online_count FROM characters WHERE online = 1');
        if ($onlineResult !== false) {
            $row = $onlineResult->fetch_assoc();
            $data['online'] = isset($row['online_count']) ? (int) $row['online_count'] : null;
        }
    } catch (Throwable $exception) {
        $data['online'] = null;
    }

    try {
        $totalResult = $db->query('SELECT COUNT(*) AS total_count FROM characters');
        if ($totalResult !== false) {
            $row = $totalResult->fetch_assoc();
            $data['total'] = isset($row['total_count']) ? (int) $row['total_count'] : null;
        }
    } catch (Throwable $exception) {
        $data['total'] = null;
    }

    return $data;
}
