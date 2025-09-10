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

$allPeriods = $pdo->query('SELECT bp.start_date, bp.end_date, b.code, b.name FROM behavior_periods bp JOIN behaviors b ON bp.behavior_id = b.id')->fetchAll();
$codeSchedules = [];
$codeColors = [];
$palette = ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#0dcaf0', '#6f42c1', '#fd7e14'];
$ci = 0;
foreach ($allPeriods as $row) {
    $code = $row['code'];
    if (!isset($codeSchedules[$code])) {
        $codeSchedules[$code] = ['name' => $row['name'], 'dates' => []];
        $codeColors[$code] = $palette[$ci % count($palette)];
        $ci++;
    }
    $start = new DateTime($row['start_date']);
    $end = new DateTime($row['end_date']);
    for ($d = $start; $d <= $end; $d->modify('+1 day')) {
        $codeSchedules[$code]['dates'][] = $d->format('Y-m-d');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <style>
    #calendarWrapper .flatpickr-calendar {
        transform: scale(1.3);
        transform-origin: top center;
    }
    </style>
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
    <h2 style="margin-bottom:20px">Calendario</h2>
    <div id="calendarWrapper" class="d-flex justify-content-center">
        <input type="text" id="calendar" class="form-control">
    </div>
    <div class="mt-3">
        <?php foreach ($codeColors as $code => $color): ?>
            <span class="badge" style="background-color: <?= $color ?>;">&nbsp;</span>
            <?= htmlspecialchars($codeSchedules[$code]['name']) ?>&nbsp;
        <?php endforeach; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<script>
var codeSchedules = <?php echo json_encode($codeSchedules); ?>;
var codeColors = <?php echo json_encode($codeColors); ?>;
flatpickr("#calendar", {
    locale: "es",
    inline: true,
    clickOpens: false,
    onDayCreate: function(dObj, dStr, fp, dayElem) {
        var date = fp.formatDate(dayElem.dateObj, "Y-m-d");
        Object.keys(codeSchedules).forEach(function(code) {
            if (codeSchedules[code].dates.indexOf(date) !== -1) {
                dayElem.style.backgroundColor = codeColors[code];
                dayElem.style.color = '#fff';
            }
        });
    }
});
document.getElementById('calendar').style.display = 'none';
</script>
</body>
</html>
