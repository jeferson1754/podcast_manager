<?php
// estadisticas.php — panel de estadísticas de escucha.
// "Escuchado" = episodio con completed_at (la fecha en que pasó a PUBLISHED al salir de la playlist).
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';

// Sección activa de la barra de navegación y título de la pestaña
$section = 'estadisticas';
$tituloPagina = 'Podcast Manager — Estadísticas';

const EST_MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

function est_horas(int $seg): string
{
    return number_format($seg / 3600, 1, ',', '.');
}

function est_fecha(?string $f): string
{
    $ts = $f ? strtotime($f) : false;
    return $ts ? date('d/m/Y', $ts) : '—';
}

function est_hace(?string $f): string
{
    $ts = $f ? strtotime($f) : false;
    if (!$ts) {
        return '';
    }
    $d = max(0, (int) floor((time() - $ts) / 86400));
    return $d === 0 ? 'hoy' : ($d === 1 ? 'hace 1 día' : "hace $d días");
}

// ------------------------------------------------------------------
// Filtros
// ------------------------------------------------------------------
$periodos = [
    '4s'   => ['Últimas 4 semanas', 'semana', 4],
    '12s'  => ['Últimas 12 semanas', 'semana', 12],
    '26s'  => ['Últimas 26 semanas', 'semana', 26],
    '12m'  => ['Últimos 12 meses', 'mes', 12],
    'todo' => ['Todo el historial', 'mes', null],
];
$per = (isset($_GET['periodo']) && is_string($_GET['periodo']) && isset($periodos[$_GET['periodo']])) ? $_GET['periodo'] : '12s';
[$perEtiqueta, $gran, $nPer] = $periodos[$per];
$fPod = (int) ($_GET['podcast'] ?? 0);
$incluirCarga = !empty($_GET['carga']);
$umbralCarga = defined('ESTADISTICAS_UMBRAL_CARGA') ? (int) ESTADISTICAS_UMBRAL_CARGA : 25;

