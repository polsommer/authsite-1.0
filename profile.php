<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';

ensureSessionStarted();
requireAuthenticatedUser('/profile.php');

$config = require __DIR__ . '/includes/config.php';
$userId = currentUserId();
$user = $userId !== null ? findUserById($mysqli, $userId) : null;

if (!$user) {
    $_SESSION = [];
    header('Location: /logout.php');
    exit;
}

$factions = [
    'Galactic Empire',
    'Rebel Alliance',
    'Independent',
    'Smuggler Coalition',
    'Mandalorian Clans',
    'Galactic Civilian Corps',
];

$activities = [
    'Pilot',
    'Crafter',
    'Bounty Hunter',
    'Entertainer',
    'Guild Quartermaster',
    'Explorer',
];

$timezones = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Europe/Moscow',
    'Asia/Tokyo',
    'Asia/Singapore',
    'Australia/Sydney',
];

$errors = [];
$success = false;

$displayName = trim((string) ($user['display_name'] ?? $user['username'] ?? ''));
$selectedFaction = $user['faction'] ?: 'Independent';
$selectedActivity = $user['favorite_activity'] ?: 'Explorer';
$selectedTimezone = $user['timezone'] ?: 'UTC';
$discordHandle = trim((string) ($user['discord_handle'] ?? ''));
$avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
$biography = trim((string) ($user['biography'] ?? ''));
$previousAvatarUrl = $avatarUrl;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired. Please resubmit the form.';
    } else {
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $selectedFaction = (string) ($_POST['faction'] ?? 'Independent');
        $selectedActivity = (string) ($_POST['favorite_activity'] ?? 'Explorer');
        $selectedTimezone = (string) ($_POST['timezone'] ?? 'UTC');
        $discordHandle = trim((string) ($_POST['discord_handle'] ?? ''));
        $avatarUrl = trim((string) ($_POST['avatar_url'] ?? ''));
        $biography = trim((string) ($_POST['biography'] ?? ''));

        $avatarUpload = $_FILES['avatar_upload'] ?? null;
        $avatarDirectory = __DIR__ . '/images/avatars';
        $maxAvatarSize = 2 * 1024 * 1024; // 2MB

        if ($avatarUpload && ($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($avatarUpload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'We could not upload your avatar. Please try again.';
            } elseif (($avatarUpload['size'] ?? 0) > $maxAvatarSize) {
                $errors[] = 'Avatar files must be 2MB or smaller.';
            } elseif (!is_uploaded_file($avatarUpload['tmp_name'] ?? '')) {
                $errors[] = 'Unexpected upload error occurred. Please retry.';
            } else {
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];

                try {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($avatarUpload['tmp_name']) ?: '';
                } catch (Throwable $exception) {
                    $mimeType = '';
                }

                if (!array_key_exists($mimeType, $allowedMimes)) {
                    $errors[] = 'Please upload a JPG, PNG, GIF, or WEBP image.';
                } else {
                    if (!is_dir($avatarDirectory)) {
                        if (!mkdir($avatarDirectory, 0755, true) && !is_dir($avatarDirectory)) {
                            $errors[] = 'Unable to prepare storage for avatars. Please contact an administrator.';
                        }
                    }

                    if (empty($errors)) {
                        try {
                            $extension = $allowedMimes[$mimeType];
                            $randomComponent = bin2hex(random_bytes(6));
                            $fileName = sprintf('avatar_%d_%s.%s', (int) $userId, $randomComponent, $extension);
                            $destination = $avatarDirectory . '/' . $fileName;

                            if (!move_uploaded_file($avatarUpload['tmp_name'], $destination)) {
                                $errors[] = 'We could not save your avatar. Please try again.';
                            } else {
                                if ($previousAvatarUrl !== '' && strpos($previousAvatarUrl, '/images/avatars/') === 0) {
                                    $previousPath = __DIR__ . $previousAvatarUrl;
                                    if (is_file($previousPath)) {
                                        @unlink($previousPath);
                                    }
                                }

                                $avatarUrl = '/images/avatars/' . $fileName;
                            }
                        } catch (Throwable $exception) {
                            $errors[] = 'We could not process your avatar upload. Please try again.';
                        }
                    }
                }
            }
        }

        if ($displayName === '' || strlen($displayName) < 3 || strlen($displayName) > 60) {
            $errors[] = 'Display name must be between 3 and 60 characters.';
        }

        if (!in_array($selectedFaction, $factions, true)) {
            $errors[] = 'Please choose a valid faction alignment.';
        }

        if (!in_array($selectedActivity, $activities, true)) {
            $errors[] = 'Select a playstyle that best represents you.';
        }

        if (!in_array($selectedTimezone, $timezones, true)) {
            $errors[] = 'Please choose a supported timezone.';
        }

        if ($discordHandle !== '' && !preg_match('/^@?[A-Za-z0-9_.\-]{2,32}(#\d{4})?$/', $discordHandle)) {
            $errors[] = 'Discord handle should include 2-32 characters and may end with #1234.';
        }

        if ($avatarUrl !== '') {
            $isLocalAvatar = strpos($avatarUrl, '/images/avatars/') === 0;
            if (!$isLocalAvatar) {
                if (!filter_var($avatarUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $avatarUrl)) {
                    $errors[] = 'Avatar URL must be a valid http or https address.';
                }
            }
        }

        if ($biography !== '') {
            $biography = strip_tags($biography);
            if (strlen($biography) > 700) {
                $errors[] = 'Biography cannot exceed 700 characters.';
            }
        }

        if (empty($errors)) {
            $profileData = [
                'display_name' => $displayName,
                'timezone' => $selectedTimezone,
                'faction' => $selectedFaction,
                'favorite_activity' => $selectedActivity,
                'discord_handle' => $discordHandle,
                'avatar_url' => $avatarUrl,
                'biography' => $biography,
            ];

            updateUserProfile($mysqli, (int) $userId, $profileData);
            $user = findUserById($mysqli, (int) $userId) ?? $user;
            refreshAuthenticatedSession($user);
            $success = true;
        }
    }
}

