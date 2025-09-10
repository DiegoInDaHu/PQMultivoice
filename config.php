<?php
$timezone = getenv('APP_TZ') ?: 'Europe/Madrid';
date_default_timezone_set($timezone);
// Configuration page with multi-date calendar using Bootstrap and Flatpickr

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
    // Column may already exist
}

$pdo->exec('CREATE TABLE IF NOT EXISTS codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT api_key, default_extension FROM settings WHERE id = 1')->fetch() ?: [];
$apiKey = $settings['api_key'] ?? '';
$defaultExtension = $settings['default_extension'] ?? '';

$message = '';

$editId = intval($_GET['edit_id'] ?? 0);
$editCode = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT id, name, code FROM codes WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $editCode = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $newKey = trim($_POST['api_key'] ?? '');
        $newDefault = trim($_POST['default_extension'] ?? '');
        $stmt = $pdo->prepare('REPLACE INTO settings (id, api_key, default_extension) VALUES (1, :api_key, :default_extension)');
        $stmt->execute([':api_key' => $newKey, ':default_extension' => $newDefault]);
        $apiKey = $newKey;
        $defaultExtension = $newDefault;
        $message = 'Configuración actualizada.';
    } elseif ($action === 'save_code') {
        $codeId = intval($_POST['code_id'] ?? 0);
        $codeName = trim($_POST['code_name'] ?? '');
        $codeValue = trim($_POST['code_value'] ?? '');
        if ($codeName !== '' && $codeValue !== '') {
            if ($codeId) {
                $stmt = $pdo->prepare('SELECT code FROM codes WHERE id = :id');
                $stmt->execute([':id' => $codeId]);
                $oldCode = $stmt->fetchColumn();
                $stmt = $pdo->prepare('UPDATE codes SET name = :name, code = :code WHERE id = :id');
                $stmt->execute([':name' => $codeName, ':code' => $codeValue, ':id' => $codeId]);
                if ($oldCode && $oldCode !== $codeValue) {
                    $stmt = $pdo->prepare('UPDATE scheduled_calls SET number = :new WHERE number = :old');
                    $stmt->execute([':new' => $codeValue, ':old' => $oldCode]);
                }
                $message = 'Código actualizado.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO codes(name, code) VALUES (:name, :code)');
                $stmt->execute([':name' => $codeName, ':code' => $codeValue]);
                $message = 'Código guardado.';
            }
        } else {
            $message = 'Nombre y código son obligatorios.';
        }
    } elseif ($action === 'delete_code') {
        $codeId = intval($_POST['code_id'] ?? 0);
        if ($codeId) {
            $stmt = $pdo->prepare('SELECT code FROM codes WHERE id = :id');
            $stmt->execute([':id' => $codeId]);
            $codeValue = $stmt->fetchColumn();
            if ($codeValue !== false) {
                $pdo->prepare('DELETE FROM codes WHERE id = :id')->execute([':id' => $codeId]);
                $pdo->prepare('DELETE FROM scheduled_calls WHERE number = :code')->execute([':code' => $codeValue]);
                $message = 'Código eliminado.';
            }
        }
    }
}

$codes = $pdo->query('SELECT id, name, code FROM codes ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Historial</a>
            <a class="nav-link" href="calls.php">Llamadas</a>
            <a class="nav-link active" href="config.php">Configuración</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <h2>Configuración general</h2>
    <form method="post" class="mb-5">
        <input type="hidden" name="action" value="save_settings">
        <div class="mb-3">
            <label for="api_key" class="form-label">API Key</label>
            <input type="text" class="form-control" name="api_key" id="api_key" value="<?= htmlspecialchars($apiKey) ?>">
        </div>
        <div class="mb-3">
            <label for="default_extension" class="form-label">Extensión por defecto</label>
            <input type="text" class="form-control" name="default_extension" id="default_extension" value="<?= htmlspecialchars($defaultExtension) ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Guardar configuración</button>
    </form>

    <h2>Códigos</h2>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="save_code">
        <?php if ($editCode): ?>
            <input type="hidden" name="code_id" value="<?= $editCode['id'] ?>">
        <?php endif; ?>
        <div class="row mb-3">
            <div class="col">
                <label for="code_name" class="form-label">Nombre</label>
                <input type="text" class="form-control" name="code_name" id="code_name" value="<?= htmlspecialchars($editCode['name'] ?? '') ?>" required>
            </div>
            <div class="col">
                <label for="code_value" class="form-label">Código</label>
                <input type="text" class="form-control" name="code_value" id="code_value" value="<?= htmlspecialchars($editCode['code'] ?? '') ?>" required>
            </div>
        </div>
        <button type="submit" class="btn btn-secondary"><?= $editCode ? 'Actualizar código' : 'Guardar código' ?></button>
        <?php if ($editCode): ?>
            <a href="config.php" class="btn btn-link">Cancelar</a>
        <?php endif; ?>
    </form>

    <?php if ($codes): ?>
    <table class="table table-striped">
        <thead><tr><th>Nombre</th><th>Código</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($codes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['code']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="config.php?edit_id=<?= $c['id'] ?>">Editar</a>
                    <form method="post" style="display:inline-block" onsubmit="return confirm('¿Eliminar código?');">
                        <input type="hidden" name="action" value="delete_code">
                        <input type="hidden" name="code_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
