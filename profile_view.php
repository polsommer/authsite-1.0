<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/market_functions.php';

ensureSessionStarted();
requireAuthenticatedUser('/profile_view.php');

$config = require __DIR__ . '/includes/config.php';

$currentUserId = currentUserId();
$profileUser = null;

$requestedId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$requestedUsername = trim((string) ($_GET['username'] ?? ''));

if ($requestedId !== null && $requestedId > 0) {
    $profileUser = findUserById($mysqli, $requestedId);
} elseif ($requestedUsername !== '') {
    $profileUser = findUserByUsername($mysqli, $requestedUsername);
}

if (!$profileUser) {
    $profileUser = $currentUserId !== null ? findUserById($mysqli, (int) $currentUserId) : null;
}

if (!$profileUser) {
    header('Location: /logout.php');
    exit;
}

$isCurrentUser = $currentUserId !== null && (int) $profileUser['user_id'] === (int) $currentUserId;

$displayName = trim((string) ($profileUser['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = $profileUser['username'] ?? 'Galactic Traveller';
}

$faction = $profileUser['faction'] ?: 'Independent';
$favoriteActivity = $profileUser['favorite_activity'] ?: 'Explorer';
$discordHandle = $profileUser['discord_handle'] ?: 'Not shared';
$timezone = $profileUser['timezone'] ?: 'UTC';
$biography = trim((string) ($profileUser['biography'] ?? ''));
$avatarUrl = trim((string) ($profileUser['avatar_url'] ?? '')) ?: '/images/swgsource.png';

$createdAt = $profileUser['created_at'] ?? null;
$lastLoginAt = $profileUser['last_login_at'] ?? null;

$localTime = null;
try {
    $timezoneObject = new DateTimeZone($timezone);
    $localTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone($timezoneObject)
        ->format('M j, Y \a\t g:i A');
} catch (Throwable $exception) {
    $localTime = null;
}

$joinedDate = null;
try {
    if ($createdAt) {
        $joinedDate = (new DateTimeImmutable($createdAt . ' UTC'))->format('M j, Y');
    }
} catch (Throwable $exception) {
    $joinedDate = null;
}

$lastSeen = null;
try {
    if ($lastLoginAt) {
        $lastSeen = (new DateTimeImmutable($lastLoginAt . ' UTC'))->format('M j, Y \a\t g:i A \U\T\C');
    }
} catch (Throwable $exception) {
    $lastSeen = null;
}

$biographyDisplay = $biography !== ''
    ? nl2br(htmlspecialchars($biography, ENT_QUOTES, 'UTF-8'))
    : '<em>This holostar has yet to share their legend.</em>';

$badges = [];
if (stripos($favoriteActivity, 'craft') !== false) {
    $badges[] = 'Master Artisan';
}
if (stripos($favoriteActivity, 'bounty') !== false) {
    $badges[] = 'Guild Hunter';
}
if (stripos($favoriteActivity, 'entertain') !== false) {
    $badges[] = 'Cantina Virtuoso';
}
if (strlen($biography) > 280) {
    $badges[] = 'Lore Keeper';
}
if (trim((string) ($profileUser['discord_handle'] ?? '')) !== '') {
    $badges[] = 'Ready to Squad Up';
}
if (empty($badges)) {
    $badges[] = 'Galactic Citizen';
}

$activeListings = fetchActiveListingsBySeller($mysqli, (int) $profileUser['user_id']);

$ambientHooks = [
    'Send a holo-high five to break the ice!',
    'Ask about their favorite cantina jam.',
    'Plan a resource run together across the galaxy.',
    'Challenge them to a friendly swoop race.',
];
shuffle($ambientHooks);
$ambientHooks = array_slice($ambientHooks, 0, 2);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Holonet Profile</title>
    <link rel="stylesheet" href="/stylesheet.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, rgba(6, 16, 32, 0.95) 0%, rgba(2, 6, 23, 0.96) 75%), url('/images/mfalcon.jpg') no-repeat center/cover fixed;
            color: #f1f5f9;
        }
        .profile-shell {
            min-height: 100vh;
            backdrop-filter: blur(6px);
            background: rgba(15, 23, 42, 0.78);
            padding-bottom: 4rem;
        }
        .profile-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
            padding: 2.5rem 5vw 1.75rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
            background: linear-gradient(120deg, rgba(30, 41, 59, 0.85), rgba(15, 118, 110, 0.3));
        }
        .avatar-frame {
            position: relative;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(94, 234, 212, 0.6);
            box-shadow: 0 25px 45px rgba(2, 6, 23, 0.45);
        }
        .avatar-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-meta {
            flex: 1;
            min-width: 240px;
        }
        .profile-meta h1 {
            margin: 0;
            font-size: clamp(1.9rem, 3vw, 2.4rem);
            color: #5eead4;
            letter-spacing: 0.05em;
        }
        .profile-meta p {
            margin: 0.4rem 0;
            color: #cbd5f5;
            font-size: 1rem;
        }
        .badge-rack {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.45);
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }
        .profile-actions {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }
        .holo-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.7rem 1.2rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(15, 23, 42, 0.75);
            color: #e2e8f0;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        .holo-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 30px rgba(45, 212, 191, 0.2);
            border-color: rgba(94, 234, 212, 0.6);
        }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            padding: 2.5rem 5vw 1rem;
        }
        .profile-card {
            background: rgba(15, 23, 42, 0.85);
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 1.8rem;
            box-shadow: 0 30px 55px rgba(2, 6, 23, 0.5);
        }
        .profile-card h2 {
            margin-top: 0;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
        }
        .profile-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 0.75rem;
        }
        .profile-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0.75rem;
            border-radius: 12px;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(30, 64, 175, 0.35);
        }
        .profile-list span:first-child {
            color: #cbd5f5;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .biography {
            line-height: 1.7;
            color: #e2e8f0;
        }
        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.2rem;
            margin-top: 1.5rem;
        }
        .listing-card {
            background: rgba(22, 30, 46, 0.9);
            border-radius: 16px;
            padding: 1.2rem 1.3rem;
            border: 1px solid rgba(56, 189, 248, 0.28);
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }
        .listing-card h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #f8fafc;
        }
        .listing-card p {
            margin: 0;
            color: #cbd5f5;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .listing-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: #bae6fd;
        }
        .no-listings {
            margin-top: 1rem;
            color: #94a3b8;
            font-style: italic;
        }
        .hook-list {
            list-style: disc;
            margin: 0.8rem 0 0 1.2rem;
            color: #cbd5f5;
        }
        .highfive-btn {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.85), rgba(14, 116, 144, 0.85));
            border: 1px solid rgba(125, 211, 252, 0.6);
            color: #f8fafc;
        }
        .highfive-btn.sent {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.85), rgba(16, 185, 129, 0.85));
            border-color: rgba(110, 231, 183, 0.7);
        }
        @media (max-width: 640px) {
            .profile-header {
                padding: 2rem 1.5rem 1.5rem;
            }
            .profile-grid {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="profile-shell">
    <header class="profile-header">
        <div class="avatar-frame">
            <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?> avatar">
        </div>
        <div class="profile-meta">
            <h1><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p><?php echo htmlspecialchars($faction, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($favoriteActivity, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($localTime !== null): ?>
                <p>Local time: <?php echo htmlspecialchars($localTime, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?>)</p>
            <?php endif; ?>
            <div class="badge-rack">
                <?php foreach ($badges as $badge): ?>
                    <span class="badge"><?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="profile-actions">
            <?php if ($isCurrentUser): ?>
                <a class="holo-button" href="/profile.php">Manage your profile</a>
            <?php else: ?>
                <button type="button" class="holo-button highfive-btn" data-target="<?php echo (int) $profileUser['user_id']; ?>">Send holo-high five</button>
            <?php endif; ?>
            <a class="holo-button" href="/market.php">Back to Holonet Exchange</a>
        </div>
    </header>

    <main class="profile-grid">
        <section class="profile-card">
            <h2>Galactic Identity</h2>
            <ul class="profile-list">
                <li><span>Codename</span><span><?php echo htmlspecialchars($profileUser['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span></li>
                <li><span>Discord</span><span id="discord-handle"><?php echo htmlspecialchars($discordHandle, ENT_QUOTES, 'UTF-8'); ?></span></li>
                <?php if ($joinedDate !== null): ?>
                    <li><span>Joined</span><span><?php echo htmlspecialchars($joinedDate, ENT_QUOTES, 'UTF-8'); ?></span></li>
                <?php endif; ?>
                <?php if ($lastSeen !== null): ?>
                    <li><span>Last seen</span><span><?php echo htmlspecialchars($lastSeen, ENT_QUOTES, 'UTF-8'); ?></span></li>
                <?php endif; ?>
            </ul>
            <button type="button" class="holo-button" id="copy-discord" data-label="<?php echo htmlspecialchars($discordHandle, ENT_QUOTES, 'UTF-8'); ?>">Copy Discord Handle</button>
        </section>

        <section class="profile-card" style="grid-column: 1 / -1;">
            <h2>Holonet Biography</h2>
            <div class="biography"><?php echo $biographyDisplay; ?></div>
        </section>

        <section class="profile-card">
            <h2>Party Up Ideas</h2>
            <ul class="hook-list">
                <?php foreach ($ambientHooks as $hook): ?>
                    <li><?php echo htmlspecialchars($hook, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="profile-card" style="grid-column: 1 / -1;">
            <h2>Marketplace Listings</h2>
            <?php if (!empty($activeListings)): ?>
                <div class="listings-grid">
                    <?php foreach ($activeListings as $listing): ?>
                        <article class="listing-card">
                            <h3><?php echo htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <div class="listing-meta">
                                <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $listing['category'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong><?php echo htmlspecialchars(number_format((float) $listing['price'], 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($listing['currency'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <p><?php echo htmlspecialchars($listing['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <button type="button" class="holo-button copy-contact" data-contact="<?php echo htmlspecialchars($listing['contact_channel'], ENT_QUOTES, 'UTF-8'); ?>">Copy Contact</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-listings">No active listings at the moment. Check back soon for fresh deals.</p>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
    const copyButtons = document.querySelectorAll('[data-contact]');
    copyButtons.forEach(button => {
        button.addEventListener('click', async () => {
            const contact = button.getAttribute('data-contact') || '';
            if (!contact) {
                return;
            }
            try {
                await navigator.clipboard.writeText(contact);
                const originalText = button.textContent;
                button.textContent = 'Contact copied!';
                button.classList.add('sent');
                setTimeout(() => {
                    button.textContent = originalText;
                    button.classList.remove('sent');
                }, 2500);
            } catch (error) {
                alert('Unable to copy contact info.');
            }
        });
    });

    const discordButton = document.getElementById('copy-discord');
    if (discordButton) {
        discordButton.addEventListener('click', async () => {
            const handle = discordButton.getAttribute('data-label') || '';
            if (!handle || handle === 'Not shared') {
                return;
            }
            try {
                await navigator.clipboard.writeText(handle.replace(/^@/, ''));
                const originalText = discordButton.textContent;
                discordButton.textContent = 'Discord copied!';
                discordButton.classList.add('sent');
                setTimeout(() => {
                    discordButton.textContent = originalText;
                    discordButton.classList.remove('sent');
                }, 2500);
            } catch (error) {
                alert('Unable to copy Discord handle.');
            }
        });
    }

    const highfiveButton = document.querySelector('.highfive-btn');
    if (highfiveButton) {
        const targetId = highfiveButton.getAttribute('data-target');
        const storageKey = `holonet-highfive-${targetId}`;
        const hasHighFived = window.localStorage.getItem(storageKey);
        if (hasHighFived) {
            highfiveButton.classList.add('sent');
            highfiveButton.textContent = 'High five sent!';
        }

        highfiveButton.addEventListener('click', () => {
            if (highfiveButton.classList.contains('sent')) {
                return;
            }
            highfiveButton.classList.add('sent');
            highfiveButton.textContent = 'High five sent!';
            window.localStorage.setItem(storageKey, '1');

            const burst = document.createElement('div');
            burst.style.position = 'fixed';
            burst.style.top = '50%';
            burst.style.left = '50%';
            burst.style.transform = 'translate(-50%, -50%)';
            burst.style.pointerEvents = 'none';
            burst.innerHTML = '✨';
            burst.style.fontSize = '2.5rem';
            document.body.appendChild(burst);
            setTimeout(() => burst.remove(), 1200);
        });
    }
</script>
</body>
</html>
