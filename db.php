<?php
$timezone = getenv('APP_TZ') ?: 'Europe/Madrid';
date_default_timezone_set($timezone);
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'pqmultivoice';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'terminal';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $user, $pass, $options);
$tz = new DateTimeZone($timezone);
$offset = (new DateTime('now', $tz))->format('P');
$pdo->exec("SET time_zone = '$offset'");
