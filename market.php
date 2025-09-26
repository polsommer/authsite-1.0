<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/market_functions.php';

ensureSessionStarted();
requireAuthenticatedUser('/market.php');

$userId = currentUserId();
if ($userId === null) {
    header('Location: /logout.php');
    exit;
}

$user = findUserById($mysqli, (int) $userId);
if (!$user) {
    header('Location: /logout.php');
    exit;
}

$config = require __DIR__ . '/includes/config.php';

$categories = getMarketCategories();
$activeTab = 'browse';
$feedback = null;
$listingDraft = [
    'title' => '',
    'category' => '',
    'price' => '',
    'currency' => 'CREDITS',
    'contact' => '',
    'description' => '',
];
$lastAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $lastAction = $action;
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $feedback = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
    } else {
        if ($action === 'create_listing') {
            $listingDraft = [
                'title' => trim((string) ($_POST['listing_title'] ?? '')),
                'category' => trim((string) ($_POST['listing_category'] ?? '')),
                'price' => trim((string) ($_POST['listing_price'] ?? '')),
                'currency' => trim((string) ($_POST['listing_currency'] ?? 'CREDITS')),
                'contact' => trim((string) ($_POST['listing_contact'] ?? '')),
                'description' => trim((string) ($_POST['listing_description'] ?? '')),
            ];

            $feedback = createMarketListing($mysqli, (int) $userId, $listingDraft);

            if (($feedback['type'] ?? '') === 'success') {
                $listingDraft = [
                    'title' => '',
                    'category' => '',
                    'price' => '',
                    'currency' => 'CREDITS',
                    'contact' => '',
                    'description' => '',
                ];
            }
            $activeTab = 'sell';
        } elseif ($action === 'close_listing') {
            $listingId = (int) ($_POST['listing_id'] ?? 0);
            if ($listingId <= 0) {
                $feedback = ['type' => 'error', 'message' => 'Unable to locate that listing.'];
            } else {
                $closed = closeMarketListing($mysqli, $listingId, (int) $userId);
                $feedback = $closed
                    ? ['type' => 'success', 'message' => 'Listing closed.']
                    : ['type' => 'error', 'message' => 'Unable to close the listing.'];
            }
            $activeTab = 'my_listings';
        }
    }
}

$searchTerm = trim((string) ($_GET['search'] ?? ''));
$categoryFilter = strtolower(trim((string) ($_GET['category'] ?? '')));
if ($categoryFilter !== '' && !array_key_exists($categoryFilter, $categories)) {
    $categoryFilter = '';
}

if (isset($_GET['tab'])) {
    $requestedTab = strtolower((string) $_GET['tab']);
    if (in_array($requestedTab, ['browse', 'sell', 'my_listings'], true)) {
        $activeTab = $requestedTab;
    }
}

if ($feedback !== null && ($feedback['type'] ?? '') === 'error' && $lastAction === 'create_listing') {
    $activeTab = 'sell';
}

$activeListings = fetchActiveListings($mysqli, $searchTerm !== '' ? $searchTerm : null, $categoryFilter !== '' ? $categoryFilter : null, 60);
$myListings = fetchUserListings($mysqli, (int) $userId);
$summary = summarizeMarketplace($mysqli);
$csrfToken = getCsrfToken();