$csrfToken = getCsrfToken();
$hasCustomAvatar = $avatarUrl !== '';
$avatarPreview = $hasCustomAvatar ? $avatarUrl : '/images/swgsource.png';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Pilot Profile</title>
    <link rel="stylesheet" href="/stylesheet.css">
    <style>
        body {
            background: linear-gradient(140deg, rgba(15, 23, 42, 0.95), rgba(2, 6, 23, 0.96)), url('/images/vaderdeathstar.jpg') no-repeat center/cover fixed;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #e2e8f0;
        }

        .profile-wrapper {
            max-width: 820px;
            margin: 4rem auto;
            padding: 3rem;
            background: rgba(15, 23, 42, 0.9);
            border-radius: 20px;
            box-shadow: 0 30px 55px rgba(2, 6, 23, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.25);
        }

        h1 {
            margin-top: 0;
            text-align: center;
            font-size: 2.2rem;
            letter-spacing: 0.12em;
        }

        p.subtitle {
            text-align: center;
            color: #cbd5e1;
            margin-bottom: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }

        label {
            display: block;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
            color: #94a3b8;
        }

        input[type="text"],
        input[type="url"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
            font-size: 1rem;
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .field-hint {
            margin-top: 0.35rem;
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .avatar-preview {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .avatar-preview img {
            width: 120px;
            height: 120px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid rgba(148, 163, 184, 0.45);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.55);
        }

        .actions {
            margin-top: 2.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }

        button[type="submit"] {
            padding: 0.95rem 2.75rem;
            border-radius: 999px;
            border: none;
            font-size: 1.05rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            background: linear-gradient(135deg, #38bdf8, #34d399);
            color: #0f172a;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(52, 211, 153, 0.35);
        }

        .notice {
            padding: 0.9rem 1.1rem;
            border-radius: 12px;
            margin-bottom: 1.75rem;
        }

        .notice.error {
            background: rgba(220, 38, 38, 0.15);
            border: 1px solid rgba(248, 113, 113, 0.45);
            color: #fecaca;
        }

        .notice.success {
            background: rgba(13, 148, 136, 0.18);
            border: 1px solid rgba(45, 212, 191, 0.45);
            color: #ccfbf1;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #bae6fd;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link:hover {
            color: #facc15;
        }

        @media (max-width: 640px) {
            .profile-wrapper {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="profile-wrapper">
        <a class="back-link" href="/dashboard.php">&larr; Back to Dashboard</a>
        <h1>Customize Your Pilot Profile</h1>
        <p class="subtitle">Fine-tune your public dossier so allies know who to call on for the next mission.</p>

        <?php if ($success) : ?>
            <div class="notice success">Profile updated! Your legend grows across the galaxy.</div>
        <?php endif; ?>

        <?php if (!empty($errors)) : ?>
            <div class="notice error">
                <strong>We detected a few issues:</strong>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="profile.php" enctype="multipart/form-data" novalidate>
            <div class="form-grid">
                <div>
                    <label for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name" required maxlength="60" value="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="faction">Preferred Faction</label>
                    <select id="faction" name="faction" required>
                        <?php foreach ($factions as $faction) : ?>
                            <option value="<?php echo htmlspecialchars($faction, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $faction === $selectedFaction ? 'selected' : ''; ?>><?php echo htmlspecialchars($faction, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="favorite_activity">Playstyle</label>
                    <select id="favorite_activity" name="favorite_activity" required>
                        <?php foreach ($activities as $activity) : ?>
                            <option value="<?php echo htmlspecialchars($activity, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $activity === $selectedActivity ? 'selected' : ''; ?>><?php echo htmlspecialchars($activity, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="timezone">Primary Timezone</label>
                    <select id="timezone" name="timezone" required>
                        <?php foreach ($timezones as $timezone) : ?>
                            <option value="<?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $timezone === $selectedTimezone ? 'selected' : ''; ?>><?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="discord_handle">Discord Handle</label>
                    <input type="text" id="discord_handle" name="discord_handle" maxlength="40" placeholder="@SWGPlusPilot" value="<?php echo htmlspecialchars($discordHandle, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="avatar_url">Avatar URL</label>
                    <input type="url" id="avatar_url" name="avatar_url" placeholder="https://example.com/avatar.jpg" value="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="field-hint">Paste an image link or upload a new avatar below.</p>
                </div>
                <div>
                    <label for="avatar_upload">Upload Avatar</label>
                    <input type="file" id="avatar_upload" name="avatar_upload" accept="image/png,image/jpeg,image/gif,image/webp">
                    <p class="field-hint">Supported formats: JPG, PNG, GIF, WEBP (max 2MB).</p>
                </div>
                <div class="avatar-preview">
                    <label>Current Avatar</label>
                    <img src="<?php echo htmlspecialchars($avatarPreview, ENT_QUOTES, 'UTF-8'); ?>" alt="Current profile avatar">
                    <p class="field-hint"><?php echo $hasCustomAvatar ? 'This is your current avatar.' : 'Showing the default avatar until you upload a new one.'; ?></p>
                </div>
            </div>
            <div style="margin-top: 1.5rem;">
                <label for="biography">Biography</label>
                <textarea id="biography" name="biography" maxlength="700" placeholder="Share your greatest victories and signature moves."><?php echo htmlspecialchars($biography, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="actions">
                <button type="submit">Save Profile</button>
                <a class="back-link" href="/logout.php">Log Out</a>
            </div>
        </form>
    </div>
</body>
</html>
