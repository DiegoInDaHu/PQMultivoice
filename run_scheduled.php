<?php
require __DIR__ . '/db.php';
// Execute scheduled calls due at the current time

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
    default_extension VARCHAR(255) DEFAULT NULL,
    execution_time VARCHAR(5) DEFAULT "21:00",
    telegram_bot_id VARCHAR(255) DEFAULT NULL,
    telegram_chat_id VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN default_extension VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_bot_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_chat_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {}
$pdo->exec('CREATE TABLE IF NOT EXISTS behaviors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT "#0d6efd"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec("ALTER TABLE behaviors ADD COLUMN color VARCHAR(7) DEFAULT '#0d6efd'");
} catch (PDOException $e) {}
$settings = $pdo->query('SELECT api_key, telegram_bot_id, telegram_chat_id FROM settings WHERE id = 1')->fetch();
$apiKey = $settings['api_key'] ?? '';
$telegramBotId = $settings['telegram_bot_id'] ?? '';
$telegramChatId = $settings['telegram_chat_id'] ?? '';

if (!$apiKey) {
    echo "API key not configured\n";
    exit;
}

$stmt = $pdo->prepare('SELECT id, extension, number, scheduled_at FROM scheduled_calls WHERE executed_at IS NULL AND scheduled_at <= NOW() ORDER BY scheduled_at');
$stmt->execute();
$calls = $stmt->fetchAll();

foreach ($calls as $call) {
    $lastStmt = $pdo->prepare('SELECT number FROM scheduled_calls WHERE extension = :ext AND executed_at IS NOT NULL AND scheduled_at < :sched ORDER BY scheduled_at DESC LIMIT 1');
    $lastStmt->execute([':ext' => $call['extension'], ':sched' => $call['scheduled_at']]);
    $lastNumber = $lastStmt->fetchColumn();
    if ($lastNumber !== false && $lastNumber === $call['number']) {
        $pdo->prepare('UPDATE scheduled_calls SET executed_at = NOW() WHERE id = :id')->execute([':id' => $call['id']]);
        echo "Skipping {$call['id']}: repeated behavior\n";
        continue;
    }

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

    if ($telegramBotId && $telegramChatId) {
        $behStmt = $pdo->prepare('SELECT name FROM behaviors WHERE code = :code');
        $behStmt->execute([':code' => $call['number']]);
        $behaviorName = $behStmt->fetchColumn();
        if ($behaviorName) {
            $text = urlencode("Comportamiento {$behaviorName} activado en la centralita");
            $url = "https://api.telegram.org/bot{$telegramBotId}/sendMessage?chat_id={$telegramChatId}&text={$text}";
            @file_get_contents($url);
        }
    }
}

