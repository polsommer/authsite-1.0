<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_functions.php';

/**
 * Locate a user by username while ensuring case-insensitive matching.
 */
function findUserIdByUsername(mysqli $db, string $username): ?int
{
    $normalized = trim($username);
    if ($normalized === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT user_id FROM user_account WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $stmt->bind_result($userId);

    if ($stmt->fetch()) {
        return (int) $userId;
    }

    return null;
}

function findExistingFriendship(mysqli $db, int $userId, int $otherUserId): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM user_friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1'
    );
    $stmt->bind_param('iiii', $userId, $otherUserId, $otherUserId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();

    return $record ?: null;
}

function sendFriendRequest(mysqli $db, int $requesterId, string $toUsername): array
{
    $targetId = findUserIdByUsername($db, $toUsername);

    if ($targetId === null) {
        return ['type' => 'error', 'message' => 'We could not find a pilot with that callsign.'];
    }

    if ($targetId === $requesterId) {
        return ['type' => 'error', 'message' => 'You cannot send yourself a friend request.'];
    }

    $existing = findExistingFriendship($db, $requesterId, $targetId);

    if ($existing) {
        $status = $existing['status'] ?? '';
        if ($status === 'accepted') {
            return ['type' => 'info', 'message' => 'You are already linked as allies.'];
        }

        if ($status === 'pending') {
            if ((int) $existing['requester_id'] === $requesterId) {
                return ['type' => 'info', 'message' => 'A request is already awaiting their response.'];
            }

            return ['type' => 'info', 'message' => 'They have already reached out. Check your incoming requests.'];
        }

        // Allow re-request after a decline by refreshing the relationship.
        $stmt = $db->prepare('UPDATE user_friendships SET requester_id = ?, addressee_id = ?, status = "pending", created_at = CURRENT_TIMESTAMP, responded_at = NULL WHERE id = ?');
        $stmt->bind_param('iii', $requesterId, $targetId, $existing['id']);
        $stmt->execute();

        return ['type' => 'success', 'message' => 'New request transmitted.'];
    }

    $stmt = $db->prepare('INSERT INTO user_friendships (requester_id, addressee_id, status) VALUES (?, ?, "pending")');
    $stmt->bind_param('ii', $requesterId, $targetId);
    $stmt->execute();

    return ['type' => 'success', 'message' => 'Friend request sent successfully.'];
}

function respondToFriendRequest(mysqli $db, int $friendshipId, int $userId, string $response): array
{
    $stmt = $db->prepare('SELECT id, requester_id, addressee_id, status FROM user_friendships WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $friendshipId);
    $stmt->execute();
    $result = $stmt->get_result();
    $friendship = $result->fetch_assoc();

    if (!$friendship) {
        return ['type' => 'error', 'message' => 'Friend request not found.'];
    }

    if ((int) $friendship['addressee_id'] !== $userId) {
        return ['type' => 'error', 'message' => 'You are not authorized to respond to that request.'];
    }

    if ($friendship['status'] !== 'pending') {
        return ['type' => 'info', 'message' => 'That request has already been handled.'];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($response === 'accept') {
        $stmt = $db->prepare('UPDATE user_friendships SET status = "accepted", responded_at = ? WHERE id = ?');
        $stmt->bind_param('si', $now, $friendshipId);
        $stmt->execute();

        return ['type' => 'success', 'message' => 'Friend request accepted.'];
    }

    $stmt = $db->prepare('UPDATE user_friendships SET status = "declined", responded_at = ? WHERE id = ?');
    $stmt->bind_param('si', $now, $friendshipId);
    $stmt->execute();

    return ['type' => 'info', 'message' => 'Friend request declined.'];
}

function getAcceptedFriends(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT f.id, CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END AS friend_user_id, u.username, u.display_name
         FROM user_friendships f
         INNER JOIN user_account u ON u.user_id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
         WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = "accepted"
         ORDER BY u.display_name IS NULL, u.display_name'
    );
    $stmt->bind_param('iiii', $userId, $userId, $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

function getIncomingFriendRequests(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT f.id, f.requester_id, u.username, u.display_name, f.created_at
         FROM user_friendships f
         INNER JOIN user_account u ON u.user_id = f.requester_id
         WHERE f.addressee_id = ? AND f.status = "pending"
         ORDER BY f.created_at DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

function getOutgoingFriendRequests(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT f.id, f.addressee_id, u.username, u.display_name, f.created_at
         FROM user_friendships f
         INNER JOIN user_account u ON u.user_id = f.addressee_id
         WHERE f.requester_id = ? AND f.status = "pending"
         ORDER BY f.created_at DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}
