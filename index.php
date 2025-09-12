<?php
require __DIR__ . '/db.php';

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
$allPeriods = $pdo->query('SELECT bd.day, b.code, b.name, b.color FROM behavior_days bd JOIN behaviors b ON bd.behavior_id = b.id')->fetchAll();
$codeSchedules = [];
$codeColors = [];
foreach ($allPeriods as $row) {
    $code = $row['code'];
    if (!isset($codeSchedules[$code])) {
        $codeSchedules[$code] = ['name' => $row['name'], 'dates' => []];
        $codeColors[$code] = $row['color'] ?: '#0d6efd';
    }
    $codeSchedules[$code]['dates'][] = $row['day'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/png" href="favicon.png">
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
<nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background-color:#003883">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Comportamientos Multivoice</a>
        <div class="navbar-nav">
            <a class="nav-link active" href="index.php">Resumen</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
            <a class="nav-link" href="calls.php">Ejec. manualmente</a>
            <a class="nav-link" href="config.php">Configuración</a>
        </div>
    </div>
</nav>
<div class="container">
    <h2 class="d-flex justify-content-center mb-3">Calendario</h2>
    <div id="calendarWrapper" class="d-flex flex-column align-items-center">
        <div class="mb-3 text-center">
            <?php foreach ($codeColors as $code => $color): ?>
                <span class="badge" style="background-color: <?= $color ?>;">&nbsp;</span>
                <?= htmlspecialchars($codeSchedules[$code]['name']) ?>&nbsp;
            <?php endforeach; ?>
        </div>
        <input type="text" id="calendar" class="form-control">
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
