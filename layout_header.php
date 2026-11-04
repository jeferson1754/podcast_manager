<?php
// layout_header.php — <head>, barra de navegación y apertura del contenedor principal.
// Requiere: $conn, $section, $podcast/$temporadas/$episodios/$calendario (de bd.php) y sesion.php cargado.

$tituloPagina = $tituloPagina ?? 'Podcast Manager';

// Ajustes del sitio
$meta = [];
if ($res = $conn->query("SELECT setting_key, setting_value FROM site_settings")) {
    while ($row = $res->fetch_assoc()) {
        $meta[$row['setting_key']] = $row['setting_value'];
    }
}

// Última sincronización + alerta si está desactualizada
$lastSyncRaw = $meta['last_sync'] ?? '';
$lastSync = 'Nunca';
$syncViejo = true;
$horasSinSync = null;
if ($lastSyncRaw !== '' && $lastSyncRaw !== 'Nunca') {
    try {
        $d = new DateTime($lastSyncRaw);
        $lastSync = $d->format('d-m-Y h:i a');
        $horasSinSync = (time() - $d->getTimestamp()) / 3600;
        $syncViejo = $horasSinSync > SYNC_ALERTA_HORAS;
    } catch (Throwable $e) {
    }
}

// Episodios detectados hoy / ayer
$episodiosHoy = (int) (consultar($conn, "SELECT COUNT(*) AS n FROM episodes WHERE DATE(detected_at) = CURDATE()")[0]['n'] ?? 0);
$episodiosAyer = (int) (consultar($conn, "SELECT COUNT(*) AS n FROM episodes WHERE DATE(detected_at) = CURDATE() - INTERVAL 1 DAY")[0]['n'] ?? 0);
$diferencia = $episodiosHoy - $episodiosAyer;

$flash = flash_pull();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= h($tituloPagina) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-white border-bottom py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-dark" href="index.php">
                <i class="fas fa-podcast text-primary fs-4"></i> Podcast Manager
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Menú">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="d-flex align-items-center bg-light border px-3 py-1 rounded-pill gap-3 my-2 my-lg-0" style="font-size: 0.85rem;">
                    <div class="text-secondary d-flex align-items-center gap-2">
                        <i class="fas fa-sync-alt <?= $syncViejo ? 'text-danger' : 'text-primary' ?>"></i>
                        <span>Sincronizado: <strong class="<?= $syncViejo ? 'text-danger' : 'text-dark' ?>"><?= h($lastSync) ?></strong></span>
                    </div>

                    <?php if ($syncViejo): ?>
                        <span class="badge bg-danger rounded-pill px-2 py-1" title="La sincronización no se ejecuta hace tiempo">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?= $horasSinSync === null ? 'Sin datos de sincronización' : 'Hace ' . floor($horasSinSync) . ' h' ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($episodiosHoy > 0): ?>
                        <span class="badge bg-success rounded-pill px-2 py-1 fw-semibold">
                            +<?= $episodiosHoy ?> hoy
                            <?php if ($diferencia !== 0): ?>
                                <span class="text-white-50">(<?= $diferencia > 0 ? '+' : '' ?><?= $diferencia ?> vs ayer)</span>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1 fw-normal">Sin nuevos hoy</span>
                    <?php endif; ?>
                </div>

                <ul class="nav nav-pills gap-1 align-items-center ms-auto">
                    <?php foreach ([$podcast => 'Podcasts',$episodios => 'Episodios', $calendario => 'Calendario'] as $key => $label): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $section === $key ? 'active' : '' ?>" href="index.php?section=<?= h($key) ?>"><?= h($label) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash[0]) ?> alert-dismissible fade show mt-3 mb-0" role="alert">
                <?= h($flash[1]) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endif; ?>