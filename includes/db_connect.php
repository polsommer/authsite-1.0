<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('SWG_DB_HOST') ?: 'mysql73.unoeuro.com';
$username = getenv('SWG_DB_USER') ?: 'swgplus_com';
$password = getenv('SWG_DB_PASSWORD') ?: '';
$dbName = getenv('SWG_DB_NAME') ?: 'swgplus_com_db';

$mysqli = new mysqli($host, $username, $password, $dbName);
$mysqli->set_charset('utf8mb4');
