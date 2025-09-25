<?php
declare(strict_types=1);

function checkServiceStatus(string $host, int $port, int $timeoutSeconds): bool
{
    $connection = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }

    return false;
}

function resolveServerStatuses(string $host, int $timeoutSeconds, array $ports): array
{
    $statuses = [];
    foreach ($ports as $key => $port) {
        $statuses[$key] = checkServiceStatus($host, (int) $port, $timeoutSeconds);
    }

    return $statuses;
}
