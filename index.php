<?php
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';
require_once 'horarios.php';


// Sección: solo valores conocidos (lista blanca)
$section = $_GET['section'] ?? $podcast;
if (!is_string($section) || !in_array($section, [$podcast, $episodios, $calendario], true)) {
    $section = $podcast;
}

// Calendario: 'esperado' (compara lo subido con el horario y avisa atrasos) o 'real' (solo lo subido)
$modoCalendario = (($_GET['modo'] ?? '') === 'real') ? 'real' : 'esperado';
// Semana mostrada: 0 = actual, -1 = anterior ... (hasta -52), 1 = la próxima
$semanaCalendario = max(-52, min(1, (int) (is_string($_GET['semana'] ?? null) ? $_GET['semana'] : 0)));

function url_calendario(string $seccion, int $semana, string $modo): string
{
    $q = ['section' => $seccion];
    if ($semana !== 0) {
        $q['semana'] = $semana;
    }
    if ($modo === 'real') {
        $q['modo'] = 'real';
    }
    return 'index.php?' . http_build_query($q);
}

// ------------------------------------------------------------------
// Acción: recalcular horarios del calendario (POST + CSRF)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'recalcular_horarios') {
    csrf_exigir();
    $n = recalcular_horarios($conn);
    flash_set('success', "Horarios recalculados para $n podcast(s).");
    header('Location: index.php?section=' . urlencode($calendario));
    exit;
}

// ------------------------------------------------------------------
// Utilidades de presentación
// ------------------------------------------------------------------
function img_logo(?string $src, string $icono = 'fa-image'): void
{
    if (!empty($src)) { ?>
        <img src="<?= h($src) ?>" alt="Logo" class="img-thumb-preview rounded" loading="lazy" referrerpolicy="no-referrer"
            onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="img-thumb-preview align-items-center justify-content-center bg-light text-muted rounded" style="display:none;">
            <i class="fas <?= h($icono) ?>"></i>
        </div>
    <?php } else { ?>
        <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted rounded">
            <i class="fas <?= h($icono) ?>"></i>
        </div>
    <?php }
}

/** Botón de eliminar: formulario POST con token CSRF y confirmación. */
function boton_borrar(string $type, int $id, string $confirmacion, bool $conTexto = true, string $extraClase = 'border'): string
{
    $js = h(json_encode($confirmacion, JSON_UNESCAPED_UNICODE));
    return '<form method="post" action="delete.php" class="d-inline" onsubmit="return confirm(' . $js . ')">'
        . csrf_field()
        . '<input type="hidden" name="type" value="' . h($type) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="btn btn-sm btn-light text-danger ' . $extraClase . '" title="Eliminar">'
        . '<i class="fas fa-trash' . ($conTexto ? ' me-1' : '') . '"></i>' . ($conTexto ? ' Eliminar' : '')
        . '</button></form>';
}