function formatListingDate(?string $value): string
{
    if (!$value) {
        return 'Recently';
    }

    try {
        $dt = new DateTimeImmutable($value . ' UTC');
        return $dt->format('M j, Y');
    } catch (Throwable $exception) {
        return 'Recently';
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($config['site_name'], ENT_QUOTES, 'UTF-8'); ?> | Holonet Exchange</title>
    <link rel="stylesheet" href="/stylesheet.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, rgba(8, 18, 38, 0.95) 0%, rgba(0, 0, 0, 0.96) 75%), url('/images/swgsource.png') no-repeat center/cover fixed;
            color: #f1f5f9;
        }
        .exchange-header {
            background: rgba(15, 23, 42, 0.85);
            padding: 1.5rem 2rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid rgba(94, 234, 212, 0.2);
        }
        .exchange-header h1 {
            margin: 0;
            font-size: 1.8rem;
            color: #5eead4;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .exchange-header .summary {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .summary-card {
            background: rgba(14, 116, 144, 0.18);
            border: 1px solid rgba(94, 234, 212, 0.3);
            border-radius: 12px;
            padding: 0.75rem 1.25rem;
            min-width: 120px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(15, 118, 110, 0.2);
        }
        .summary-card strong {
            display: block;
            font-size: 1.4rem;
            color: #22d3ee;
        }
        .summary-card span {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(226, 232, 240, 0.75);
        }
        .market-container {
            padding: 2rem;
            display: grid;
            gap: 2rem;
        }
        .tabs {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .tabs a {
            text-decoration: none;
            color: rgba(226, 232, 240, 0.75);
            padding: 0.6rem 1rem;
            border-radius: 999px;
            border: 1px solid transparent;
            transition: all 0.2s ease-in-out;
        }
        .tabs a.active {
            background: rgba(45, 212, 191, 0.18);
            border-color: rgba(94, 234, 212, 0.4);
            color: #f8fafc;
            box-shadow: 0 10px 20px rgba(45, 212, 191, 0.2);
        }
        .tabs a:hover {
            border-color: rgba(94, 234, 212, 0.25);
            color: #e2e8f0;
        }
        .panel {
            background: rgba(15, 23, 42, 0.78);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.6);
        }
        .feedback {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }
        .feedback.success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.45);
            color: #bbf7d0;
        }
        .feedback.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #fecaca;
        }
        .listing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.25rem;
        }
        .listing-card {
            background: rgba(12, 74, 110, 0.25);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 14px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            box-shadow: 0 10px 30px rgba(14, 165, 233, 0.18);
        }
        .listing-card h3 {
            margin: 0;
            color: #38bdf8;
            font-size: 1.1rem;
        }
        .listing-card .meta {
            font-size: 0.85rem;
            color: rgba(226, 232, 240, 0.7);
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .listing-card .price {
            font-weight: bold;
            color: #fbbf24;
            letter-spacing: 0.05em;
        }
        .listing-card p {
            margin: 0;
            font-size: 0.9rem;
            color: rgba(226, 232, 240, 0.85);
        }
        .listing-card .contact {
            font-size: 0.85rem;
            color: rgba(94, 234, 212, 0.9);
        }
        form.search-form {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            margin-bottom: 1.5rem;
        }
        form.search-form input[type="text"],
        form.search-form select,
        form.search-form button,
        .panel input[type="text"],
        .panel textarea {
            padding: 0.75rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
            font-size: 0.95rem;
        }
        form.search-form button,
        .panel button {
            background: linear-gradient(120deg, #0ea5e9, #22d3ee);
            border: none;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: transform 0.15s ease-in-out;
        }
        form.search-form button:hover,
        .panel button:hover {
            transform: translateY(-2px);
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .my-listings table {
            width: 100%;
            border-collapse: collapse;
            color: rgba(226, 232, 240, 0.9);
        }
        .my-listings th,
        .my-listings td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }
        .my-listings th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(148, 163, 184, 0.9);
        }
        .status-pill {
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }
        .status-pill.active {
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }
        .status-pill.closed {
            background: rgba(248, 113, 113, 0.2);
            color: #fecaca;
        }
        .recent-deals {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: rgba(226, 232, 240, 0.75);
        }
        .close-button {
            background: linear-gradient(120deg, #f97316, #fb7185);
            border: none;
            color: #fff;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .close-button:hover {
            opacity: 0.9;
        }
        .nav-link {
            color: rgba(94, 234, 212, 0.9);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .nav-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header class="exchange-header">
        <div>
            <h1>Holonet Exchange</h1>
            <p style="margin: 0; font-size: 0.9rem; color: rgba(148, 163, 184, 0.85);">Buy, sell, and barter services across the galaxy.</p>
        </div>
        <div class="summary">
            <div class="summary-card">
                <strong><?php echo number_format((int) ($summary['active'] ?? 0)); ?></strong>
                <span>Active Listings</span>
            </div>
            <div class="summary-card">
                <strong><?php echo number_format((int) ($summary['traders'] ?? 0)); ?></strong>
                <span>Active Traders</span>
            </div>
            <div class="summary-card">
                <strong><?php echo number_format((int) ($summary['closed'] ?? 0)); ?></strong>
                <span>Completed Deals</span>
            </div>
        </div>
        <div>
            <a class="nav-link" href="/dashboard.php">&larr; Return to Command Dashboard</a>
        </div>
    </header>

    <main class="market-container">
        <nav class="tabs">
            <a class="<?php echo $activeTab === 'browse' ? 'active' : ''; ?>" href="?tab=browse">Browse Listings</a>
            <a class="<?php echo $activeTab === 'sell' ? 'active' : ''; ?>" href="?tab=sell">Post a Listing</a>
            <a class="<?php echo $activeTab === 'my_listings' ? 'active' : ''; ?>" href="?tab=my_listings">My Trade Ledger</a>
        </nav>

        <?php if ($feedback !== null): ?>
            <div class="feedback <?php echo htmlspecialchars((string) ($feedback['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars((string) ($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'browse'): ?>
            <section class="panel">
                <form class="search-form" method="get" action="/market.php">
                    <input type="hidden" name="tab" value="browse">
                    <input type="text" name="search" placeholder="Search listings for blasters, buffs, runs..." value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                    <select name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $categoryFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Scan Holonet</button>
                </form>

                <?php if (count($activeListings) === 0): ?>
                    <p style="color: rgba(148, 163, 184, 0.9);">No listings yet. Be the first to advertise your services!</p>
                <?php else: ?>
                    <div class="listing-grid">
                        <?php foreach ($activeListings as $listing): ?>
                            <article class="listing-card">
                                <h3><?php echo htmlspecialchars((string) ($listing['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="meta">
                                    <span><?php echo htmlspecialchars($categories[$listing['category']] ?? ucfirst((string) $listing['category']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="price"><?php echo htmlspecialchars(number_format((float) ($listing['price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string) ($listing['currency'] ?? 'CREDITS'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <p><?php echo nl2br(htmlspecialchars((string) ($listing['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                <p class="contact">Contact: <?php echo htmlspecialchars((string) ($listing['contact_channel'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="meta" style="font-size: 0.8rem;">
                                    <span>Merchant: <?php echo htmlspecialchars((string) ($listing['display_name'] ?: $listing['seller_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>Posted <?php echo htmlspecialchars(formatListingDate($listing['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="panel">
                <h2 style="margin-top: 0; color: #38bdf8;">Recent Deals</h2>
                <?php if (empty($summary['recent'])): ?>
                    <p style="color: rgba(148, 163, 184, 0.9);">Once traders start closing deals, highlights will appear here.</p>
                <?php else: ?>
                    <div class="recent-deals">
                        <?php foreach ($summary['recent'] as $deal): ?>
                            <div>
                                <strong style="color: #fbbf24;"><?php echo htmlspecialchars((string) ($deal['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span style="margin-left: 0.5rem; color: rgba(226, 232, 240, 0.75);">by <?php echo htmlspecialchars((string) ($deal['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <div style="font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($categories[$deal['category']] ?? ucfirst((string) $deal['category']), ENT_QUOTES, 'UTF-8'); ?> •
                                    <?php echo htmlspecialchars(number_format((float) ($deal['price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string) ($deal['currency'] ?? 'CREDITS'), ENT_QUOTES, 'UTF-8'); ?> •
                                    posted <?php echo htmlspecialchars(formatListingDate($deal['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($activeTab === 'sell'): ?>
            <section class="panel">
                <h2 style="margin-top: 0; color: #38bdf8;">Advertise Your Offering</h2>
                <form method="post" action="/market.php?tab=sell">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="create_listing">

                    <label style="display: block; margin-bottom: 0.4rem;">Listing Title</label>
                    <input type="text" name="listing_title" maxlength="120" required value="<?php echo htmlspecialchars($listingDraft['title'], ENT_QUOTES, 'UTF-8'); ?>">

                    <label style="display: block; margin: 1rem 0 0.4rem;">Category</label>
                    <select name="listing_category" required style="width: 100%;">
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $listingDraft['category'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="display: block; margin: 1rem 0 0.4rem;">Price &amp; Currency</label>
                    <div style="display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                        <input type="text" name="listing_price" placeholder="e.g. 50000" required value="<?php echo htmlspecialchars($listingDraft['price'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" name="listing_currency" placeholder="CREDITS" value="<?php echo htmlspecialchars($listingDraft['currency'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <label style="display: block; margin: 1rem 0 0.4rem;">Contact Method</label>
                    <input type="text" name="listing_contact" placeholder="Discord, in-game mail, holocomm..." required value="<?php echo htmlspecialchars($listingDraft['contact'], ENT_QUOTES, 'UTF-8'); ?>">

                    <label style="display: block; margin: 1rem 0 0.4rem;">Description</label>
                    <textarea name="listing_description" maxlength="1500" placeholder="Describe what you are offering, any requirements, and scheduling details." required><?php echo htmlspecialchars($listingDraft['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <button type="submit" style="margin-top: 1.25rem;">Publish Listing</button>
                </form>
            </section>
        <?php else: ?>
            <section class="panel my-listings">
                <h2 style="margin-top: 0; color: #38bdf8;">My Trade Ledger</h2>
                <?php if (count($myListings) === 0): ?>
                    <p style="color: rgba(148, 163, 184, 0.9);">No listings yet. Post your first offering on the Holonet Exchange!</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myListings as $listing): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($listing['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($categories[$listing['category']] ?? ucfirst((string) $listing['category']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float) ($listing['price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string) ($listing['currency'] ?? 'CREDITS'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="status-pill <?php echo htmlspecialchars((string) ($listing['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars(ucfirst((string) ($listing['status'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatListingDate($listing['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if (($listing['status'] ?? '') === 'active'): ?>
                                                <form method="post" action="/market.php?tab=my_listings" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="close_listing">
                                                    <input type="hidden" name="listing_id" value="<?php echo (int) ($listing['id'] ?? 0); ?>">
                                                    <button type="submit" class="close-button">Close</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: rgba(148, 163, 184, 0.7);">Closed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
