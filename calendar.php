<?php
$timezone = getenv('APP_TZ') ?: 'Europe/Madrid';
date_default_timezone_set($timezone);
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
        $time = trim($_POST['time'] ?? '21:00');
        if ($extension !== '' && $number !== '' && $time !== '') {
            $pdo->prepare('DELETE FROM scheduled_calls WHERE extension = :extension AND number = :number AND executed_at IS NULL')
                ->execute([':extension' => $extension, ':number' => $number]);

            if ($dates !== '') {
                $dateArray = array_filter(array_map('trim', explode(',', $dates)));
                $insert = $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:extension, :number, :scheduled_at)');
                foreach ($dateArray as $d) {
                    $insert->execute([
                        ':extension' => $extension,
                        ':number' => $number,
                        ':scheduled_at' => $d . ' ' . $time . ':00'
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
$selectedTime = '21:00';
$executionHistory = [];
if ($extension !== '' && $number !== '') {
    $stmt = $pdo->prepare('SELECT DATE(scheduled_at) AS d, TIME(scheduled_at) AS t FROM scheduled_calls WHERE extension = :extension AND number = :number AND executed_at IS NULL');
    $stmt->execute([':extension' => $extension, ':number' => $number]);
    $rows = $stmt->fetchAll();
    $selectedDates = array_column($rows, 'd');
    if ($rows) {
        $selectedTime = substr($rows[0]['t'], 0, 5);
    }

    $histStmt = $pdo->prepare('SELECT executed_at FROM scheduled_calls WHERE extension = :extension AND number = :number AND executed_at IS NOT NULL ORDER BY executed_at DESC');
    $histStmt->execute([':extension' => $extension, ':number' => $number]);
    $executionHistory = $histStmt->fetchAll();
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['dates' => $selectedDates, 'time' => $selectedTime]);
    exit;
}

$allScheduled = $pdo->query('SELECT DATE(sc.scheduled_at) AS d, sc.number, c.name FROM scheduled_calls sc JOIN codes c ON sc.number = c.code WHERE sc.executed_at IS NULL')->fetchAll();
$codeSchedules = [];
$codeColors = [];
$palette = ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#0dcaf0', '#6f42c1', '#fd7e14'];
$ci = 0;
foreach ($allScheduled as $row) {
    $code = $row['number'];
    if (!isset($codeSchedules[$code])) {
        $codeSchedules[$code] = ['name' => $row['name'], 'dates' => []];
        $codeColors[$code] = $palette[$ci % count($palette)];
        $ci++;
    }
    $codeSchedules[$code]['dates'][] = $row['d'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Calendario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <style>
    .schedule-dot{
        position:absolute;
        width:6px;
        height:6px;
        border-radius:50%;
        bottom:2px;
        right:2px;
    }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Historial</a>
            <a class="nav-link" href="calls.php">Llamadas</a>
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
            <div class="col">
                <label for="time" class="form-label">Hora</label>
                <input type="time" class="form-control" name="time" id="time" value="<?= htmlspecialchars($selectedTime) ?>" required>
            </div>
        </div>
        <div class="mb-3">
            <label for="datePicker" class="form-label">Fechas</label>
            <input type="text" id="datePicker" class="form-control">
            <input type="hidden" name="dates" id="dates">
        </div>
        <button type="submit" class="btn btn-success">Guardar fechas</button>
    </form>
    <div class="mt-3">
        <?php foreach ($codeColors as $code => $color): ?>
            <span class="badge" style="background-color: <?= $color ?>;">&nbsp;</span>
            <?= htmlspecialchars($codeSchedules[$code]['name']) ?>&nbsp;
        <?php endforeach; ?>
    </div>
    <?php if ($executionHistory): ?>
    <h3 class="mt-4">Historial de ejecuciones</h3>
    <ul class="list-group">
        <?php foreach ($executionHistory as $row): ?>
            <li class="list-group-item"><?= htmlspecialchars($row['executed_at']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<script>
var selectedDates = <?php echo json_encode($selectedDates); ?>;
var codeSchedules = <?php echo json_encode($codeSchedules); ?>;
var codeColors = <?php echo json_encode($codeColors); ?>;
var fp = flatpickr("#datePicker", {
    locale: "es",
    mode: "multiple",
    dateFormat: "Y-m-d",
    defaultDate: selectedDates,
    onChange: function(selDates, dateStr, instance) {
        document.getElementById('dates').value = selDates.map(function(d){return instance.formatDate(d, 'Y-m-d');}).join(',');
    },
    onDayCreate: function(dObj, dStr, fp, dayElem) {
        var date = fp.formatDate(dayElem.dateObj, "Y-m-d");
        Object.keys(codeSchedules).forEach(function(code) {
            if (codeSchedules[code].dates.indexOf(date) !== -1) {
                var span = document.createElement('span');
                span.className = 'schedule-dot';
                span.style.backgroundColor = codeColors[code];
                dayElem.appendChild(span);
            }
        });
    }
});
document.getElementById('dates').value = selectedDates.join(',');

document.getElementById('number').addEventListener('change', function() {
    var number = this.value;
    var extension = document.getElementById('extension').value;
    fetch('calendar.php?ajax=1&extension=' + encodeURIComponent(extension) + '&number=' + encodeURIComponent(number))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            selectedDates = data.dates || [];
            document.getElementById('time').value = data.time || '21:00';
            fp.setDate(selectedDates, false);
            document.getElementById('dates').value = selectedDates.join(',');
        });
});
</script>
</body>
</html>

