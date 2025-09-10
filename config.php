<?php
$timezone = getenv('APP_TZ') ?: 'Europe/Madrid';
date_default_timezone_set($timezone);
// Configuration page with multi-date calendar using Bootstrap and Flatpickr

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
    // Column may already exist
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

try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {
    // Column may already exist
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN notification_email VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
    // Column may already exist
}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_host VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_port INT DEFAULT 587'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_user VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_pass VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $pdo->exec('ALTER TABLE settings ADD COLUMN smtp_secure VARCHAR(10) DEFAULT NULL'); } catch (PDOException $e) {}

$settings = $pdo->query('SELECT api_key, default_extension, execution_time, notification_email, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure FROM settings WHERE id = 1')->fetch() ?: [];
$apiKey = $settings['api_key'] ?? '';
$defaultExtension = $settings['default_extension'] ?? '';
$executionTime = $settings['execution_time'] ?? '21:00';
$notificationEmail = $settings['notification_email'] ?? '';
$smtpHost = $settings['smtp_host'] ?? '';
$smtpPort = $settings['smtp_port'] ?? 587;
$smtpUser = $settings['smtp_user'] ?? '';
$smtpPass = $settings['smtp_pass'] ?? '';
$smtpSecure = $settings['smtp_secure'] ?? '';

$message = '';

