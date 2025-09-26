<?php
declare(strict_types=1);

/**
 * Marketplace helper functions for the SWG+ Holonet Exchange.
 */
function getMarketCategories(): array
{
    return [
        'crafting' => 'Crafting Components',
        'weapons' => 'Weapons & Upgrades',
        'armor' => 'Armor & Shields',
        'services' => 'Player Services',
        'housing' => 'Housing & Decorations',
        'pets' => 'Companions & Mounts',
        'resources' => 'Resources & Harvesting',
        'consumables' => 'Food, Stims & Buffs',
        'misc' => 'Miscellaneous Finds',
    ];
}

function createMarketListing(mysqli $db, int $sellerId, array $payload): array
{
    $title = trim((string) ($payload['title'] ?? ''));
    $category = strtolower(trim((string) ($payload['category'] ?? '')));
    $price = trim((string) ($payload['price'] ?? '0'));
    $currency = strtoupper(trim((string) ($payload['currency'] ?? 'CREDITS')));
    $contact = trim((string) ($payload['contact'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));

    $categories = getMarketCategories();
    if ($title === '' || mb_strlen($title) > 120) {
        return ['type' => 'error', 'message' => 'Provide a listing title up to 120 characters.'];
    }

    if (!array_key_exists($category, $categories)) {
        return ['type' => 'error', 'message' => 'Choose a valid listing category.'];
    }

    if ($description === '') {
        return ['type' => 'error', 'message' => 'Include a short description of your offering.'];
    }

    if ($contact === '') {
        return ['type' => 'error', 'message' => 'Share a contact method such as Discord or an in-game mail handle.'];
    }

    if (!preg_match('/^(?:\d+)(?:\.\d{1,2})?$/', $price)) {
        return ['type' => 'error', 'message' => 'Enter a valid price such as 5000 or 499.99.'];
    }

    if (mb_strlen($currency) > 12) {
        return ['type' => 'error', 'message' => 'Currency label is too long.'];
    }

    try {
        $floatPrice = (float) $price;

        $stmt = $db->prepare('INSERT INTO market_listings (seller_id, title, category, price, currency, contact_channel, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if ($stmt === false) {
            return ['type' => 'error', 'message' => 'Marketplace is temporarily unavailable.'];
        }

        $stmt->bind_param('issdsss', $sellerId, $title, $category, $floatPrice, $currency, $contact, $description);

        $stmt->execute();

        if ($stmt->affected_rows <= 0) {
            return ['type' => 'error', 'message' => 'Unable to post the listing. Please try again.'];
        }
    } catch (Throwable $exception) {
        return ['type' => 'error', 'message' => 'Unable to post the listing. Please try again.'];
    }

    return ['type' => 'success', 'message' => 'Listing published to the Holonet Exchange!'];
}

function fetchActiveListings(mysqli $db, ?string $search = null, ?string $category = null, int $limit = 40): array
{
    $limit = max(1, min($limit, 100));
    $searchTerm = $search !== null ? trim($search) : '';
    $categoryKey = $category !== null ? strtolower(trim($category)) : '';
    $categories = getMarketCategories();
    $hasCategory = $categoryKey !== '' && array_key_exists($categoryKey, $categories);

    try {
        $baseSelect = 'SELECT ml.id, ml.seller_id, ml.title, ml.category, ml.price, ml.currency, ml.contact_channel, ml.description, ml.status, ml.created_at, ua.username AS seller_name, ua.display_name FROM market_listings ml JOIN user_account ua ON ua.user_id = ml.seller_id';

        if ($searchTerm !== '' && $hasCategory) {
            $stmt = $db->prepare($baseSelect . ' WHERE ml.status = "active" AND (ml.title LIKE ? OR ml.description LIKE ?) AND ml.category = ? ORDER BY ml.created_at DESC LIMIT ?');
            if ($stmt === false) {
                return [];
            }

            $pattern = '%' . $searchTerm . '%';
            $stmt->bind_param('sssi', $pattern, $pattern, $categoryKey, $limit);
        } elseif ($searchTerm !== '') {
            $stmt = $db->prepare($baseSelect . ' WHERE ml.status = "active" AND (ml.title LIKE ? OR ml.description LIKE ?) ORDER BY ml.created_at DESC LIMIT ?');
            if ($stmt === false) {
                return [];
            }

            $pattern = '%' . $searchTerm . '%';
            $stmt->bind_param('ssi', $pattern, $pattern, $limit);
        } elseif ($hasCategory) {
            $stmt = $db->prepare($baseSelect . ' WHERE ml.status = "active" AND ml.category = ? ORDER BY ml.created_at DESC LIMIT ?');
            if ($stmt === false) {
                return [];
            }

            $stmt->bind_param('si', $categoryKey, $limit);
        } else {
            $stmt = $db->prepare($baseSelect . ' WHERE ml.status = "active" ORDER BY ml.created_at DESC LIMIT ?');
            if ($stmt === false) {
                return [];
            }

            $stmt->bind_param('i', $limit);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $listings = [];
        while ($row = $result->fetch_assoc()) {
            $listings[] = $row;
        }

        return $listings;
    } catch (Throwable $exception) {
        return [];
    }
}

function fetchUserListings(mysqli $db, int $sellerId): array
{
    try {
        $stmt = $db->prepare('SELECT id, title, category, price, currency, status, created_at, updated_at FROM market_listings WHERE seller_id = ? ORDER BY created_at DESC LIMIT 50');
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('i', $sellerId);
        $stmt->execute();
        $result = $stmt->get_result();

        $listings = [];
        while ($row = $result->fetch_assoc()) {
            $listings[] = $row;
        }

        return $listings;
    } catch (Throwable $exception) {
        return [];
    }
}

function fetchActiveListingsBySeller(mysqli $db, int $sellerId, int $limit = 20): array
{
    $limit = max(1, min($limit, 50));

    try {
        $stmt = $db->prepare('SELECT id, title, category, price, currency, contact_channel, description, created_at FROM market_listings WHERE seller_id = ? AND status = "active" ORDER BY created_at DESC LIMIT ?');
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('ii', $sellerId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $listings = [];
        while ($row = $result->fetch_assoc()) {
            $listings[] = $row;
        }

        return $listings;
    } catch (Throwable $exception) {
        return [];
    }
}

function closeMarketListing(mysqli $db, int $listingId, int $sellerId): bool
{
    try {
        $stmt = $db->prepare('UPDATE market_listings SET status = \"closed\", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND seller_id = ?');
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('ii', $listingId, $sellerId);
        $stmt->execute();

        return $stmt->affected_rows > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function summarizeMarketplace(mysqli $db): array
{
    $summary = [
        'active' => 0,
        'closed' => 0,
        'traders' => 0,
        'recent' => [],
    ];

    try {
        $result = $db->query('SELECT COUNT(*) AS active_count FROM market_listings WHERE status = \"active\"');
        if ($result !== false) {
            $row = $result->fetch_assoc();
            $summary['active'] = (int) ($row['active_count'] ?? 0);
        }
    } catch (Throwable $exception) {
        $summary['active'] = 0;
    }

    try {
        $result = $db->query('SELECT COUNT(*) AS closed_count FROM market_listings WHERE status = \"closed\"');
        if ($result !== false) {
            $row = $result->fetch_assoc();
            $summary['closed'] = (int) ($row['closed_count'] ?? 0);
        }
    } catch (Throwable $exception) {
        $summary['closed'] = 0;
    }

    try {
        $result = $db->query('SELECT COUNT(DISTINCT seller_id) AS trader_count FROM market_listings');
        if ($result !== false) {
            $row = $result->fetch_assoc();
            $summary['traders'] = (int) ($row['trader_count'] ?? 0);
        }
    } catch (Throwable $exception) {
        $summary['traders'] = 0;
    }

    try {
        $result = $db->query('SELECT ml.title, ml.category, ml.price, ml.currency, ml.created_at, ua.username FROM market_listings ml JOIN user_account ua ON ua.user_id = ml.seller_id ORDER BY ml.created_at DESC LIMIT 5');
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $summary['recent'][] = $row;
            }
        }
    } catch (Throwable $exception) {
        $summary['recent'] = [];
    }

    return $summary;
}
