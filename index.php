<?php
// History page styled with Bootstrap

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Marcación Siptize</a>
        <div class="navbar-nav">
            <a class="nav-link active" href="index.php">Historial</a>
            <a class="nav-link" href="config.php">Configuración</a>
            <a class="nav-link" href="calendar.php">Calendario</a>
        </div>
    </div>
</nav>
<div class="container">
    <h2>Llamadas realizadas</h2>
    <table class="table table-striped">
        <thead><tr><th>Extensión</th><th>Código</th><th>Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($pdo->query('SELECT extension, number, created_at FROM calls ORDER BY id DESC') as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['extension']) ?></td>
                <td><?= htmlspecialchars($row['number']) ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Llamadas programadas</h2>
    <table class="table table-striped">
        <thead><tr><th>Extensión</th><th>Código</th><th>Programada</th><th>Ejecutada</th><th>Editar</th></tr></thead>
        <tbody>
        <?php foreach ($pdo->query('SELECT extension, number, scheduled_at, executed_at FROM scheduled_calls ORDER BY id DESC') as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['extension']) ?></td>
                <td><?= htmlspecialchars($row['number']) ?></td>
                <td><?= htmlspecialchars($row['scheduled_at']) ?></td>
                <td><?= htmlspecialchars($row['executed_at'] ?? '-') ?></td>
                <td><a class="btn btn-sm btn-primary" href="calendar.php?extension=<?= urlencode($row['extension']) ?>&number=<?= urlencode($row['number']) ?>">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
