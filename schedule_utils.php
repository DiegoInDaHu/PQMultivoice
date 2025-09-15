<?php
function nextWorkingDay(DateTime $date): DateTime {
    $d = clone $date;
    do {
        $d->modify('+1 day');
    } while (in_array($d->format('N'), [6,7]));
    return $d;
}

function previousWorkingDay(DateTime $date): DateTime {
    $d = clone $date;
    do {
        $d->modify('-1 day');
    } while (in_array($d->format('N'), [6,7]));
    return $d;
}

function rebuildScheduledCalls(PDO $pdo, string $defaultExtension, string $executionTime, string $changeTiming): void {
    if ($defaultExtension === '') {
        return;
    }

    $pdo->exec("DELETE FROM scheduled_calls WHERE executed_at IS NULL");
    $stmt = $pdo->query('SELECT bd.day, b.code FROM behavior_days bd JOIN behaviors b ON bd.behavior_id = b.id ORDER BY bd.day');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $prevDay = null;
    $prevCode = null;
    $now = new DateTime();

    foreach ($rows as $row) {
        $day = new DateTime($row['day']);
        $code = $row['code'];
        $shouldSchedule = false;
        if ($prevCode === null) {
            $shouldSchedule = true;
        } else {
            $expected = nextWorkingDay($prevDay);
            if ($code !== $prevCode || $expected->format('Y-m-d') !== $day->format('Y-m-d')) {
                $shouldSchedule = true;
            }
        }
        if ($shouldSchedule) {
            if ($changeTiming === 'end') {
                $schedDay = previousWorkingDay($day);
            } else {
                $schedDay = $day;
            }
            $scheduledAt = $schedDay->format('Y-m-d') . ' ' . $executionTime . ':00';
            if (new DateTime($scheduledAt) >= $now) {
                $pdo->prepare('INSERT INTO scheduled_calls(extension, number, scheduled_at) VALUES (:ext, :num, :sched)')
                    ->execute([
                        ':ext' => $defaultExtension,
                        ':num' => $code,
                        ':sched' => $scheduledAt
                    ]);
            }
        }
        $prevDay = $day;
        $prevCode = $code;
    }
}
?>
