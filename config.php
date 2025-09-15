<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/schedule_utils.php';
// Configuration page with multi-date calendar using Bootstrap and Flatpickr

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
    telegram_chat_id VARCHAR(255) DEFAULT NULL,
    change_timing VARCHAR(10) DEFAULT "start"
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
} catch (PDOException $e) {
}

$pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {
    // Column may already exist
}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN change_timing VARCHAR(10) DEFAULT 'start'");
} catch (PDOException $e) {
    // Column may already exist
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_bot_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
    // Column may already exist
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_chat_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
}

$settings = $pdo->query('SELECT api_key, default_extension, execution_time, change_timing, telegram_bot_id, telegram_chat_id FROM settings WHERE id = 1')->fetch() ?: [];
$apiKey = $settings['api_key'] ?? '';
$defaultExtension = $settings['default_extension'] ?? '';
$executionTime = $settings['execution_time'] ?? '21:00';
$changeTiming = $settings['change_timing'] ?? 'start';
$telegramBotId = $settings['telegram_bot_id'] ?? '';
$telegramChatId = $settings['telegram_chat_id'] ?? '';

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
        $newBot = trim($_POST['telegram_bot_id'] ?? '');
        $newChat = trim($_POST['telegram_chat_id'] ?? '');
        $newChange = $_POST['change_timing'] ?? 'start';
        $stmt = $pdo->prepare('REPLACE INTO settings (id, api_key, default_extension, execution_time, change_timing, telegram_bot_id, telegram_chat_id) VALUES (1, :api_key, :default_extension, :execution_time, :change_timing, :telegram_bot_id, :telegram_chat_id)');
        $stmt->execute([':api_key' => $newKey, ':default_extension' => $newDefault, ':execution_time' => $newTime, ':change_timing' => $newChange, ':telegram_bot_id' => $newBot, ':telegram_chat_id' => $newChat]);
        $apiKey = $newKey;
        $defaultExtension = $newDefault;
        $executionTime = $newTime;
        $changeTiming = $newChange;
        $telegramBotId = $newBot;
        $telegramChatId = $newChat;
        $message = 'Configuración actualizada.';
        rebuildScheduledCalls($pdo, $defaultExtension, $executionTime, $changeTiming);
    } elseif ($action === 'send_test_message') {
        $bot = trim($_POST['telegram_bot_id'] ?? $telegramBotId);
        $chat = trim($_POST['telegram_chat_id'] ?? $telegramChatId);
        if ($bot !== '' && $chat !== '') {
            $text = urlencode('Mensaje de prueba.');
            $url = "https://api.telegram.org/bot{$bot}/sendMessage?chat_id={$chat}&text={$text}";
            $res = @file_get_contents($url);
            $message = $res !== false ? 'Mensaje de prueba enviado.' : 'Error al enviar el mensaje de prueba.';
        } else {
            $message = 'Bot ID y Chat ID son obligatorios.';
        }
        $telegramBotId = $bot;
        $telegramChatId = $chat;
    } elseif ($action === 'save_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username !== '' && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare('INSERT INTO users(username, password) VALUES (:username, :password)');
                $stmt->execute([':username' => $username, ':password' => $hash]);
                $message = 'Usuario guardado.';
            } catch (PDOException $e) {
                $message = 'Nombre de usuario ya existe.';
            }
        } else {
            $message = 'Nombre de usuario y contraseña son obligatorios.';
        }
    } elseif ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
            $message = 'Usuario eliminado.';
        }
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
$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();
$behaviors = $pdo->query('SELECT id, name, code, color FROM behaviors ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Configuración</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background-color:#003883">
        <div class="container-fluid">
        <img src="proquo_pqmultivoice_blanco.png" alt="Logo" style="max-width: 120px;">
            <div class="navbar-nav">
                <a class="nav-link" href="dashboard.php">Resumen</a>
                <a class="nav-link" href="calendar.php">Calendario</a>
                <a class="nav-link" href="calls.php">Ejec. manualmente</a>
                <a class="nav-link active" href="config.php">Configuración</a>
                <a class="nav-link" href="logout.php">Salir</a>
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
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="telegram_bot_id" class="form-label">ID del bot de Telegram</label>
                    <input type="text" class="form-control" name="telegram_bot_id" id="telegram_bot_id" value="<?= htmlspecialchars($telegramBotId) ?>">
                </div>
                <div class="col-md-6">
                    <label for="telegram_chat_id" class="form-label">ID del chat de Telegram</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="telegram_chat_id" id="telegram_chat_id" value="<?= htmlspecialchars($telegramChatId) ?>">
                        <button class="btn btn-secondary" type="button" id="testMessageBtn">Probar</button>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="execution_time" class="form-label">Hora de ejecución</label>
                    <input type="time" class="form-control" name="execution_time" id="execution_time" value="<?= htmlspecialchars($executionTime) ?>">

                </div>
                <div class="col-md-6">
                    <label for="change_timing" class="form-label">Momento del cambio</label>
                    <select class="form-select" name="change_timing" id="change_timing">
                        <option value="start" <?= $changeTiming === 'start' ? 'selected' : '' ?>>Al inicio del periodo</option>
                        <option value="end" <?= $changeTiming === 'end' ? 'selected' : '' ?>>Al final del periodo</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-success">Guardar configuración</button>
        </form>
        <h2>Usuarios</h2>
        <form method="post" class="mb-3">
            <input type="hidden" name="action" value="save_user">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="username" class="form-label">Usuario</label>
                    <input type="text" class="form-control" name="username" id="username" required>
                </div>
                <div class="col-md-6">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" class="form-control" name="password" id="password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-success">Añadir usuario</button>
        </form>
        <?php if ($users): ?>
            <table class="table table-striped mb-5">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td>
                                <form method="post" style="display:inline-block" onsubmit="return confirm('¿Eliminar usuario?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

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
            <button type="submit" class="btn btn-success"><?= $editBehavior ? 'Actualizar comportamiento' : 'Guardar comportamiento' ?></button>
            <?php if ($editBehavior): ?>
                <a href="config.php" class="btn btn-link">Cancelar</a>
            <?php endif; ?>
        </form>

        <?php if ($behaviors): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Código</th>
                        <th>Color</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
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
        document.getElementById('testMessageBtn').addEventListener('click', function() {
            var form = this.closest('form');
            form.querySelector('input[name="action"]').value = 'send_test_message';
            form.submit();
        });
    </script>
</body>

</html>