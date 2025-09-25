<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('SWG_DB_HOST') ?: 'localhost';
$username = getenv('SWG_DB_USER') ?: 'root';
$password = getenv('SWG_DB_PASSWORD') ?: 'swg';
$dbName = getenv('SWG_DB_NAME') ?: 'swgusers';

$mysqli = new mysqli($host, $username, $password, $dbName);
$mysqli->set_charset('utf8mb4');
