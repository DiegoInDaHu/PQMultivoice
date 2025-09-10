<?php
// Allow timezone configuration via APP_TZ environment variable (defaults to Europe/Madrid)
$timezone = getenv('APP_TZ') ?: 'Europe/Madrid';
date_default_timezone_set($timezone);
// Execute scheduled calls due at the current time

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

$pdo->exec('CREATE TABLE IF NOT EXISTS scheduled_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    number VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$pdo->exec('CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL,
    default_extension VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN default_extension VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {
}

$apiKey = $pdo->query('SELECT api_key FROM settings WHERE id = 1')->fetchColumn();

if (!$apiKey) {
    echo "API key not configured\n";
    exit;
}

$stmt = $pdo->prepare('SELECT id, extension, number FROM scheduled_calls WHERE executed_at IS NULL AND scheduled_at <= NOW()');
$stmt->execute();
$calls = $stmt->fetchAll();

foreach ($calls as $call) {
    $url = "https://vpbx.me/api/originatecall/" . urlencode($call['extension']) . "/" . urlencode($call['number']) . "?timeout=20&autoAnswer=true";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-type: application/json',
        'X-Api-Key: ' . $apiKey
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $update = $pdo->prepare('UPDATE scheduled_calls SET executed_at = NOW() WHERE id = :id');
    $update->execute([':id' => $call['id']]);

    if ($error) {
        echo "Error for {$call['id']}: $error\n";
    } else {
        echo "Executed {$call['id']}: $response\n";
    }
}