function fecha_corta(?string $f): string
{
    $ts = $f ? strtotime($f) : false;
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

/** Insignia de estado del episodio: en vivo / programado / pendiente / duración. */
function badge_episodio(array $e): string
{
    $live = $e['live_status'] ?? 'none';
    if ($live === 'live') {
        return '<span class="badge bg-danger text-white px-2 py-1"><i class="fas fa-circle me-1" style="font-size:6px;vertical-align:middle;"></i> En vivo</span>';
    }
    if ($live === 'upcoming') {
        return '<span class="badge bg-info text-dark px-2 py-1"><i class="far fa-calendar me-1"></i> Programado</span>';
    }
    if (($e['status'] ?? '') === 'PENDING') {
        return '<span class="badge bg-warning text-dark px-2 py-1" title="Pendiente en playlist"><i class="fas fa-circle me-1" style="font-size:6px;vertical-align:middle;"></i> Pendiente</span>';
    }
    return '<span><i class="far fa-clock me-1"></i>' . h($e['duration'] ?? '') . '</span>';
}

function url_con(array $cambios): string
{
    return 'index.php?' . http_build_query(array_merge($_GET, $cambios));
}

$etiquetas = [$podcast => 'Podcast', $episodios => 'Episodio'];
$tituloPagina = 'Podcast Manager — ' . ucfirst($section);

include 'layout_header.php';
?>
<div class="main-card">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0 text-capitalize"><?= h($section) ?></h3>
            <p class="text-muted small mb-0">Gestiona y organiza el contenido de tu plataforma</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($section === $podcast): ?>
                <a href="channels.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                    <i class="fas fa-tv"></i> <span>Gestionar Canales</span>
                </a>
                <a href="reclasificar.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" title="Mover episodios al podcast correcto cuando un canal tiene varios">
                    <i class="fas fa-shuffle"></i> <span>Reclasificar</span>
                </a>
            <?php endif; ?>
            <?php if ($section === $calendario): ?>
                <div class="btn-group" role="group" aria-label="Modo del calendario">
                    <a class="btn btn-outline-secondary <?= $modoCalendario === 'esperado' ? 'active' : '' ?>"
                        href="<?= h(url_calendario($calendario, $semanaCalendario, 'esperado')) ?>" title="Compara lo subido con el horario y avisa atrasos">
                        <i class="fas fa-bell me-1"></i> Con avisos
                    </a>
                    <a class="btn btn-outline-secondary <?= $modoCalendario === 'real' ? 'active' : '' ?>"
                        href="<?= h(url_calendario($calendario, $semanaCalendario, 'real')) ?>" title="Solo los episodios realmente subidos, sin cálculo">
                        <i class="fas fa-check me-1"></i> Solo subidos
                    </a>
                </div>
                <?php if ($modoCalendario === 'esperado'): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="recalcular_horarios">
                        <button class="btn btn-outline-primary d-inline-flex align-items-center gap-2" type="submit">
                            <i class="fas fa-rotate"></i> <span>Recalcular horarios</span>
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <a href="add.php?type=<?= urlencode($section) ?>" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="fas fa-plus"></i> <span>Nuevo <?= h($etiquetas[$section]) ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($section === $podcast): ?>
        <?php
        // ---------------- Resumen ----------------
        $r = consultar($conn, "SELECT
                (SELECT COUNT(*) FROM podcasts WHERE state = 'Activo') AS activos,
                (SELECT COUNT(*) FROM episodes WHERE status = 'PENDING') AS pendientes,
                (SELECT COALESCE(SUM(TIME_TO_SEC(duration)), 0) FROM episodes WHERE status = 'PENDING') AS seg_pend,
                (SELECT COUNT(*) FROM podcasts p WHERE p.state = 'Activo' AND NOT EXISTS (
                    SELECT 1 FROM episodes e JOIN seasons s ON s.id = e.season_id
                    WHERE s.podcast_id = p.id AND e.publish_date >= NOW() - INTERVAL 30 DAY)) AS sin_actividad")[0] ?? [];
        $horasPend = round(((float) ($r['seg_pend'] ?? 0)) / 3600, 1);
        ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-value"><?= (int) ($r['activos'] ?? 0) ?></div>
                    <div class="stat-label"><i class="fas fa-podcast me-1"></i>Podcasts activos</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-value"><?= (int) ($r['pendientes'] ?? 0) ?></div>
                    <div class="stat-label"><i class="fas fa-list-check me-1"></i>Episodios pendientes</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <?php
                $seg_pend = (int) ($r['seg_pend'] ?? 0);

                $horas = floor($seg_pend / 3600);
                $minutos = floor(($seg_pend % 3600) / 60);
                ?>

                <div class="stat-card">
                    <div class="stat-value">
                        <?= h("{$horas}h {$minutos}min") ?>
                    </div>
                    <div class="stat-label"><i class="far fa-clock me-1"></i>Por escuchar</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-value <?= ((int) ($r['sin_actividad'] ?? 0)) > 0 ? 'text-warning' : '' ?>"><?= (int) ($r['sin_actividad'] ?? 0) ?></div>
                    <div class="stat-label"><i class="fas fa-moon me-1"></i>Sin Actividad (+30 días)</div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:80px;">Logo</th>
                        <th>Nombre del Podcast</th>
                        <th>Frecuencia</th>
                        <th>Temporada</th>
                        <th class="text-center">Episodios</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $enVivoSql = tiene_live_status($conn)
                        ? "(SELECT COUNT(*) FROM episodes e3 JOIN seasons s3 ON s3.id = e3.season_id WHERE s3.podcast_id = p.id AND e3.live_status = 'live')"
                        : "0";
                    $filas = consultar($conn, "SELECT p.*, $enVivoSql AS en_vivo, c.name AS channel_name, c.image AS channel_image,
                            (SELECT COUNT(*) FROM episodes e JOIN seasons s ON e.season_id = s.id WHERE s.podcast_id = p.id) AS total_episodios,
                            (SELECT number FROM seasons s WHERE s.podcast_id = p.id ORDER BY s.number DESC LIMIT 1) AS temporada_actual
                        FROM podcasts p
                        LEFT JOIN channels c ON p.channel_id = c.id
                        ORDER BY p.id");
                    if ($filas):
                        foreach ($filas as $p):
                            $imagen = !empty($p['image']) ? $p['image'] : $p['channel_image'];
                    ?>
                            <tr>
                                <td class="fw-semibold text-muted"><?= (int) $p['id'] ?></td>
                                <td><?php img_logo($imagen); ?></td>
                                <td>
                                    <a href="<?= h($p['description']) ?>" target="_blank" rel="noopener" class="fw-semibold text-dark text-decoration-none">
                                        <?= h($p['title']) ?>
                                    </a>
                                    <?php if (!empty($p['en_vivo'])): ?>
                                        <span class="badge bg-danger ms-1"><i class="fas fa-circle me-1" style="font-size:6px;vertical-align:middle;"></i>En vivo</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['channel_name']) && mb_strtolower(trim($p['channel_name'])) !== mb_strtolower(trim($p['title']))): ?>
                                        <div class="small text-muted"><i class="fas fa-tv me-1"></i><?= h($p['channel_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['frequency'])): ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1">
                                            <i class="fas fa-clock me-1"></i> <?= h($p['frequency']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">N/D</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas fa-layer-group me-1 text-muted"></i> Temporada <?= h($p['temporada_actual'] ?? '0') ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1">
                                        <i class="fas fa-headphones me-1"></i> <?= (int) $p['total_episodios'] ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="change_state.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <div class="dropdown">
                                            <span class="badge-state <?= estado_clase($p['state']) ?> dropdown-toggle" role="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-circle me-1" style="font-size:8px;"></i> <?= h(estado_label($p['state'])) ?>
                                            </span>
                                            <ul class="dropdown-menu shadow-sm border-0">
                                                <li><button type="submit" name="state" value="Activo" class="dropdown-item">Activo</button></li>
                                                <li><button type="submit" name="state" value="Inactivo" class="dropdown-item">Pausado</button></li>
                                                <li><button type="submit" name="state" value="Finalizado" class="dropdown-item">Finalizado</button></li>
                                            </ul>
                                        </div>
                                    </form>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="edit.php?type=podcasts&id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-light text-primary me-1 border" title="Editar">
                                        <i class="fas fa-pen"></i> Editar
                                    </a>
                                    <?= boton_borrar('podcasts', (int) $p['id'], '¿Eliminar el podcast «' . $p['title'] . '»? Se borrarán también sus temporadas y episodios.') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="empty-state">
                                    <i class="fas fa-podcast fa-2x mb-2 text-muted"></i>
                                    <h5>Sin Podcasts aún</h5>
                                    <p class="small text-muted">Haz clic en "Nuevo Podcast" para registrar tu primer contenido.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($section === $episodios): ?>
        <?php
        // ---------------- Filtros (servidor) + paginación ----------------
        $hasLive = tiene_live_status($conn);
        $q       = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $fPod    = (int) ($_GET['podcast'] ?? 0);
        $fEstado = $_GET['estado'] ?? '';
        $fEstado = in_array($fEstado, ['PENDING', 'PUBLISHED', 'LIVE'], true) ? $fEstado : '';
        $desde   = (isset($_GET['desde']) && is_string($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'])) ? $_GET['desde'] : '';
        $hasta   = (isset($_GET['hasta']) && is_string($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'])) ? $_GET['hasta'] : '';
        $vista   = (($_GET['vista'] ?? '') === 'lista') ? 'lista' : 'tarjetas';
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 24;

        $where = [];
        $tipos = '';
        $params = [];
        if ($q !== '') {
            $where[] = '(e.title LIKE ? OR p.title LIKE ?)';
            $like = '%' . $q . '%';
            $tipos .= 'ss';
            array_push($params, $like, $like);
        }
        if ($fPod > 0) {
            $where[] = 'p.id = ?';
            $tipos .= 'i';
            $params[] = $fPod;
        }
        if ($fEstado === 'PENDING' || $fEstado === 'PUBLISHED') {
            $where[] = 'e.status = ?';
            $tipos .= 's';
            $params[] = $fEstado;
        } elseif ($fEstado === 'LIVE') {
            $where[] = $hasLive ? "e.live_status IN ('live','upcoming')" : '1 = 0';
        }
        if ($desde !== '') {
            $where[] = 'DATE(e.publish_date) >= ?';
            $tipos .= 's';
            $params[] = $desde;
        }
        if ($hasta !== '') {
            $where[] = 'DATE(e.publish_date) <= ?';
            $tipos .= 's';
            $params[] = $hasta;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $from = "FROM episodes e
                 JOIN seasons s ON e.season_id = s.id
                 JOIN podcasts p ON s.podcast_id = p.id
                 LEFT JOIN channels c ON p.channel_id = c.id";

        $total = (int) (consultar($conn, "SELECT COUNT(*) AS n $from $whereSql", $tipos, $params)[0]['n'] ?? 0);
        $paginas = max(1, (int) ceil($total / $perPage));
        $page = min($page, $paginas);
        $offset = ($page - 1) * $perPage;

        $orden = $hasLive
            ? "CASE WHEN e.live_status = 'live' THEN 0 WHEN e.live_status = 'upcoming' THEN 1 WHEN e.status = 'PENDING' THEN 2 ELSE 3 END"
            : "CASE WHEN e.status = 'PENDING' THEN 2 ELSE 3 END";

        $lista = consultar(
            $conn,
            "SELECT e.*, s.number AS season_number, p.title AS podcast_title, c.name AS channel_name
             $from $whereSql
             ORDER BY $orden ASC, e.publish_date DESC
             LIMIT ? OFFSET ?",
            $tipos . 'ii',
            array_merge($params, [$perPage, $offset])
        );
        $podcastsFiltro = consultar($conn, "SELECT id, title FROM podcasts ORDER BY title");
        $hayFiltros = ($q !== '' || $fPod > 0 || $fEstado !== '' || $desde !== '' || $hasta !== '');
        ?>

        <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="section" value="<?= h($episodios) ?>">
            <input type="hidden" name="vista" value="<?= h($vista) ?>">
            <div class="col-12 col-lg-4">
                <label class="form-label small text-muted mb-1">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Título del capítulo o podcast...">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Podcast</label>
                <select name="podcast" class="form-select">
                    <option value="0">Todos</option>
                    <?php foreach ($podcastsFiltro as $pf): ?>
                        <option value="<?= (int) $pf['id'] ?>" <?= $fPod === (int) $pf['id'] ? 'selected' : '' ?>><?= h($pf['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">Todos</option>
                    <option value="PENDING" <?= $fEstado === 'PENDING' ? 'selected' : '' ?>>Pendientes</option>
                    <option value="PUBLISHED" <?= $fEstado === 'PUBLISHED' ? 'selected' : '' ?>>Publicados</option>
                    <option value="LIVE" <?= $fEstado === 'LIVE' ? 'selected' : '' ?>>En vivo / programados</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Desde</label>
                <input type="date" name="desde" value="<?= h($desde) ?>" class="form-control">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Hasta</label>
                <input type="date" name="hasta" value="<?= h($hasta) ?>" class="form-control">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter me-1"></i> Filtrar</button>
                    <?php if ($hayFiltros): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="index.php?section=<?= h($episodios) ?>&vista=<?= h($vista) ?>">Limpiar</a>
                    <?php endif; ?>
                    <span class="text-muted small align-self-center"><?= $total ?> episodio(s)</span>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Cambiar vista">
                    <a class="btn btn-outline-secondary <?= $vista === 'tarjetas' ? 'active' : '' ?>" href="<?= h(url_con(['vista' => 'tarjetas', 'page' => 1])) ?>"><i class="fas fa-th-large me-1"></i> Tarjetas</a>
                    <a class="btn btn-outline-secondary <?= $vista === 'lista' ? 'active' : '' ?>" href="<?= h(url_con(['vista' => 'lista', 'page' => 1])) ?>"><i class="fas fa-list me-1"></i> Lista</a>
                </div>
            </div>
        </form>

        <?php if ($lista): ?>

            <?php if ($vista === 'tarjetas'): ?>
                <div class="row g-4">
                    <?php foreach ($lista as $e):
                        $yt = !empty($e['youtube_id']) ? 'https://www.youtube.com/watch?v=' . rawurlencode($e['youtube_id']) : null;
                        $canalL = trim(mb_strtolower($e['channel_name'] ?? ''));
                        $podL   = trim(mb_strtolower($e['podcast_title'] ?? ''));
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="episode-card">
                                <?php if ($yt): ?>
                                    <a href="<?= h($yt) ?>" target="_blank" rel="noopener" class="episode-thumb-container" title="Ver en YouTube">
                                        <img src="https://i.ytimg.com/vi/<?= h($e['youtube_id']) ?>/hqdefault.jpg" class="episode-thumb" alt="Miniatura" loading="lazy">
                                        <span class="episode-play"><i class="fas fa-play-circle"></i></span>
                                    </a>
                                <?php else: ?>
                                    <div class="episode-thumb-container">
                                        <div class="episode-thumb d-flex align-items-center justify-content-center text-white-50"><i class="fas fa-microphone fs-1"></i></div>
                                    </div>
                                <?php endif; ?>

                                <div class="episode-body">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                        <h5 class="episode-title mb-0" title="<?= h($e['title']) ?>"><?= h($e['title']) ?></h5>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill text-nowrap">Cap. <?= h($e['number']) ?></span>
                                    </div>

                                    <div class="episode-meta">
                                        <span><i class="far fa-calendar-alt me-1"></i><?= h(fecha_corta($e['publish_date'])) ?></span>
                                        <span>•</span>
                                        <?= badge_episodio($e) ?>
                                    </div>

                                    <div class="episode-podcast-info flex-wrap small text-muted">
                                        <?php if ($canalL !== '' && $canalL !== $podL): ?>
                                            <span class="fw-semibold"><i class="fas fa-tv me-1"></i><?= h($e['channel_name']) ?></span>
                                            <span>•</span>
                                        <?php endif; ?>
                                        <span class="text-dark fw-medium"><i class="fas fa-podcast text-primary"></i> <?= h($e['podcast_title']) ?></span>
                                        <span>•</span>
                                        <span class="badge bg-light text-dark border">Temporada <?= h($e['season_number']) ?></span>
                                    </div>

                                    <div class="mt-auto pt-2 border-top d-flex justify-content-end gap-2">
                                        <?php if ($yt): ?>
                                            <a href="<?= h($yt) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-light text-danger border" title="Ver en YouTube">
                                                <i class="fab fa-youtube me-1"></i> Ver
                                            </a>
                                        <?php endif; ?>
                                        <a href="edit.php?type=<?= h($episodios) ?>&id=<?= (int) $e['id'] ?>" class="btn btn-sm btn-light text-primary border" title="Editar">
                                            <i class="fas fa-pen me-1"></i> Editar
                                        </a>
                                        <?= boton_borrar('episodios', (int) $e['id'], '¿Eliminar este episodio?') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Miniatura</th>
                                <th>Título</th>
                                <th>Podcast y Temporada</th>
                                <th>Cap.</th>
                                <th>Publicación</th>
                                <th>Duración</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista as $e):
                                $yt = !empty($e['youtube_id']) ? 'https://www.youtube.com/watch?v=' . rawurlencode($e['youtube_id']) : null;
                                $canalL = trim(mb_strtolower($e['channel_name'] ?? ''));
                                $podL   = trim(mb_strtolower($e['podcast_title'] ?? ''));
                            ?>
                                <tr>
                                    <td style="width:80px;">
                                        <?php if ($yt): ?>
                                            <a href="<?= h($yt) ?>" target="_blank" rel="noopener">
                                                <img src="https://i.ytimg.com/vi/<?= h($e['youtube_id']) ?>/mqdefault.jpg" class="img-thumb-preview" style="width:70px;height:42px;" loading="lazy" alt="">
                                            </a>
                                        <?php else: ?>
                                            <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted" style="width:70px;height:42px;"><i class="fas fa-microphone"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold text-dark">
                                        <?php if ($yt): ?>
                                            <a href="<?= h($yt) ?>" target="_blank" rel="noopener" class="text-dark text-decoration-none"><?= h($e['title']) ?></a>
                                        <?php else: ?>
                                            <?= h($e['title']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap small">
                                            <?php if ($canalL !== '' && $canalL !== $podL): ?>
                                                <span class="text-muted fw-semibold"><i class="fas fa-tv me-1"></i><?= h($e['channel_name']) ?></span>
                                                <span class="text-muted">•</span>
                                            <?php endif; ?>
                                            <span class="fw-medium text-dark"><i class="fas fa-podcast text-primary"></i> <?= h($e['podcast_title']) ?></span>
                                            <span class="badge bg-light text-dark border">T<?= h($e['season_number']) ?></span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Cap. <?= h($e['number']) ?></span></td>
                                    <td class="small text-muted"><?= h(fecha_corta($e['publish_date'])) ?></td>
                                    <td class="small text-muted"><?= badge_episodio($e) ?></td>
                                    <td class="text-end text-nowrap">
                                        <a href="edit.php?type=<?= h($episodios) ?>&id=<?= (int) $e['id'] ?>" class="btn btn-sm btn-light text-primary me-1" title="Editar"><i class="fas fa-pen"></i></a>
                                        <?= boton_borrar('episodios', (int) $e['id'], '¿Eliminar este episodio?', false, '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($paginas > 1): ?>
                <nav class="mt-4" aria-label="Paginación">
                    <ul class="pagination justify-content-center flex-wrap mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url_con(['page' => $page - 1])) ?>">&laquo;</a></li>
                        <?php for ($i = max(1, $page - 2); $i <= min($paginas, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h(url_con(['page' => $i])) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url_con(['page' => $page + 1])) ?>">&raquo;</a></li>
                    </ul>
                    <div class="text-center text-muted small mt-2">Página <?= $page ?> de <?= $paginas ?></div>
                </nav>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-<?= $hayFiltros ? 'search' : 'microphone-alt' ?>"></i>
                <h5><?= $hayFiltros ? 'No se encontraron coincidencias' : 'Sin Episodios' ?></h5>
                <p class="small"><?= $hayFiltros ? 'Prueba con otros filtros.' : 'Comienza publicando episodios en tus podcasts existentes.' ?></p>
            </div>
        <?php endif; ?>

    <?php elseif ($section === $calendario): ?>
        <?php
        // =====================================================================================
        // CALENDARIO SEMANAL (lunes a domingo de la semana actual)
        //  - Todo lo SUBIDO sale de los episodios reales (fecha de publicación; en directos, la de inicio).
        //  - Modo 'esperado': además se compara con el horario de cada podcast ACTIVO y se avisa:
        //        Atrasado      -> hoy, ya pasó la hora esperada (+ gracia) y no hay episodio
        //        No se subió   -> un día anterior de esta semana, tenía horario y no hubo episodio
        //        Esperado      -> aún no llega la hora
        //  - Modo 'real': solo lo subido, sin horarios ni avisos.
        // =====================================================================================
        $modo = $modoCalendario;
        $graciaMin = defined('CALENDARIO_GRACIA_MIN') ? (int) CALENDARIO_GRACIA_MIN : 60;
        $diasInactivo = defined('CALENDARIO_INACTIVO_DIAS') ? (int) CALENDARIO_INACTIVO_DIAS : 30;

        $n = (int) date('N');
        $lunes = new DateTime('today');
        $lunes->modify('-' . ($n - 1) . ' days');
        $semOff = $semanaCalendario;
        if ($semOff !== 0) {
            $lunes->modify(($semOff > 0 ? '+' : '') . $semOff . ' weeks');
        }
        // Los avisos (atrasado / no se subió) y los horarios solo tienen sentido para la semana actual y la próxima:
        // en semanas pasadas el horario de hoy no refleja lo que se esperaba entonces, así que solo se muestra lo subido.
        $mostrarAvisos = ($modo === 'esperado' && $semOff >= 0);
        $inicioSemana = $lunes->format('Y-m-d H:i:s');
        $finSemana = (clone $lunes)->modify('+7 days')->format('Y-m-d H:i:s');
        $hoyStr = date('Y-m-d');
        $ahora = time();

        /** ¿Se esperaba un episodio ese día, según la frecuencia del podcast y su último episodio previo? */
        function slot_esperado(?string $frecuencia, ?int $ultimoTs, int $diaTs): bool
        {
            $f = mb_strtolower(trim((string) $frecuencia), 'UTF-8');
            if ($f === '' || mb_strpos($f, 'semanal') !== false) {
                return true;                       // semanal (o sin dato): se espera cada semana
            }
            if (mb_strpos($f, 'quincenal') !== false) {
                $minDias = 10;
            } elseif (mb_strpos($f, 'mensual') !== false) {
                $minDias = 25;
            } else {
                return false;                      // esporádico / semestral / otro: nunca se avisa
            }
            return $ultimoTs === null || (($diaTs - $ultimoTs) / 86400) >= $minDias;
        }

        $colLive = tiene_live_status($conn) ? 'e.live_status' : "'none'";
        $subidos = consultar(
            $conn,
            "SELECT e.id, e.number, e.title, e.publish_date, e.youtube_id, $colLive AS live_status,
                    p.id AS podcast_id, p.title AS podcast_title, COALESCE(NULLIF(p.image, ''), c.image) AS image
             FROM episodes e
             JOIN seasons sn ON sn.id = e.season_id
             JOIN podcasts p ON p.id = sn.podcast_id
             LEFT JOIN channels c ON c.id = p.channel_id
             WHERE p.state != 'Finalizado' AND e.publish_date >= ? AND e.publish_date < ?
             ORDER BY e.publish_date ASC",
            'ss',
            [$inicioSemana, $finSemana]
        );

        $slots = [];
        $ultimoCalculo = null;
        if ($mostrarAvisos) {
            if (horarios_desactualizados($conn, 24)) {
                recalcular_horarios($conn);
            }
            $ultimoCalculo = consultar($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'schedule_updated_at'")[0]['setting_value'] ?? null;
            $slots = consultar(
                $conn,
                "SELECT s.day_of_week, s.start_time, s.is_manual, p.id AS podcast_id, p.title AS podcast_title, p.frequency,
                        COALESCE(NULLIF(p.image, ''), c.image) AS image,
                        COALESCE((SELECT MAX(e.number) FROM episodes e JOIN seasons sn ON sn.id = e.season_id WHERE sn.podcast_id = p.id), 0) + 1 AS siguiente,
                        (SELECT MAX(e2.publish_date) FROM episodes e2 JOIN seasons sn2 ON sn2.id = e2.season_id
                          WHERE sn2.podcast_id = p.id AND e2.publish_date < ?) AS ultimo_antes,
                        (SELECT MAX(e3.publish_date) FROM episodes e3 JOIN seasons sn3 ON sn3.id = e3.season_id
                          WHERE sn3.podcast_id = p.id) AS ultima_publicacion
                 FROM schedule s
                 JOIN podcasts p ON p.id = s.podcast_id
                 LEFT JOIN channels c ON c.id = p.channel_id
                 WHERE p.state = 'Activo'
                 ORDER BY s.start_time",
                's',
                [$inicioSemana]
            );
        }

        // Un episodio ya publicado -> elemento del calendario
        $desdeEpisodio = function (array $e, ?array $slot) use ($ahora): array {
            $ts = strtotime($e['publish_date']);
            $live = $e['live_status'] ?? 'none';
            $tipo = $live === 'live' ? 'en_vivo' : (($live === 'upcoming' || $ts > $ahora) ? 'programado' : 'subido');
            return [
                'tipo' => $tipo, 'ts' => $ts, 'hora' => date('H:i', $ts), 'title' => $e['podcast_title'], 'episodio' => $e['number'],
                'image' => $e['image'] ?? '', 'esperado' => $slot ? substr($slot['start_time'], 0, 5) : null,
                'estimado' => $slot ? !$slot['is_manual'] : false,
            ];
        };

        // Podcasts ACTIVOS sin ningún episodio en los últimos N días: no se les espera nada (se listan aparte)
        $limiteInactivo = date('Y-m-d H:i:s', strtotime("-$diasInactivo days"));
        $sinActividad = [];
        if ($mostrarAvisos) {
            $sinActividad = array_column(consultar(
                $conn,
                "SELECT p.title FROM podcasts p
                 WHERE p.state = 'Activo'
                   AND NOT EXISTS (SELECT 1 FROM episodes e JOIN seasons sn ON sn.id = e.season_id
                                   WHERE sn.podcast_id = p.id AND e.publish_date >= ?)
                 ORDER BY p.title",
                's',
                [$limiteInactivo]
            ), 'title');
        }

        $semana = [];
        $resumen = ['subido' => 0, 'atrasado' => [], 'no_subio' => []];
        foreach (DIAS_SEMANA as $i => $nombreDia) {
            $fecha = (clone $lunes)->modify("+$i days")->format('Y-m-d');
            $diaTs = strtotime($fecha);
            $subDia = array_values(array_filter($subidos, fn($e) => substr($e['publish_date'], 0, 10) === $fecha));

            // Horarios de ese día (sin duplicados exactos podcast + hora)
            $slotsDia = [];
            foreach ($slots as $sl) {
                if ($sl['day_of_week'] === $nombreDia) {
                    $slotsDia[$sl['podcast_id'] . '|' . $sl['start_time']] = $sl;
                }
            }
            $slotsDia = array_values($slotsDia);

            // Emparejar cada horario con el episodio subido más cercano a su hora (asignación global:
            // si un podcast tiene 2 horarios y sube 1 episodio, se asigna al horario que corresponde)
            $pares = [];
            foreach ($slotsDia as $a => $sl) {
                $slotTs = strtotime($fecha . ' ' . $sl['start_time']);
                foreach ($subDia as $k => $e) {
                    if ((int) $e['podcast_id'] === (int) $sl['podcast_id']) {
                        $pares[] = [abs(strtotime($e['publish_date']) - $slotTs), $a, $k];
                    }
                }
            }
            usort($pares, fn($x, $y) => $x[0] <=> $y[0]);
            $slotCon = [];   // índice de horario => índice de episodio
            $usado = [];     // índice de episodio => true
            foreach ($pares as [$dif, $a, $k]) {
                if (!isset($slotCon[$a]) && !isset($usado[$k])) {
                    $slotCon[$a] = $k;
                    $usado[$k] = true;
                }
            }

            $items = [];
            foreach ($slotsDia as $a => $sl) {
                $slotTs = strtotime($fecha . ' ' . $sl['start_time']);

                if (isset($slotCon[$a])) {                       // hubo episodio: se muestra lo real
                    $items[] = $desdeEpisodio($subDia[$slotCon[$a]], $sl);
                    continue;
                }

                // Sin episodio. ¿Corresponde avisar?
                // a) el podcast debe tener actividad reciente (si no, se considera inactivo y se oculta)
                $ventana = $diasInactivo;
                if (mb_strpos(mb_strtolower((string) $sl['frequency'], 'UTF-8'), 'mensual') !== false) {
                    $ventana = max($ventana, 45);                // un mensual no debe ocultarse justo cuando le toca
                }
                if (empty($sl['ultima_publicacion']) || strtotime($sl['ultima_publicacion']) < strtotime("-$ventana days")) {
                    continue;
                }
                // b) por frecuencia: semanal siempre; quincenal/mensual según el último episodio previo a ese día
                $ultimoTs = $sl['ultimo_antes'] ? strtotime($sl['ultimo_antes']) : null;
                foreach ($subidos as $e) {
                    if ((int) $e['podcast_id'] === (int) $sl['podcast_id'] && substr($e['publish_date'], 0, 10) < $fecha) {
                        $t = strtotime($e['publish_date']);
                        if ($ultimoTs === null || $t > $ultimoTs) {
                            $ultimoTs = $t;
                        }
                    }
                }
                if (!slot_esperado($sl['frequency'], $ultimoTs, $diaTs)) {
                    continue;
                }

                if ($fecha < $hoyStr) {
                    $tipo = 'no_subio';
                    $resumen['no_subio'][] = $sl['podcast_title'] . ' (' . $nombreDia . ')';
                } elseif ($fecha === $hoyStr && $slotTs + $graciaMin * 60 < $ahora) {
                    $tipo = 'atrasado';
                    $resumen['atrasado'][] = $sl['podcast_title'] . ' (' . substr($sl['start_time'], 0, 5) . ')';
                } else {
                    $tipo = 'esperado';
                }
                $items[] = [
                    'tipo' => $tipo, 'ts' => $slotTs, 'hora' => substr($sl['start_time'], 0, 5), 'title' => $sl['podcast_title'],
                    'episodio' => $sl['siguiente'], 'image' => $sl['image'] ?? '', 'esperado' => null, 'estimado' => !$sl['is_manual'],
                ];
            }

            // Episodios subidos que no correspondían a ningún horario
            foreach ($subDia as $k => $e) {
                if (!isset($usado[$k])) {
                    $items[] = $desdeEpisodio($e, null);
                }
            }
            usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']);
            foreach ($items as $it) {
                if ($it['tipo'] === 'subido' || $it['tipo'] === 'en_vivo') {
                    $resumen['subido']++;
                }
            }
            $semana[$nombreDia] = ['fecha' => $fecha, 'items' => $items, 'hoy' => $fecha === $hoyStr];
        }

        $insignias = [
            'subido'     => ['bg-success', 'fa-check', 'Subido'],
            'en_vivo'    => ['bg-danger', 'fa-circle', 'En vivo'],
            'programado' => ['bg-info text-dark', 'fa-calendar', 'Programado'],
            'atrasado'   => ['bg-warning text-dark', 'fa-hourglass-half', 'Atrasado'],
            'no_subio'   => ['bg-danger', 'fa-xmark', 'No se subió'],
            'esperado'   => ['bg-light text-secondary border', 'fa-clock', 'Esperado'],
        ];
        ?>

        <?php
        $finSem = (clone $lunes)->modify('+6 days');
        $etiquetaSemana = 'Semana del ' . $lunes->format('d/m') . ' al ' . $finSem->format('d/m')
            . (($lunes->format('Y') !== date('Y') || $finSem->format('Y') !== date('Y')) ? ' de ' . $finSem->format('Y') : '');
        ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="btn-group btn-group-sm" role="group" aria-label="Cambiar de semana">
                <a class="btn btn-outline-secondary <?= $semOff <= -52 ? 'disabled' : '' ?>" href="<?= h(url_calendario($calendario, $semOff - 1, $modo)) ?>">
                    <i class="fas fa-chevron-left me-1"></i> Semana anterior
                </a>
                <a class="btn btn-outline-secondary <?= $semOff >= 1 ? 'disabled' : '' ?>" href="<?= h(url_calendario($calendario, $semOff + 1, $modo)) ?>">
                    Semana siguiente <i class="fas fa-chevron-right ms-1"></i>
                </a>
            </div>
            <div class="fw-semibold">
                <?= h($etiquetaSemana) ?>
                <?php if ($semOff === 0): ?>
                    <span class="badge bg-primary ms-1">Esta semana</span>
                <?php else: ?>
                    <a class="btn btn-link btn-sm" href="<?= h(url_calendario($calendario, 0, $modo)) ?>">Volver a esta semana</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mostrarAvisos): ?>
            <?php if ($resumen['atrasado']): ?>
                <div class="alert alert-warning py-2 small">
                    <i class="fas fa-hourglass-half me-1"></i><strong>Atrasados hoy:</strong> <?= h(implode(', ', $resumen['atrasado'])) ?>
                </div>
            <?php endif; ?>
            <?php if ($resumen['no_subio']): ?>
                <div class="alert alert-danger py-2 small">
                    <i class="fas fa-xmark me-1"></i><strong>No se subieron esta semana:</strong> <?= h(implode(', ', $resumen['no_subio'])) ?>
                </div>
            <?php endif; ?>
            <?php if ($sinActividad): ?>
                <p class="text-muted small mb-2">
                    <i class="fas fa-eye-slash me-1"></i>
                    Sin avisos para <?= count($sinActividad) ?> podcast(s) activos sin episodios en los últimos <?= $diasInactivo ?> días:
                    <?= h(implode(', ', array_slice($sinActividad, 0, 6))) ?><?= count($sinActividad) > 6 ? ' y ' . (count($sinActividad) - 6) . ' más' : '' ?>.
                </p>
            <?php endif; ?>
            <p class="text-muted small mb-3">
                <i class="fas fa-circle-info me-1"></i>
                <?= (int) $resumen['subido'] ?> subido(s) <?= $semOff === 0 ? 'esta semana' : 'esa semana' ?>. Un episodio se marca <em>Atrasado</em> si pasan <?= $graciaMin ?> min de la hora esperada.
                <i class="far fa-clock ms-2 me-1"></i>"~" = horario estimado automáticamente (no fijado a mano).
                <?php if ($ultimoCalculo): ?> Horarios calculados: <?= h(fecha_corta($ultimoCalculo)) ?>.<?php endif; ?>
            </p>
        <?php else: ?>
            <p class="text-muted small mb-3">
                <i class="fas fa-check me-1"></i>Solo se muestran los episodios realmente subidos <?= $semOff === 0 ? 'esta semana' : 'esa semana' ?> (<?= (int) $resumen['subido'] ?>).
                <?php if ($modo === 'esperado' && $semOff < 0): ?>En semanas anteriores no se muestran avisos de atraso.<?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="row g-3">
            <?php foreach ($semana as $nombreDia => $dia): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 schedule-card <?= $dia['hoy'] ? 'is-today' : '' ?>">
                        <div class="card-header bg-light border-0 fw-bold py-3 d-flex align-items-center justify-content-between">
                            <span><?= h($nombreDia) ?> <span class="text-muted fw-normal small"><?= h(date('d/m', strtotime($dia['fecha']))) ?></span></span>
                            <?php if ($dia['hoy']): ?><span class="badge bg-white text-primary fw-normal">Hoy</span><?php endif; ?>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($dia['items']):
                                $totalDia = count($dia['items']);
                                foreach ($dia['items'] as $i => $it):
                                    [$claseB, $iconoB, $textoB] = $insignias[$it['tipo']]; ?>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php img_logo($it['image'], 'fa-podcast'); ?>
                                        <div style="min-width:0;">
                                            <h6 class="mb-0 fw-semibold text-truncate" style="max-width:150px;"><?= h($it['title']) ?></h6>
                                            <span class="badge <?= $claseB ?> mt-1"><i class="fas <?= $iconoB ?> me-1" style="font-size:.7em;"></i><?= $textoB ?></span>
                                            <span class="badge bg-light text-secondary border mt-1">Cap. <?= h($it['episodio']) ?></span>
                                            <div class="small text-muted mt-1">
                                                <i class="far fa-clock me-1"></i><?= ($it['estimado'] && in_array($it['tipo'], ['esperado', 'atrasado', 'no_subio'], true)) ? '~' : '' ?><?= h($it['hora']) ?> hs
                                                <?php if ($it['esperado'] && $it['esperado'] !== $it['hora']): ?>
                                                    <span class="ms-1">(esperado <?= h($it['esperado']) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($i < $totalDia - 1): ?><hr class="my-3 text-light-emphasis"><?php endif; ?>
                                <?php endforeach;
                            else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-calendar-minus fs-3 mb-2 d-block opacity-50"></i>
                                    <span class="small">Sin episodios</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'layout_footer.php'; ?>