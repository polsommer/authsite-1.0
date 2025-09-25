<?php
declare(strict_types=1);

function findUserByUsername(mysqli $db, string $username): ?array
{
    $stmt = $db->prepare('SELECT * FROM user_account WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    $user = $result->fetch_assoc();
    return $user ?: null;
}

function findUserById(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT * FROM user_account WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $user = $result->fetch_assoc();
    return $user ?: null;
}

function findUserByEmail(mysqli $db, string $email): ?array
{
    $stmt = $db->prepare('SELECT * FROM user_account WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    $user = $result->fetch_assoc();
    return $user ?: null;
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function requireVerifiedAccount(array $user): bool
{
    return !empty($user['email_verified_at']);
}

function markEmailVerified(mysqli $db, int $userId): void
{
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $null = null;
    $stmt = $db->prepare('UPDATE user_account SET email_verified_at = ?, email_verification_token = ? WHERE user_id = ?');
    $stmt->bind_param('ssi', $timestamp, $null, $userId);
    $stmt->execute();
}

function updateLastLogin(mysqli $db, int $userId): void
{
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = $db->prepare('UPDATE user_account SET last_login_at = ? WHERE user_id = ?');
    $stmt->bind_param('si', $timestamp, $userId);
    $stmt->execute();
}

function updateUserProfile(mysqli $db, int $userId, array $profile): void
{
    $stmt = $db->prepare('UPDATE user_account SET display_name = ?, timezone = ?, faction = ?, favorite_activity = ?, discord_handle = ?, avatar_url = ?, biography = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
    $stmt->bind_param(
        'sssssssi',
        $profile['display_name'],
        $profile['timezone'],
        $profile['faction'],
        $profile['favorite_activity'],
        $profile['discord_handle'],
        $profile['avatar_url'],
        $profile['biography'],
        $userId
    );
    $stmt->execute();
}
