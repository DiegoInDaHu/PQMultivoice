<?php
// Execute scheduled calls due at the current time

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'calls';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $user, $pass, $options);

$pdo->exec('CREATE TABLE IF NOT EXISTS scheduled_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    number VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$stmt = $pdo->prepare('SELECT id, extension, number FROM scheduled_calls WHERE executed_at IS NULL AND scheduled_at <= NOW()');
$stmt->execute();
$calls = $stmt->fetchAll();

foreach ($calls as $call) {
    $url = "https://vpbx.me/api/originatecall/" . urlencode($call['extension']) . "/" . urlencode($call['number']) . "?timeout=20&autoAnswer=true";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

