<?php
require __DIR__ . '/db.php';

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

$settings = $pdo->query('SELECT api_key, default_extension, telegram_bot_id, telegram_chat_id FROM settings WHERE id = 1')->fetch() ?: [];
$apiKey = $settings['api_key'] ?? '';
$defaultExtension = $settings['default_extension'] ?? '';
$telegramBotId = $settings['telegram_bot_id'] ?? '';
$telegramChatId = $settings['telegram_chat_id'] ?? '';

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
            if ($telegramBotId && $telegramChatId) {
                $behStmt = $pdo->prepare('SELECT name FROM behaviors WHERE code = :code');
                $behStmt->execute([':code' => $number]);
                $behaviorName = $behStmt->fetchColumn();
                if ($behaviorName) {
                    $text = urlencode("Comportamiento {$behaviorName} activado en la centralita");
                    $url = "https://api.telegram.org/bot{$telegramBotId}/sendMessage?chat_id={$telegramChatId}&text={$text}";
                    @file_get_contents($url);
                }
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
<nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background-color:#003883">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Comportamientos Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Resumen</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
            <a class="nav-link active" href="calls.php">Ejec. manualmente</a>
            <a class="nav-link" href="config.php">Configuración</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

    <h2>Cambiar comportamiento</h2>
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
        <button type="submit" class="btn btn-success">Cambiar</button>
    </form>

    <h2>Códigos lanzados</h2>
    <table class="table table-striped">
        <thead><tr><th>Extensión</th><th>Comportamiento</th><th>Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($pdo->query('SELECT c.extension, c.number, b.name AS behavior_name, c.created_at FROM calls c LEFT JOIN behaviors b ON c.number = b.code ORDER BY c.id DESC') as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['extension']) ?></td>
                <td><?= htmlspecialchars($row['behavior_name'] ?? '') ?> (<?= htmlspecialchars($row['number']) ?>)</td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
