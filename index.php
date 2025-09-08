<?php
// Simple web interface to initiate calls via Siptize API
// Stores extension and dialed number in a MySQL database
// Allows scheduling calls for future execution

// Connect to MySQL database using environment variables or defaults
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

// Create table if it doesn't exist
$pdo->exec('CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    number VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$pdo->exec('CREATE TABLE IF NOT EXISTS scheduled_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    number VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $extension = trim($_POST['extension'] ?? '');
    $number = trim($_POST['number'] ?? '');

    if ($action === 'call_now') {
        if ($extension !== '' && $number !== '') {
            // Call Siptize API
            $url = "https://vpbx.me/api/originatecall/" . urlencode($extension) . "/" . urlencode($number) . "?timeout=20&autoAnswer=true";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            // Store call data
            $stmt = $pdo->prepare('INSERT INTO calls(extension, number) VALUES (:extension, :number)');
            $stmt->execute([':extension' => $extension, ':number' => $number]);

            $message = $error ? "Error: $error" : "API response: $response";
        } else {
            $message = 'Both extension and code are required.';
        }
    } elseif ($action === 'schedule') {
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        if ($extension !== '' && $number !== '' && $scheduled_at !== '') {
            $stmt = $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:extension, :number, :scheduled_at)');
            $stmt->execute([':extension' => $extension, ':number' => $number, ':scheduled_at' => $scheduled_at]);
            $message = 'Call scheduled.';
        } else {
            $message = 'All fields are required to schedule a call.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Marcación Siptize</title>
</head>
<body>
    <h1>Marcación Siptize</h1>
    <?php if ($message): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <h2>Llamada inmediata</h2>
    <form method="post">
        <input type="hidden" name="action" value="call_now">
        <label>
            Extensión:
            <input type="text" name="extension" required>
        </label>
        <br>
        <label>
            Código:
            <input type="text" name="number" required>
        </label>
        <br>
        <button type="submit">Llamar</button>
    </form>

    <h2>Programar llamada</h2>
    <form method="post">
        <input type="hidden" name="action" value="schedule">
        <label>
            Extensión:
            <input type="text" name="extension" required>
        </label>
        <br>
        <label>
            Código:
            <input type="text" name="number" required>
        </label>
        <br>
        <label>
            Fecha y hora:
            <input type="datetime-local" name="scheduled_at" required>
        </label>
        <br>
        <button type="submit">Programar</button>
    </form>

    <h2>Historial</h2>
    <table border="1">
        <tr><th>Extensión</th><th>Código</th><th>Fecha</th></tr>
        <?php
        foreach ($pdo->query('SELECT extension, number, created_at FROM calls ORDER BY id DESC') as $row) {
            echo '<tr><td>' . htmlspecialchars($row['extension']) . '</td><td>' . htmlspecialchars($row['number']) . '</td><td>' . htmlspecialchars($row['created_at']) . '</td></tr>';
        }
        ?>
    </table>

    <h2>Llamadas programadas</h2>
    <table border="1">
        <tr><th>Extensión</th><th>Código</th><th>Programada</th><th>Ejecutada</th></tr>
        <?php
        foreach ($pdo->query('SELECT extension, number, scheduled_at, executed_at FROM scheduled_calls ORDER BY id DESC') as $row) {
            echo '<tr><td>' . htmlspecialchars($row['extension']) . '</td><td>' . htmlspecialchars($row['number']) . '</td><td>' . htmlspecialchars($row['scheduled_at']) . '</td><td>' . htmlspecialchars($row['executed_at'] ?? '-') . '</td></tr>';
        }
        ?>
    </table>
</body>
</html>
