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
    execution_time VARCHAR(5) DEFAULT "21:00"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN default_extension VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {}

$pdo->exec('CREATE TABLE IF NOT EXISTS behaviors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$pdo->exec('CREATE TABLE IF NOT EXISTS behavior_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    behavior_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT default_extension, execution_time FROM settings WHERE id = 1')->fetch() ?: [];
$defaultExtension = $settings['default_extension'] ?? '';
$executionTime = $settings['execution_time'] ?? '21:00';

$behaviors = $pdo->query('SELECT id, name, code FROM behaviors ORDER BY name')->fetchAll();

$message = '';
$behaviorId = intval($_POST['behavior'] ?? $_GET['behavior'] ?? ($behaviors[0]['id'] ?? 0));
$period = null;
if ($behaviorId) {
    $stmt = $pdo->prepare('SELECT start_date, end_date FROM behavior_periods WHERE behavior_id = :id');
    $stmt->execute([':id' => $behaviorId]);
    $period = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_period') {
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        if ($behaviorId && $start !== '' && $end !== '' && $defaultExtension !== '') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM behavior_periods WHERE behavior_id <> :id AND NOT (end_date < :start OR start_date > :end)');
            $check->execute([':id' => $behaviorId, ':start' => $start, ':end' => $end]);
            if ($check->fetchColumn() > 0) {
                $message = 'Periodo solapado con otro comportamiento.';
            } else {
                $pdo->prepare('DELETE FROM behavior_periods WHERE behavior_id = :id')->execute([':id' => $behaviorId]);
                $pdo->prepare('INSERT INTO behavior_periods(behavior_id, start_date, end_date) VALUES (:id, :start, :end)')
                    ->execute([':id' => $behaviorId, ':start' => $start, ':end' => $end]);
                $stmt = $pdo->prepare('SELECT code FROM behaviors WHERE id = :id');
                $stmt->execute([':id' => $behaviorId]);
                $code = $stmt->fetchColumn();
                if ($code !== false) {
                    $pdo->prepare('DELETE FROM scheduled_calls WHERE number = :code AND executed_at IS NULL')->execute([':code' => $code]);
                    $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:ext, :num, :sched)')
                        ->execute([
                            ':ext' => $defaultExtension,
                            ':num' => $code,
                            ':sched' => $start . ' ' . $executionTime . ':00'
                        ]);
                }
                $message = 'Periodo actualizado.';
                $period = ['start_date' => $start, 'end_date' => $end];
            }
        } else {
            $message = 'Todos los campos son obligatorios.';
        }
    }
}

$allPeriods = $pdo->query('SELECT bp.start_date, bp.end_date, b.code, b.name FROM behavior_periods bp JOIN behaviors b ON bp.behavior_id = b.id')->fetchAll();
$behaviorSchedules = [];
$behaviorColors = [];
$palette = ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#0dcaf0', '#6f42c1', '#fd7e14'];
$ci = 0;
foreach ($allPeriods as $row) {
    $code = $row['code'];
    if (!isset($behaviorSchedules[$code])) {
        $behaviorSchedules[$code] = ['name' => $row['name'], 'dates' => []];
        $behaviorColors[$code] = $palette[$ci % count($palette)];
        $ci++;
    }
    $start = new DateTime($row['start_date']);
    $end = new DateTime($row['end_date']);
    for ($d = $start; $d <= $end; $d->modify('+1 day')) {
        $behaviorSchedules[$code]['dates'][] = $d->format('Y-m-d');
    }
}

$selectedStart = $period['start_date'] ?? '';
$selectedEnd = $period['end_date'] ?? '';
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
        <a class="navbar-brand" href="#">PQ Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link active" href="index.php">Historial</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
            <a class="nav-link" href="calls.php">Llamadas</a>
            <a class="nav-link" href="config.php">Configuración</a>
        </div>
    </div>
</nav>
<div class="container">
<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if (!$behaviors): ?>
    <p>No hay comportamientos guardados. Agregue uno en Configuración.</p>
<?php else: ?>
    <h2>Configurar calendario</h2>
    <form method="post">
        <input type="hidden" name="action" value="save_period">
        <div class="row mb-3">
            <div class="col">
                <label for="behavior" class="form-label">Comportamiento</label>
                <select class="form-select" name="behavior" id="behavior" onchange="location='calendar.php?behavior='+this.value;">
                    <?php foreach ($behaviors as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $b['id']==$behaviorId ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="rangePicker" class="form-label">Periodo</label>
            <input type="text" id="rangePicker" class="form-control">
            <input type="hidden" name="start_date" id="start_date" value="<?= htmlspecialchars($selectedStart) ?>">
            <input type="hidden" name="end_date" id="end_date" value="<?= htmlspecialchars($selectedEnd) ?>">
        </div>
        <button type="submit" class="btn btn-success">Guardar periodo</button>
    </form>
    <div class="mt-3">
        <?php foreach ($behaviorColors as $code => $color): ?>
            <span class="badge" style="background-color: <?= $color ?>;">&nbsp;</span>
            <?= htmlspecialchars($behaviorSchedules[$code]['name']) ?>&nbsp;
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<script>
var selectedStart = "<?= $selectedStart ?>";
var selectedEnd = "<?= $selectedEnd ?>";
var behaviorSchedules = <?php echo json_encode($behaviorSchedules); ?>;
var behaviorColors = <?php echo json_encode($behaviorColors); ?>;
flatpickr("#rangePicker", {
    locale: "es",
    mode: "range",
    dateFormat: "Y-m-d",
    defaultDate: selectedStart && selectedEnd ? [selectedStart, selectedEnd] : [],
    onChange: function(selDates, dateStr, instance) {
        var dates = selDates.map(function(d){return instance.formatDate(d, 'Y-m-d');});
        document.getElementById('start_date').value = dates[0] || '';
        document.getElementById('end_date').value = dates[1] || '';
    },
    onDayCreate: function(dObj, dStr, fp, dayElem) {
        var date = fp.formatDate(dayElem.dateObj, "Y-m-d");
        Object.keys(behaviorSchedules).forEach(function(code) {
            if (behaviorSchedules[code].dates.indexOf(date) !== -1) {
                dayElem.style.backgroundColor = behaviorColors[code];
                dayElem.style.color = '#fff';
            }
        });
    }
});
</script>
</body>
</html>