// ------------------------------------------------------------------
// Datos: todos los episodios con su podcast (se agrega en PHP)
// ------------------------------------------------------------------
$colLive = tiene_live_status($conn) ? 'e.live_status' : "'none'";
$filas = consultar($conn, "SELECT e.id, e.title, e.youtube_id, e.status, e.duration, e.publish_date, e.completed_at,
        COALESCE(TIME_TO_SEC(e.duration), 0) AS seg, $colLive AS live_status,
        COALESCE(s.podcast_id, e.podcast_id) AS podcast_id, p.title AS podcast_title,
        COALESCE(NULLIF(p.image, ''), c.image) AS image
    FROM episodes e
    LEFT JOIN seasons s ON s.id = e.season_id
    LEFT JOIN podcasts p ON p.id = COALESCE(s.podcast_id, e.podcast_id)
    LEFT JOIN channels c ON c.id = p.channel_id");

// Días con una carga masiva de completed_at (p. ej. la importación inicial): no reflejan cuándo se escuchó
$porDia = [];
foreach ($filas as $f) {
    if (!empty($f['completed_at'])) {
        $d = substr($f['completed_at'], 0, 10);
        $porDia[$d] = ($porDia[$d] ?? 0) + 1;
    }
}
$diasCarga = array_keys(array_filter($porDia, fn($n) => $n >= $umbralCarga));
$nCarga = 0;
foreach ($diasCarga as $d) {
    $nCarga += $porDia[$d];
}

$historicoSeg = 0;      // todo lo que alguna vez se marcó como escuchado (incluye la carga inicial)
$esc = [];              // escuchados válidos (con filtro de podcast y sin carga masiva salvo que se incluya)
$pendientes = [];
$podcasts = [];         // id => acumulados
foreach ($filas as $f) {
    $pid = (int) ($f['podcast_id'] ?? 0);
    if (!empty($f['completed_at'])) {
        $historicoSeg += (int) $f['seg'];
    }
    if ($fPod > 0 && $pid !== $fPod) {
        continue;
    }
    if (!isset($podcasts[$pid])) {
        $podcasts[$pid] = ['titulo' => $f['podcast_title'] ?? 'Sin podcast', 'image' => $f['image'], 'esc' => 0, 'seg' => 0,
                           'pend' => 0, 'segPend' => 0, 'ultimo' => null];
    }
    if (!empty($f['completed_at'])) {
        if ($incluirCarga || !in_array(substr($f['completed_at'], 0, 10), $diasCarga, true)) {
            $esc[] = $f;
            if ($podcasts[$pid]['ultimo'] === null || $f['completed_at'] > $podcasts[$pid]['ultimo']) {
                $podcasts[$pid]['ultimo'] = $f['completed_at'];
            }
        }
    } elseif ($f['status'] === 'PENDING') {
        $pendientes[] = $f;
        $podcasts[$pid]['pend']++;
        $podcasts[$pid]['segPend'] += (int) $f['seg'];
    }
}

// ------------------------------------------------------------------
// Rango del período y casilleros (semanas o meses)
// ------------------------------------------------------------------
$hoy = new DateTime('today');
$buckets = [];
$inicio = null;
$clave = function (DateTime $d) use ($gran): string {
    if ($gran === 'semana') {
        return (clone $d)->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }
    return $d->format('Y-m-01');
};

if ($gran === 'semana') {
    $inicio = new DateTime($clave($hoy));
    $inicio->modify('-' . ($nPer - 1) . ' weeks');
} elseif ($nPer !== null) {
    $inicio = new DateTime('first day of this month 00:00:00');
    $inicio->modify('-' . ($nPer - 1) . ' months');
} elseif ($esc) {
    $primero = min(array_column($esc, 'completed_at'));
    $inicio = new DateTime(substr($primero, 0, 7) . '-01');
}
if ($inicio) {
    $cursor = clone $inicio;
    $fin = new DateTime($clave($hoy));
    while ($cursor <= $fin) {
        $k = $cursor->format('Y-m-d');
        $etq = $gran === 'semana' ? $cursor->format('d/m') : EST_MESES[(int) $cursor->format('n') - 1] . ' ' . $cursor->format('y');
        $buckets[$k] = ['label' => $etq, 'seg' => 0];
        $cursor->modify($gran === 'semana' ? '+1 week' : '+1 month');
    }
}

// ------------------------------------------------------------------
// Cálculos
// ------------------------------------------------------------------
$segPer = 0;
$nPeriodo = 0;
$mesIni = new DateTime('first day of this month 00:00:00');
$mesAnt = (clone $mesIni)->modify('-1 month');
$segMes = 0;
$segMesAnt = 0;
foreach ($esc as $f) {
    $dt = new DateTime(substr($f['completed_at'], 0, 10));
    $seg = (int) $f['seg'];
    $pid = (int) ($f['podcast_id'] ?? 0);

    if ($dt >= $mesIni) {
        $segMes += $seg;
    } elseif ($dt >= $mesAnt) {
        $segMesAnt += $seg;
    }
    if ($inicio !== null && $dt < $inicio) {
        continue;
    }
    $segPer += $seg;
    $nPeriodo++;
    $podcasts[$pid]['esc']++;
    $podcasts[$pid]['seg'] += $seg;
    $k = $clave($dt);
    if (isset($buckets[$k])) {
        $buckets[$k]['seg'] += $seg;
    }
}
$nSemanas = $gran === 'semana' ? max(1, count($buckets)) : null;
$promedio = $nSemanas ? $segPer / $nSemanas : null;

$segPend = array_sum(array_column($pendientes, 'seg'));
usort($pendientes, function ($a, $b) {
    $la = ($a['live_status'] ?? 'none') === 'live' ? 0 : 1;
    $lb = ($b['live_status'] ?? 'none') === 'live' ? 0 : 1;
    return [$la, $b['publish_date']] <=> [$lb, $a['publish_date']];
});
$proximos = array_slice($pendientes, 0, 6);

uasort($podcasts, fn($a, $b) => [$b['seg'], $b['pend']] <=> [$a['seg'], $a['pend']]);
$tabla = array_filter($podcasts, fn($p) => $p['esc'] > 0 || $p['pend'] > 0);
$top = array_slice(array_filter($podcasts, fn($p) => $p['seg'] > 0), 0, 8);

$serieLabels = array_column($buckets, 'label');
$serieHoras = array_map(fn($b) => round($b['seg'] / 3600, 1), $buckets);
$barLabels = array_column($top, 'titulo');
$barHoras = array_map(fn($p) => round($p['seg'] / 3600, 1), array_values($top));

$listaPodcasts = consultar($conn, "SELECT id, title FROM podcasts ORDER BY title");
$deltaMes = $segMes - $segMesAnt;

include 'layout_header.php';
?>
<div class="main-card">
    <div class="page-header flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-chart-line text-primary me-2"></i>Estadísticas</h3>
            <p class="text-muted small mb-0">Qué escuchas y cuánto, según cuándo cada episodio pasó a escuchado (<code>completed_at</code>).</p>
        </div>
    </div>

    <form method="get" class="row g-2 align-items-end mb-3" id="formFiltros">
        <div class="col-6 col-lg-3">
            <label class="form-label small text-muted mb-1">Período</label>
            <select name="periodo" class="form-select" onchange="this.form.submit()">
                <?php foreach ($periodos as $k => $p): ?>
                    <option value="<?= h($k) ?>" <?= $per === $k ? 'selected' : '' ?>><?= h($p[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-4">
            <label class="form-label small text-muted mb-1">Podcast</label>
            <select name="podcast" class="form-select" onchange="this.form.submit()">
                <option value="0">Todos</option>
                <?php foreach ($listaPodcasts as $lp): ?>
                    <option value="<?= (int) $lp['id'] ?>" <?= $fPod === (int) $lp['id'] ? 'selected' : '' ?>><?= h($lp['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($nCarga > 0): ?>
            <div class="col-12 col-lg-5">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="carga" value="1" id="chkCarga" <?= $incluirCarga ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label small" for="chkCarga">
                        Incluir la carga inicial (<?= $nCarga ?> episodios marcados el <?= h(implode(', ', array_map('est_fecha', $diasCarga))) ?>)
                    </label>
                </div>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($nCarga > 0 && !$incluirCarga): ?>
        <div class="alert alert-light border small py-2">
            <i class="fas fa-circle-info text-primary me-1"></i>
            Se dejaron fuera <?= $nCarga ?> episodios marcados de golpe el <?= h(implode(', ', array_map('est_fecha', $diasCarga))) ?>:
            esa fecha no indica cuándo los escuchaste y distorsionaría los gráficos.
            Contando todo el historial, llevas <strong><?= est_horas($historicoSeg) ?> h</strong> escuchadas.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= est_horas($segPer) ?> h</div>
                <div class="stat-label"><i class="far fa-clock me-1"></i>Horas escuchadas</div>
                <div class="small text-muted mt-1"><?= h(mb_strtolower($perEtiqueta)) ?><?= $promedio !== null ? ' · ' . est_horas((int) round($promedio)) . ' h/semana' : '' ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= $nPeriodo ?></div>
                <div class="stat-label"><i class="fas fa-headphones me-1"></i>Episodios escuchados</div>
                <div class="small text-muted mt-1"><?= $nPeriodo > 0 ? est_horas((int) round($segPer / $nPeriodo)) . ' h por episodio' : 'sin episodios en el período' ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= count($pendientes) ?></div>
                <div class="stat-label"><i class="fas fa-list-check me-1"></i>Pendientes</div>
                <div class="small text-muted mt-1"><?= est_horas((int) $segPend) ?> h por escuchar</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-value"><?= est_horas($segMes) ?> h</div>
                <div class="stat-label"><i class="far fa-calendar me-1"></i>Este mes</div>
                <div class="small mt-1 <?= $deltaMes >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $deltaMes >= 0 ? '+' : '−' ?><?= est_horas(abs($deltaMes)) ?> h vs mes anterior (<?= est_horas($segMesAnt) ?> h)
                </div>
            </div>
        </div>
    </div>

    <?php if ($nPeriodo === 0): ?>
        <div class="empty-state py-4">
            <i class="fas fa-chart-line"></i>
            <h5>Aún no hay episodios escuchados en este período</h5>
            <p class="small">Aparecen cuando un episodio sale de la playlist y queda como escuchado.</p>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="border rounded-3 p-3 h-100">
                    <div class="fw-semibold mb-2">Horas escuchadas por <?= $gran === 'semana' ? 'semana' : 'mes' ?></div>
                    <div style="position:relative;height:240px;"><canvas id="cSerie" role="img" aria-label="Horas escuchadas por <?= $gran === 'semana' ? 'semana' : 'mes' ?> en el período seleccionado"></canvas></div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="border rounded-3 p-3 h-100">
                    <div class="fw-semibold mb-2">Horas por podcast</div>
                    <div style="position:relative;height:<?= max(160, count($barLabels) * 38 + 50) ?>px;"><canvas id="cPodcast" role="img" aria-label="Horas escuchadas por podcast en el período"></canvas></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">Continuar escuchando</div>
            <?php if (count($pendientes) > count($proximos)): ?>
                <a class="small text-decoration-none" href="index.php?section=<?= h($episodios) ?>&estado=PENDING<?= $fPod > 0 ? '&podcast=' . $fPod : '' ?>">Ver los <?= count($pendientes) ?> pendientes</a>
            <?php endif; ?>
        </div>
        <?php if ($proximos): ?>
            <div class="row g-3">
                <?php foreach ($proximos as $e):
                    $yt = !empty($e['youtube_id']) ? 'https://www.youtube.com/watch?v=' . rawurlencode($e['youtube_id']) : null;
                    $live = $e['live_status'] ?? 'none';
                ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="d-flex gap-3 align-items-center border rounded-3 p-2 h-100">
                            <?php if ($yt): ?>
                                <a href="<?= h($yt) ?>" target="_blank" rel="noopener" class="flex-shrink-0">
                                    <img src="https://i.ytimg.com/vi/<?= h($e['youtube_id']) ?>/mqdefault.jpg" alt="" loading="lazy" style="width:96px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;">
                                </a>
                            <?php endif; ?>
                            <div style="min-width:0;">
                                <div class="fw-semibold small text-truncate" title="<?= h($e['title']) ?>"><?= h($e['title']) ?></div>
                                <div class="text-muted small text-truncate"><?= h($e['podcast_title'] ?? '') ?></div>
                                <div class="small mt-1">
                                    <?php if ($live === 'live'): ?>
                                        <span class="badge bg-danger">En vivo</span>
                                    <?php elseif ($live === 'upcoming'): ?>
                                        <span class="badge bg-info text-dark">Programado</span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="far fa-clock me-1"></i><?= h($e['duration']) ?></span>
                                    <?php endif; ?>
                                    <span class="text-muted ms-2"><?= h(est_hace($e['publish_date'])) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">No tienes episodios pendientes.</p>
        <?php endif; ?>
    </div>

    <div>
        <div class="fw-semibold mb-2">Podcasts</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Podcast</th>
                        <th class="text-center">Escuchados</th>
                        <th class="text-center">Horas</th>
                        <th class="text-center">Pendientes</th>
                        <th class="text-center">Por escuchar</th>
                        <th>Último escuchado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tabla): foreach ($tabla as $p): ?>
                        <tr>
                            <td class="fw-semibold text-dark"><?= h($p['titulo']) ?></td>
                            <td class="text-center"><span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1"><?= (int) $p['esc'] ?></span></td>
                            <td class="text-center"><?= est_horas((int) $p['seg']) ?> h</td>
                            <td class="text-center"><?= $p['pend'] > 0 ? '<span class="badge bg-warning text-dark">' . (int) $p['pend'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td class="text-center text-muted"><?= $p['pend'] > 0 ? est_horas((int) $p['segPend']) . ' h' : '—' ?></td>
                            <td class="small text-muted"><?= h(est_fecha($p['ultimo'])) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Sin datos para mostrar.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
ob_start();
if ($nPeriodo > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const horasFmt = v => v.toLocaleString('es-CL', {maximumFractionDigits: 1}) + ' h';
    const INDIGO = '#4f46e5';
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#64748b';
    new Chart(document.getElementById('cSerie'), {
        type: 'line',
        data: {
            labels: <?= json_encode($serieLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [{data: <?= json_encode(array_values($serieHoras)) ?>, borderColor: INDIGO, backgroundColor: 'rgba(79,70,229,0.10)',
                fill: true, borderWidth: 2, tension: 0.3, pointRadius: 3, pointBackgroundColor: INDIGO}]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => horasFmt(c.parsed.y)}}},
            scales: {x: {grid: {display: false}, ticks: {autoSkip: true, maxRotation: 0}},
                     y: {beginAtZero: true, grid: {color: '#e2e8f0'}, ticks: {callback: v => v + ' h'}}}
        }
    });
    new Chart(document.getElementById('cPodcast'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($barLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [{data: <?= json_encode($barHoras) ?>, backgroundColor: INDIGO, borderRadius: 4, barThickness: 18}]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => horasFmt(c.parsed.x)}}},
            scales: {x: {beginAtZero: true, grid: {color: '#e2e8f0'}, ticks: {callback: v => v + ' h'}}, y: {grid: {display: false}}}
        }
    });
</script>
<?php endif;
$scriptsExtra = ob_get_clean();
include 'layout_footer.php';