$editId = intval($_GET['edit_id'] ?? 0);
$editBehavior = null;
if ($editId) {
$stmt = $pdo->prepare('SELECT id, name, code, color FROM behaviors WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $editBehavior = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $newKey = trim($_POST['api_key'] ?? '');
        $newDefault = trim($_POST['default_extension'] ?? '');
        $newTime = trim($_POST['execution_time'] ?? '21:00');
        $newEmail = trim($_POST['notification_email'] ?? '');
        $newSmtpHost = trim($_POST['smtp_host'] ?? '');
        $newSmtpPort = intval($_POST['smtp_port'] ?? 587);
        $newSmtpUser = trim($_POST['smtp_user'] ?? '');
        $newSmtpPass = trim($_POST['smtp_pass'] ?? '');
        $newSmtpSecure = trim($_POST['smtp_secure'] ?? '');
        $stmt = $pdo->prepare('REPLACE INTO settings (id, api_key, default_extension, execution_time, notification_email, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure) VALUES (1, :api_key, :default_extension, :execution_time, :notification_email, :smtp_host, :smtp_port, :smtp_user, :smtp_pass, :smtp_secure)');
        $stmt->execute([':api_key' => $newKey, ':default_extension' => $newDefault, ':execution_time' => $newTime, ':notification_email' => $newEmail, ':smtp_host' => $newSmtpHost, ':smtp_port' => $newSmtpPort, ':smtp_user' => $newSmtpUser, ':smtp_pass' => $newSmtpPass, ':smtp_secure' => $newSmtpSecure]);
        $apiKey = $newKey;
        $defaultExtension = $newDefault;
        $executionTime = $newTime;
        $notificationEmail = $newEmail;
        $smtpHost = $newSmtpHost;
        $smtpPort = $newSmtpPort;
        $smtpUser = $newSmtpUser;
        $smtpPass = $newSmtpPass;
        $smtpSecure = $newSmtpSecure;
        $message = 'Configuración actualizada.';
    } elseif ($action === 'send_test_email') {
        $email = trim($_POST['notification_email'] ?? '');
        $smtpHost = trim($_POST['smtp_host'] ?? $smtpHost);
        $smtpPort = intval($_POST['smtp_port'] ?? $smtpPort);
        $smtpUser = trim($_POST['smtp_user'] ?? $smtpUser);
        $smtpPass = trim($_POST['smtp_pass'] ?? $smtpPass);
        $smtpSecure = trim($_POST['smtp_secure'] ?? $smtpSecure);
        if ($email !== '' && $smtpHost !== '' && $smtpUser !== '') {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                if ($smtpSecure !== '') { $mail->SMTPSecure = $smtpSecure; }
                $mail->Port = $smtpPort ?: 587;
                $mail->setFrom($smtpUser);
                $mail->addAddress($email);
                $mail->Subject = 'Prueba de notificación';
                $mail->Body = 'Este es un mensaje de prueba.';
                $mail->send();
                $message = 'Correo de prueba enviado.';
            } catch (Exception $e) {
                $message = 'Error al enviar el correo de prueba: ' . $mail->ErrorInfo;
            }
        } else {
            $message = 'Configuración SMTP incompleta o correo no válido.';
        }
        $notificationEmail = $email;
    } elseif ($action === 'save_behavior') {
        $behaviorId = intval($_POST['behavior_id'] ?? 0);
        $behaviorName = trim($_POST['behavior_name'] ?? '');
        $behaviorCode = trim($_POST['behavior_code'] ?? '');
        $behaviorColor = trim($_POST['behavior_color'] ?? '#0d6efd');
        if ($behaviorName !== '' && $behaviorCode !== '' && $behaviorColor !== '') {
            if ($behaviorId) {
                $stmt = $pdo->prepare('SELECT code FROM behaviors WHERE id = :id');
                $stmt->execute([':id' => $behaviorId]);
                $oldCode = $stmt->fetchColumn();
                $stmt = $pdo->prepare('UPDATE behaviors SET name = :name, code = :code, color = :color WHERE id = :id');
                $stmt->execute([':name' => $behaviorName, ':code' => $behaviorCode, ':color' => $behaviorColor, ':id' => $behaviorId]);
                if ($oldCode && $oldCode !== $behaviorCode) {
                    $stmt = $pdo->prepare('UPDATE scheduled_calls SET number = :new WHERE number = :old');
                    $stmt->execute([':new' => $behaviorCode, ':old' => $oldCode]);
                }
                $message = 'Comportamiento actualizado.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO behaviors(name, code, color) VALUES (:name, :code, :color)');
                $stmt->execute([':name' => $behaviorName, ':code' => $behaviorCode, ':color' => $behaviorColor]);
                $message = 'Comportamiento guardado.';
            }
        } else {
            $message = 'Nombre, código y color son obligatorios.';
        }
    } elseif ($action === 'delete_behavior') {
        $behaviorId = intval($_POST['behavior_id'] ?? 0);
        if ($behaviorId) {
        $stmt = $pdo->prepare('SELECT code FROM behaviors WHERE id = :id');
            $stmt->execute([':id' => $behaviorId]);
            $behaviorCode = $stmt->fetchColumn();
            if ($behaviorCode !== false) {
                $pdo->prepare('DELETE FROM behaviors WHERE id = :id')->execute([':id' => $behaviorId]);
                $pdo->prepare('DELETE FROM scheduled_calls WHERE number = :code')->execute([':code' => $behaviorCode]);
                $message = 'Comportamiento eliminado.';
            }
        }
    }
}
$behaviors = $pdo->query('SELECT id, name, code, color FROM behaviors ORDER BY name')->fetchAll();
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
        <a class="navbar-brand" href="#">PQ Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Resumen</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
            <a class="nav-link" href="calls.php">Llamadas</a>
            <a class="nav-link active" href="config.php">Configuración</a>
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
        <div class="mb-3">
            <label for="notification_email" class="form-label">Correo de notificación</label>
            <div class="input-group">
                <input type="email" class="form-control" name="notification_email" id="notification_email" value="<?= htmlspecialchars($notificationEmail) ?>">
                <button class="btn btn-outline-secondary" type="button" id="testEmailBtn">Probar</button>
            </div>
        </div>
        <div class="mb-3">
            <label for="execution_time" class="form-label">Hora de ejecución</label>
            <input type="time" class="form-control" name="execution_time" id="execution_time" value="<?= htmlspecialchars($executionTime) ?>">
        </div>
        <div class="mb-3">
            <label for="smtp_host" class="form-label">SMTP Host</label>
            <input type="text" class="form-control" name="smtp_host" id="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>">
        </div>
        <div class="mb-3">
            <label for="smtp_port" class="form-label">SMTP Puerto</label>
            <input type="number" class="form-control" name="smtp_port" id="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>">
        </div>
        <div class="mb-3">
            <label for="smtp_user" class="form-label">SMTP Usuario</label>
            <input type="text" class="form-control" name="smtp_user" id="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>">
        </div>
        <div class="mb-3">
            <label for="smtp_pass" class="form-label">SMTP Contraseña</label>
            <input type="password" class="form-control" name="smtp_pass" id="smtp_pass" value="<?= htmlspecialchars($smtpPass) ?>">
        </div>
        <div class="mb-3">
            <label for="smtp_secure" class="form-label">SMTP Seguridad</label>
            <select class="form-select" name="smtp_secure" id="smtp_secure">
                <option value="" <?= $smtpSecure=='' ? 'selected' : '' ?>>Ninguna</option>
                <option value="tls" <?= $smtpSecure=='tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= $smtpSecure=='ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">Guardar configuración</button>
    </form>

    <h2>Comportamientos</h2>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="save_behavior">
        <?php if ($editBehavior): ?>
            <input type="hidden" name="behavior_id" value="<?= $editBehavior['id'] ?>">
        <?php endif; ?>
        <div class="row mb-3">
            <div class="col">
                <label for="behavior_name" class="form-label">Nombre</label>
                <input type="text" class="form-control" name="behavior_name" id="behavior_name" value="<?= htmlspecialchars($editBehavior['name'] ?? '') ?>" required>
            </div>
            <div class="col">
                <label for="behavior_code" class="form-label">Código</label>
                <input type="text" class="form-control" name="behavior_code" id="behavior_code" value="<?= htmlspecialchars($editBehavior['code'] ?? '') ?>" required>
            </div>
            <div class="col">
                <label for="behavior_color" class="form-label">Color</label>
                <input type="color" class="form-control form-control-color" name="behavior_color" id="behavior_color" value="<?= htmlspecialchars($editBehavior['color'] ?? '#0d6efd') ?>" required>
            </div>
        </div>
        <button type="submit" class="btn btn-secondary"><?= $editBehavior ? 'Actualizar comportamiento' : 'Guardar comportamiento' ?></button>
        <?php if ($editBehavior): ?>
            <a href="config.php" class="btn btn-link">Cancelar</a>
        <?php endif; ?>
    </form>

    <?php if ($behaviors): ?>
    <table class="table table-striped">
        <thead><tr><th>Nombre</th><th>Código</th><th>Color</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($behaviors as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['code']) ?></td>
                <td><span class="badge" style="background-color: <?= htmlspecialchars($c['color']) ?>;">&nbsp;&nbsp;&nbsp;</span></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="config.php?edit_id=<?= $c['id'] ?>">Editar</a>
                    <form method="post" style="display:inline-block" onsubmit="return confirm('¿Eliminar comportamiento?');">
                        <input type="hidden" name="action" value="delete_behavior">
                        <input type="hidden" name="behavior_id" value="<?= $c['id'] ?>">
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
<script>
document.getElementById('testEmailBtn').addEventListener('click', function () {
    var form = this.closest('form');
    form.querySelector('input[name="action"]').value = 'send_test_email';
    form.submit();
});
</script>
</body>
</html>
