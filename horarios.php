<?php
// horarios.php — cálculo de horarios semanales (tabla schedule)
// Se ejecuta desde: sincronizar.php (acción recalcular_horarios) y el botón del calendario.
// Ya NO se ejecuta en cada carga de página.

require_once __DIR__ . '/helpers.php';

/**
 * Recalcula la tabla `schedule` de todos los podcasts no finalizados.
 * - Con schedule_custom ("Martes:12:30,Jueves:18:00") usa ese horario tal cual.
 * - Sin él, lo deduce de los últimos 20 episodios (patrón día+hora repetido 2+ veces).
 * Cada podcast se procesa en su propia transacción: nunca queda con el horario borrado a medias.
 * Devuelve la cantidad de podcasts procesados.
 */
function recalcular_horarios(mysqli $conn): int
{
    $dias_es = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
    $podcasts = consultar($conn, "SELECT id, schedule_custom FROM podcasts WHERE state != 'Finalizado'");
    $procesados = 0;

    foreach ($podcasts as $pod) {
        $podcastId = (int) $pod['id'];
        $nuevos = [];   // [dia, hora, is_manual]

        $custom = normalizar_schedule_custom((string) ($pod['schedule_custom'] ?? ''));

        if ($custom !== null && $custom !== '') {
            // Horario fijo manual (conserva los minutos: "Martes:12:30")
            foreach (explode(',', $custom) as $bloque) {
                $partes = explode(':', $bloque, 2);
                $nuevos[] = [$partes[0], $partes[1] . ':00', 1];
            }
        } else {
            // Horario calculado a partir de los 20 episodios MÁS RECIENTES
            $eps = consultar(
                $conn,
                "SELECT e.publish_date
                 FROM episodes e JOIN seasons s ON s.id = e.season_id
                 WHERE s.podcast_id = ? AND e.publish_date IS NOT NULL
                 ORDER BY e.publish_date DESC LIMIT 20",
                'i',
                [$podcastId]
            );
            if (count($eps) < 3) {
                continue;
            }

            $patrones = [];
            foreach ($eps as $e) {
                $ts = strtotime($e['publish_date']);
                if (!$ts) {
                    continue;
                }
                $dia  = $dias_es[(int) date('w', $ts)];
                $hora = date('H:00:00', $ts);
                $key  = $dia . '_' . $hora;
                if (!isset($patrones[$key])) {
                    $patrones[$key] = ['day' => $dia, 'time' => date('H:i:s', $ts), 'count' => 0];
                }
                $patrones[$key]['count']++;
            }
            usort($patrones, fn($a, $b) => $b['count'] <=> $a['count']);

            $diasUsados = [];
            foreach ($patrones as $p) {
                if ($p['count'] >= 2 && !isset($diasUsados[$p['day']])) {
                    $diasUsados[$p['day']] = true;
                    $nuevos[] = [$p['day'], $p['time'], 0];
                }
            }
        }

        $conn->begin_transaction();
        try {
            $ok = ejecutar($conn, "DELETE FROM schedule WHERE podcast_id = ?", 'i', [$podcastId]);
            foreach ($nuevos as [$dia, $hora, $manual]) {
                $ok = $ok && ejecutar(
                    $conn,
                    "INSERT INTO schedule (podcast_id, day_of_week, start_time, is_manual) VALUES (?, ?, ?, ?)",
                    'issi',
                    [$podcastId, $dia, $hora, $manual]
                );
            }
            $ok ? $conn->commit() : $conn->rollback();
            $procesados += $ok ? 1 : 0;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('recalcular_horarios podcast ' . $podcastId . ': ' . $e->getMessage());
        }
    }

    ejecutar(
        $conn,
        "REPLACE INTO site_settings (setting_key, setting_value) VALUES ('schedule_updated_at', ?)",
        's',
        [date('Y-m-d H:i:s')]
    );

    return $procesados;
}

/** ¿Los horarios nunca se calcularon o tienen más de $horas horas? */
function horarios_desactualizados(mysqli $conn, int $horas = 24): bool
{
    $r = consultar($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'schedule_updated_at'");
    if (!$r || empty($r[0]['setting_value'])) {
        return true;
    }
    $ts = strtotime($r[0]['setting_value']);
    return !$ts || (time() - $ts) > $horas * 3600;
}
