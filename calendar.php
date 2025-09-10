<?php
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
}

$pdo->exec('CREATE TABLE IF NOT EXISTS codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT default_extension FROM settings WHERE id = 1')->fetch() ?: [];
$defaultExtension = $settings['default_extension'] ?? '';

$codes = $pdo->query('SELECT name, code FROM codes ORDER BY name')->fetchAll();

$message = '';
$extension = trim($_POST['extension'] ?? $_GET['extension'] ?? $defaultExtension);
$number = trim($_POST['number'] ?? $_GET['number'] ?? ($codes[0]['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_dates') {
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
    <title>Calendario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Historial</a>
            <a class="nav-link" href="config.php">Configuración</a>
            <a class="nav-link active" href="calendar.php">Calendario</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if (!$codes): ?>
    <p>No hay códigos guardados. Agregue uno en Configuración.</p>
<?php else: ?>
    <h2>Configurar calendario</h2>
    <form method="post">
        <input type="hidden" name="action" value="save_dates">
        <div class="row mb-3">
            <div class="col">
                <label for="extension" class="form-label">Extensión</label>
                <input type="text" class="form-control" name="extension" id="extension" value="<?= htmlspecialchars($extension) ?>" required>
            </div>
            <div class="col">
                <label for="number" class="form-label">Código</label>
                <select class="form-select" name="number" id="number" required>
                    <?php foreach ($codes as $code): ?>
                        <option value="<?= htmlspecialchars($code['code']) ?>" <?= $code['code'] === $number ? 'selected' : '' ?>>
                            <?= htmlspecialchars($code['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="datePicker" class="form-label">Fechas</label>
            <input type="text" id="datePicker" class="form-control">
            <input type="hidden" name="dates" id="dates">
        </div>
        <button type="submit" class="btn btn-success">Guardar fechas</button>
    </form>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<script>
var selectedDates = <?php echo json_encode($selectedDates); ?>;
flatpickr("#datePicker", {
    locale: "es",
    mode: "multiple",
    dateFormat: "Y-m-d",
    defaultDate: selectedDates,
    onChange: function(selDates, dateStr, instance) {
        document.getElementById('dates').value = selDates.map(function(d){return instance.formatDate(d, 'Y-m-d');}).join(',');
    }
});
document.getElementById('dates').value = selectedDates.join(',');
</script>
</body>
</html>

