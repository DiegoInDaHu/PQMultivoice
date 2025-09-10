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

$pdo->exec('CREATE TABLE IF NOT EXISTS codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

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
    <title>Historial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link active" href="index.php">Historial</a>
            <a class="nav-link" href="calls.php">Llamadas</a>
            <a class="nav-link" href="config.php">Configuración</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
        </div>
    </div>
</nav>
<div class="container">
    <h2>Calendario de llamadas programadas</h2>
    <input type="text" id="calendar" class="form-control">
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
</script>
</body>
</html>
