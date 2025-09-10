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

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    default_extension VARCHAR(255) DEFAULT NULL,
    execution_time VARCHAR(5) DEFAULT "21:00",
    notification_email VARCHAR(255) DEFAULT NULL,
    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT DEFAULT 587,
    smtp_user VARCHAR(255) DEFAULT NULL,
    smtp_pass VARCHAR(255) DEFAULT NULL,
    smtp_secure VARCHAR(10) DEFAULT NULL
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
    $pdo->exec('ALTER TABLE settings ADD COLUMN notification_email VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
}
$pdo->exec('CREATE TABLE IF NOT EXISTS behaviors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT "#0d6efd"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec("ALTER TABLE behaviors ADD COLUMN color VARCHAR(7) DEFAULT '#0d6efd'");
} catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_host VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_port INT DEFAULT 587'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_user VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_pass VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_secure VARCHAR(10) DEFAULT NULL'); } catch (PDOException $e) {}
$settings = $pdo->query('SELECT api_key, notification_email, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure FROM settings WHERE id = 1')->fetch();
$apiKey = $settings['api_key'] ?? '';
$notificationEmail = $settings['notification_email'] ?? '';
$smtpHost = $settings['smtp_host'] ?? '';
$smtpPort = $settings['smtp_port'] ?? 587;
$smtpUser = $settings['smtp_user'] ?? '';
$smtpPass = $settings['smtp_pass'] ?? '';
$smtpSecure = $settings['smtp_secure'] ?? '';

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

    if ($notificationEmail && $smtpHost && $smtpUser) {
        $subject = "Llamada programada ejecutada";
        $body = "Extensión: {$call['extension']}\nComportamiento: {$call['number']}\n";
        $body .= $error ? "Error: $error" : "Respuesta: $response";
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            if ($smtpSecure) { $mail->SMTPSecure = $smtpSecure; }
            $mail->Port = $smtpPort ?: 587;
            $mail->setFrom($smtpUser);
            $mail->addAddress($notificationEmail);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
        } catch (Exception $e) {
            echo 'Email error: ' . $mail->ErrorInfo . "\n";
        }
    }
}

