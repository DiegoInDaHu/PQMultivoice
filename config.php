<?php
// Configuration page with multi-date calendar using Bootstrap and Flatpickr

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'multivoice';
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

// Ensure required tables exist
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

$pdo->exec('CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$apiKey = $pdo->query('SELECT api_key FROM settings WHERE id = 1')->fetchColumn() ?: '';

$message = '';
$extension = trim($_POST['extension'] ?? $_GET['extension'] ?? '');
$number = trim($_POST['number'] ?? $_GET['number'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_api_key') {
        $newKey = trim($_POST['api_key'] ?? '');
        if ($newKey !== '') {
            $stmt = $pdo->prepare('REPLACE INTO settings (id, api_key) VALUES (1, :api_key)');
            $stmt->execute([':api_key' => $newKey]);
            $apiKey = $newKey;
            $message = 'API key actualizada.';
        } else {
            $message = 'La API key es obligatoria.';
        }
    } elseif ($action === 'call_now') {
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
        } else {
            $message = 'Both extension and code are required.';
        }
    } elseif ($action === 'save_dates') {
        $dates = $_POST['dates'] ?? '';
        if ($extension !== '' && $number !== '') {
            $pdo->prepare('DELETE FROM scheduled_calls WHERE extension = :extension AND number = :number AND executed_at IS NULL')
                ->execute([':extension' => $extension, ':number' => $number]);

            if ($dates !== '') {
                $dateArray = array_filter(array_map('trim', explode(',', $dates)));
                $insert = $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:extension, :number, :scheduled_at)');
                foreach ($dateArray as $d) {
                    $insert->execute([
                        ':extension' => $extension,
                        ':number' => $number,
                        ':scheduled_at' => $d . ' 00:00:00'
                    ]);
                }
                $message = 'Fechas actualizadas.';
            } else {
                $message = 'Fechas eliminadas.';
            }
        } else {
            $message = 'Todos los campos son obligatorios.';
        }
    }
}

$selectedDates = [];
if ($extension !== '' && $number !== '') {
    $stmt = $pdo->prepare('SELECT DATE(scheduled_at) AS d FROM scheduled_calls WHERE extension = :extension AND number = :number AND executed_at IS NULL');
    $stmt->execute([':extension' => $extension, ':number' => $number]);
    $selectedDates = array_column($stmt->fetchAll(), 'd');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Historial</a>
            <a class="nav-link active" href="config.php">Configuración</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <h2>API Key</h2>
    <form method="post" class="mb-5">
        <input type="hidden" name="action" value="save_api_key">
        <div class="mb-3">
            <label for="api_key" class="form-label">API Key</label>
            <input type="text" class="form-control" name="api_key" id="api_key" value="<?= htmlspecialchars($apiKey) ?>" required>
        </div>
        <button type="submit" class="btn btn-secondary">Guardar API Key</button>
    </form>

    <h2>Llamada inmediata</h2>
    <form method="post" class="mb-5">
        <input type="hidden" name="action" value="call_now">
        <div class="row mb-3">
            <div class="col">
                <label for="extension" class="form-label">Extensión</label>
                <input type="text" class="form-control" name="extension" id="extension" value="<?= htmlspecialchars($extension) ?>" required>
            </div>
            <div class="col">
                <label for="number" class="form-label">Código</label>
                <input type="text" class="form-control" name="number" id="number" value="<?= htmlspecialchars($number) ?>" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Llamar</button>
    </form>

    <h2>Configurar calendario</h2>
    <form method="post">
        <input type="hidden" name="action" value="save_dates">
        <div class="row mb-3">
            <div class="col">
                <label for="extension2" class="form-label">Extensión</label>
                <input type="text" class="form-control" name="extension" id="extension2" value="<?= htmlspecialchars($extension) ?>" required>
            </div>
            <div class="col">
                <label for="number2" class="form-label">Código</label>
                <input type="text" class="form-control" name="number" id="number2" value="<?= htmlspecialchars($number) ?>" required>
            </div>
        </div>
        <div class="mb-3">
            <label for="datePicker" class="form-label">Fechas</label>
            <input type="text" id="datePicker" class="form-control">
            <input type="hidden" name="dates" id="dates">
        </div>
        <button type="submit" class="btn btn-success">Guardar fechas</button>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
var selectedDates = <?php echo json_encode($selectedDates); ?>;
var fp = flatpickr("#datePicker", {
    mode: "multiple",
    dateFormat: "Y-m-d",
    defaultDate: selectedDates,
    onChange: function(selDates, dateStr, instance) {
        document.getElementById('dates').value = selDates.map(function(d){return instance.formatDate(d, "Y-m-d");}).join(',');
    }
});
// Initialize hidden input with default dates
 document.getElementById('dates').value = selectedDates.join(',');
</script>
</body>
</html>
