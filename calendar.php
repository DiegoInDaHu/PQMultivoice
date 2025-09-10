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
    execution_time VARCHAR(5) DEFAULT "21:00",
    notification_email VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN default_extension VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN notification_email VARCHAR(255) DEFAULT NULL');
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

$pdo->exec('CREATE TABLE IF NOT EXISTS behavior_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    behavior_id INT NOT NULL,
    day DATE NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT default_extension, execution_time FROM settings WHERE id = 1')->fetch() ?: [];
$defaultExtension = $settings['default_extension'] ?? '';
$executionTime = $settings['execution_time'] ?? '21:00';

// Helper to rebuild scheduled calls for a behavior
function reprogramBehavior($pdo, $behaviorId, $defaultExtension, $executionTime) {
    if (!$defaultExtension) {
        return;
    }
    $stmt = $pdo->prepare('SELECT code FROM behaviors WHERE id = :id');
    $stmt->execute([':id' => $behaviorId]);
    $code = $stmt->fetchColumn();
    if ($code === false) {
        return;
    }
    $pdo->prepare('DELETE FROM scheduled_calls WHERE number = :code AND executed_at IS NULL')->execute([':code' => $code]);
    $periods = $pdo->prepare('SELECT day FROM behavior_days WHERE behavior_id = :id');
    $periods->execute([':id' => $behaviorId]);
    foreach ($periods as $p) {
        $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:ext, :num, :sched)')
            ->execute([
                ':ext' => $defaultExtension,
                ':num' => $code,
                ':sched' => $p['day'] . ' ' . $executionTime . ':00'
            ]);
    }
}

$behaviors = $pdo->query('SELECT id, name, code, color FROM behaviors ORDER BY name')->fetchAll();

$message = '';
$behaviorId = intval($_POST['behavior'] ?? $_GET['behavior'] ?? ($behaviors[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datesStr = trim($_POST['dates'] ?? '');
    $dates = $datesStr !== '' ? array_filter(array_map('trim', explode(',', $datesStr))) : [];
    if ($behaviorId && $defaultExtension !== '') {
        $overlap = false;
        $check = $pdo->prepare('SELECT behavior_id FROM behavior_days WHERE day = :day');
        foreach ($dates as $d) {
            $check->execute([':day' => $d]);
            $existing = $check->fetchColumn();
            if ($existing && intval($existing) !== $behaviorId) {
                $message = 'Día ' . htmlspecialchars($d) . ' solapado con otro comportamiento.';
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $pdo->prepare('DELETE FROM behavior_days WHERE behavior_id = :bid')
                ->execute([':bid' => $behaviorId]);
            $ins = $pdo->prepare('INSERT INTO behavior_days(behavior_id, day) VALUES (:id, :day)');
            foreach ($dates as $d) {
                $ins->execute([':id' => $behaviorId, ':day' => $d]);
            }
            reprogramBehavior($pdo, $behaviorId, $defaultExtension, $executionTime);
            $message = 'Días actualizados.';
        }
    } else {
        $message = 'Todos los campos son obligatorios.';
    }
}

$stmt = $pdo->prepare('SELECT id, day FROM behavior_days WHERE behavior_id = :id ORDER BY day');
$stmt->execute([':id' => $behaviorId]);
$behaviorDays = $stmt->fetchAll();
$allPeriods = $pdo->query('SELECT bd.day, b.code, b.name, b.color FROM behavior_days bd JOIN behaviors b ON bd.behavior_id = b.id')->fetchAll();
$behaviorSchedules = [];
$behaviorColors = [];
foreach ($allPeriods as $row) {
    $code = $row['code'];
    if (!isset($behaviorSchedules[$code])) {
        $behaviorSchedules[$code] = ['name' => $row['name'], 'dates' => []];
        $behaviorColors[$code] = $row['color'] ?: '#0d6efd';
    }
    $behaviorSchedules[$code]['dates'][] = $row['day'];
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
        <a class="navbar-brand" href="#">PQ Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">Resumen</a>
            <a class="nav-link active" href="calendar.php">Calendario</a>
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
    <form method="post" class="mb-3">
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
            <label for="datePicker" class="form-label">Días</label>
            <input type="text" id="datePicker" class="form-control">
            <input type="hidden" name="dates" id="dates">
        </div>
        <button type="submit" class="btn btn-success">Guardar días</button>
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
var behaviorSchedules = <?php echo json_encode($behaviorSchedules); ?>;
var behaviorColors = <?php echo json_encode($behaviorColors); ?>;
var existingDates = <?php echo json_encode(array_column($behaviorDays, 'day')); ?>;
document.getElementById('dates').value = existingDates.join(',');
flatpickr("#datePicker", {
    locale: "es",
    mode: "multiple",
    dateFormat: "Y-m-d",
    defaultDate: existingDates,
    onChange: function(selDates, dateStr, instance) {
        var dates = selDates.map(function(d){return instance.formatDate(d, 'Y-m-d');});
        document.getElementById('dates').value = dates.join(',');
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

