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

$pdo->exec('CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(255) NOT NULL,
    number VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$pdo->exec('CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL,
    default_extension VARCHAR(255) DEFAULT NULL,
    execution_time VARCHAR(5) DEFAULT "21:00",
    notification_email VARCHAR(255) DEFAULT NULL
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
    code VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT api_key, default_extension, notification_email FROM settings WHERE id = 1')->fetch() ?: [];
$apiKey = $settings['api_key'] ?? '';
$defaultExtension = $settings['default_extension'] ?? '';
$notificationEmail = $settings['notification_email'] ?? '';

$behaviors = $pdo->query('SELECT name, code FROM behaviors ORDER BY name')->fetchAll();

$message = '';
$extension = trim($_POST['extension'] ?? $_GET['extension'] ?? $defaultExtension);
$number = trim($_POST['number'] ?? $_GET['number'] ?? ($behaviors[0]['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'call_now') {
        if ($apiKey === '') {
            $message = 'API key no configurada.';
        } elseif ($extension !== '' && $number !== '') {
            $url = "https://vpbx.me/api/originatecall/" . urlencode($extension) . "/" . urlencode($number) . "?timeout=20&autoAnswer=true";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-type: application/json',
                'X-Api-Key: ' . $apiKey
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            $stmt = $pdo->prepare('INSERT INTO calls(extension, number) VALUES (:extension, :number)');
            $stmt->execute([':extension' => $extension, ':number' => $number]);

            $message = $error ? "Error: $error" : "API response: $response";
            if ($notificationEmail !== '') {
                $subject = 'Llamada inmediata ejecutada';
                $body = "Extensión: $extension\nComportamiento: $number\n";
                $body .= $error ? "Error: $error" : "Respuesta: $response";
                @mail($notificationEmail, $subject, $body);
            }
        } else {
            $message = 'Both extension and comportamiento are required.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Llamadas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">PQ Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Historial</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
            <a class="nav-link active" href="calls.php">Llamadas</a>
            <a class="nav-link" href="config.php">Configuración</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

    <h2>Llamada inmediata</h2>
    <form method="post" class="mb-5">
        <input type="hidden" name="action" value="call_now">
        <div class="row mb-3">
            <div class="col">
                <label for="extension" class="form-label">Extensión</label>
                <input type="text" class="form-control" name="extension" id="extension" value="<?= htmlspecialchars($extension) ?>" required>
            </div>
            <div class="col">
                <label for="number" class="form-label">Comportamiento</label>
                <select class="form-select" name="number" id="number" required>
                    <?php foreach ($behaviors as $b): ?>
                        <option value="<?= htmlspecialchars($b['code']) ?>" <?= $b['code'] === $number ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Llamar</button>
    </form>

    <h2>Llamadas realizadas</h2>
    <table class="table table-striped">
        <thead><tr><th>Extensión</th><th>Comportamiento</th><th>Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($pdo->query('SELECT extension, number, created_at FROM calls ORDER BY id DESC') as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['extension']) ?></td>
                <td><?= htmlspecialchars($row['number']) ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
