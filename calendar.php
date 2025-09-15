<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/schedule_utils.php';

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
}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN execution_time VARCHAR(5) DEFAULT '21:00'");
} catch (PDOException $e) {
}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN change_timing VARCHAR(10) DEFAULT 'start'");
} catch (PDOException $e) {
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_bot_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
}
try {
    $pdo->exec('ALTER TABLE settings ADD COLUMN telegram_chat_id VARCHAR(255) DEFAULT NULL');
} catch (PDOException $e) {
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

$pdo->exec('CREATE TABLE IF NOT EXISTS behavior_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    behavior_id INT NOT NULL,
    day DATE NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$settings = $pdo->query('SELECT default_extension, execution_time, change_timing FROM settings WHERE id = 1')->fetch() ?: [];
$defaultExtension = $settings['default_extension'] ?? '';
$executionTime = $settings['execution_time'] ?? '21:00';
$changeTiming = $settings['change_timing'] ?? 'start';


$behaviors = $pdo->query('SELECT id, name, code, color FROM behaviors ORDER BY name')->fetchAll();

$message = '';
$behaviorId = intval($_POST['behavior'] ?? $_GET['behavior'] ?? ($behaviors[0]['id'] ?? 0));
$behaviorCode = '';
$behaviorColor = '#0d6efd';
foreach ($behaviors as $b) {
    if ($b['id'] == $behaviorId) {
        $behaviorCode = $b['code'];
        $behaviorColor = $b['color'] ?: '#0d6efd';
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datesStr = trim($_POST['dates'] ?? '');
    $dates = $datesStr !== '' ? array_filter(array_map('trim', explode(',', $datesStr))) : [];
    if ($behaviorId && $defaultExtension !== '') {
        $pdo->prepare('DELETE FROM behavior_days WHERE behavior_id = :bid')
            ->execute([':bid' => $behaviorId]);
        if (!empty($dates)) {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $pdo->prepare("DELETE FROM behavior_days WHERE day IN ($placeholders)")
                ->execute($dates);
            $ins = $pdo->prepare('INSERT INTO behavior_days(behavior_id, day) VALUES (:id, :day)');
            foreach ($dates as $d) {
                $ins->execute([':id' => $behaviorId, ':day' => $d]);
            }
        }
        rebuildScheduledCalls($pdo, $defaultExtension, $executionTime, $changeTiming);
        $message = 'Días actualizados.';
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

// Fetch pending scheduled calls with behavior names
$pendingCalls = $pdo->query('SELECT sc.extension, sc.number, sc.scheduled_at, b.name AS behavior_name '
    . 'FROM scheduled_calls sc JOIN behaviors b ON sc.number = b.code '
    . 'WHERE sc.executed_at IS NULL AND sc.scheduled_at >= NOW() ORDER BY sc.scheduled_at')
    ->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Calendario</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background-color:#003883">
        <div class="container-fluid">
        <img src="proquo_pqmultivoice_blanco.png" alt="Logo" style="max-width: 120px;">
            <div class="navbar-nav">
                <a class="nav-link" href="dashboard.php">Resumen</a>
                <a class="nav-link active" href="calendar.php">Calendario</a>
                <a class="nav-link" href="calls.php">Ejec. manualmente</a>
                <a class="nav-link" href="config.php">Configuración</a>
                <a class="nav-link" href="logout.php">Salir</a>
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
                                <option value="<?= $b['id'] ?>" <?= $b['id'] == $behaviorId ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <label for="datePicker" class="form-label">Días</label>
                            <input type="text" id="datePicker" class="form-control">
                            <input type="hidden" name="dates" id="dates">
                        </div>
                    </div>
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

        <?php if (!empty($pendingCalls)): ?>
            <h2 class="mt-4">Cambios pendientes</h2>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Extensión</th>
                        <th>Comportamiento</th>
                        <th>Programada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingCalls as $call): ?>
                        <tr>
                            <td><?= htmlspecialchars($call['extension']) ?></td>
                            <td><?= htmlspecialchars($call['behavior_name']) ?></td>
                            <td><?= htmlspecialchars($call['scheduled_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="mt-4">No hay cambios pendientes.</p>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script>
        var behaviorSchedules = <?php echo json_encode($behaviorSchedules); ?>;
        var behaviorColors = <?php echo json_encode($behaviorColors); ?>;
        var currentBehaviorCode = <?php echo json_encode($behaviorCode); ?>;
        var currentBehaviorColor = <?php echo json_encode($behaviorColor); ?>;
        var existingDates = <?php echo json_encode(array_column($behaviorDays, 'day')); ?>;
        document.getElementById('dates').value = existingDates.join(',');

        function refreshColors(fp) {
            var selectedDates = document.getElementById('dates').value.split(',').filter(Boolean);
            fp.calendarContainer.querySelectorAll('.flatpickr-day').forEach(function(dayElem) {
                var date = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                var color = '';
                var textColor = '';
                if (selectedDates.indexOf(date) !== -1) {
                    color = currentBehaviorColor;
                    textColor = '#fff';
                } else {
                    Object.keys(behaviorSchedules).forEach(function(code) {
                        if (behaviorSchedules[code].dates.indexOf(date) !== -1) {
                            color = behaviorColors[code];
                            textColor = '#fff';
                        }
                    });
                }
                dayElem.style.backgroundColor = color;
                dayElem.style.color = textColor;
                if (color) {
                    dayElem.style.boxShadow = 'none';
                }
            });
        }

        var fp = flatpickr("#datePicker", {
            locale: "es",
            mode: "multiple",
            dateFormat: "Y-m-d",
            defaultDate: existingDates,
            onChange: function(selDates, dateStr, instance) {
                var dates = selDates.map(function(d) {
                    return instance.formatDate(d, 'Y-m-d');
                });
                document.getElementById('dates').value = dates.join(',');
                if (!behaviorSchedules[currentBehaviorCode]) {
                    behaviorSchedules[currentBehaviorCode] = {dates: []};
                }
                behaviorSchedules[currentBehaviorCode].dates = dates;
                refreshColors(instance);
            },
            onMonthChange: function(selDates, dateStr, instance) {
                refreshColors(instance);
            },
            onYearChange: function(selDates, dateStr, instance) {
                refreshColors(instance);
            },
            onReady: function(selDates, dateStr, instance) {
                refreshColors(instance);
            }
        });
    </script>
</body>

</html>
