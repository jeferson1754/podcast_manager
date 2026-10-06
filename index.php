<?php
include('bd.php');

// Manejo seguro de variables para navegación
$podcast    = $podcast    ?? 'podcasts';
$temporadas = $temporadas ?? 'temporadas';
$episodios  = $episodios  ?? 'episodios';
$calendario = $calendario ?? 'calendario';
$año        = $año        ?? date('Y');

$section = $_GET['section'] ?? $podcast;
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Podcast Manager</title>

    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style id="app-style">
        :root {
            --bg-body: #f4f6f9;
            --card-radius: 12px;
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-body);
            color: #1e293b;
            min-height: 100vh;
        }

        /* Navbar */
        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.8rem 0;
        }

        .navbar-brand {
            font-weight: 700;
            color: #0f172a;
            font-size: 1.25rem;
        }

        .nav-pills .nav-link {
            color: #64748b;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
        }

        .nav-pills .nav-link:hover {
            color: var(--primary-color);
            background-color: #f1f5f9;
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
            color: #ffffff !important;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
        }

        /* Main Wrapper */
        .main-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            padding: 1.5rem;
            margin-top: 1.5rem;
            margin-bottom: 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1rem;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        /* Table Styling */
        .table {
            vertical-align: middle;
        }

        .table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 600;
            border-bottom-width: 1px;
            background-color: #f8fafc;
        }

        .img-thumb-preview {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        /* TARJETAS DE EPISODIOS AMPLIADAS */
        .episode-card {
            border: 1px solid #e2e8f0;
            border-radius: var(--card-radius);
            overflow: hidden;
            background: #ffffff;
            transition: all 0.2s ease-in-out;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .episode-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .episode-thumb-container {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            /* Relación de aspecto 16:9 */
            background-color: #0f172a;
        }

        .episode-thumb {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .episode-body {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .episode-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.4;
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .episode-meta {
            font-size: 0.85rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 0.75rem;
        }

        .episode-podcast-info {
            background-color: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 1rem;
        }

        /* Badges de estado */
        .badge-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.35em 0.75em;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 20px;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
        }

        .state-active {
            background-color: #dcfce7;
            color: #166534;
        }

        .state-inactive {
            background-color: #fef3c7;
            color: #92400e;
        }

        .state-finished {
            background-color: #f1f5f9;
            color: #475569;
        }

        /* Calendario */
        .schedule-card {
            border-radius: var(--card-radius);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        .schedule-card.is-today {
            border: 2px solid var(--primary-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 1rem;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .footer {
            text-align: center;
            padding: 1.5rem;
            font-size: 0.875rem;
            color: #94a3b8;
        }

        .badge.bg-warning i.fa-circle {
            color: #fd7e14 !important;
            /* Naranja fuerte */
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <i class="fas fa-podcast text-primary fs-4"></i> Podcast Manager
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="nav nav-pills ms-auto gap-1">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($section == $podcast) ? 'active' : ''; ?>" href="index.php?section=<?= $podcast ?>">Podcasts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($section == $temporadas) ? 'active' : ''; ?>" href="index.php?section=<?= $temporadas ?>">Temporadas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($section == $episodios) ? 'active' : ''; ?>" href="index.php?section=<?= $episodios ?>">Episodios</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($section == $calendario) ? 'active' : ''; ?>" href="index.php?section=<?= $calendario ?>">Calendario</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Container -->
    <div class="container">
        <div class="main-card">
            <!-- Header Section -->
            <div class="page-header">
                <div>
                    <h3 class="fw-bold mb-0 text-capitalize"><?= htmlspecialchars($section); ?></h3>
                    <p class="text-muted small mb-0">Gestiona y organiza el contenido de tu plataforma</p>
                </div>
                <div>
                    <a href="add.php?type=<?= urlencode($section); ?>" class="btn btn-primary d-inline-flex align-items-center gap-2">
                        <i class="fas fa-plus"></i> <span>Nuevo <?= htmlspecialchars($section); ?></span>
                    </a>
                </div>
            </div>

            <!-- TABLA PODCASTS -->
            <?php if ($section == $podcast): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th style="width: 80px;">Logo</th>
                                <th>Nombre del Podcast</th>
                                <th>Temporada</th>
                                <th class="text-center">Episodios</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Consulta mejorada con conteo de episodios y última temporada
                            $sql = "SELECT p.*, 
                           (SELECT COUNT(*) FROM episodes e WHERE e.podcast_id = p.id) as total_episodios,
                           (SELECT title FROM seasons s WHERE s.podcast_id = p.id ORDER BY s.number DESC LIMIT 1) as temporada_actual
                    FROM podcasts p 
                    ORDER BY p.id";

                            $result = $conn->query($sql);
                            if ($result && $result->num_rows > 0):
                                while ($podcasts = $result->fetch_assoc()):
                                    $stateClass = $podcasts['state'] === 'Activo' ? 'state-active' : ($podcasts['state'] === 'Inactivo' ? 'state-inactive' : 'state-finished');
                            ?>
                                    <tr>
                                        <td class="fw-semibold text-muted"><?= $podcasts['id']; ?></td>
                                        <td>
                                            <?php if (!empty($podcasts['image'])): ?>
                                                <img src="<?= htmlspecialchars($podcasts['image']); ?>" alt="Logo" class="img-thumb-preview rounded">
                                            <?php else: ?>
                                                <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted rounded">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars($podcasts['description']); ?>" target="_blank" class="fw-semibold text-dark text-decoration-none">
                                                <?= htmlspecialchars($podcasts['title']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-layer-group me-1 text-muted"></i>
                                                <?= htmlspecialchars($podcasts['temporada_actual'] ?? 'Sin Temporada'); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1">
                                                <i class="fas fa-headphones me-1"></i> <?= $podcasts['total_episodios']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="post" action="change_state.php" class="d-inline">
                                                <input type="hidden" name="id" value="<?= $podcasts['id']; ?>">
                                                <input type="hidden" name="type" value="podcast">
                                                <div class="dropdown">
                                                    <span class="badge-state <?= $stateClass; ?> dropdown-toggle" role="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-circle fs-6 me-1" style="font-size: 8px !important;"></i> <?= $podcasts['state']; ?>
                                                    </span>
                                                    <ul class="dropdown-menu shadow-sm border-0">
                                                        <li><button type="submit" name="state" value="Activo" class="dropdown-item">Activo</button></li>
                                                        <li><button type="submit" name="state" value="Inactivo" class="dropdown-item">Pausado</button></li>
                                                        <li><button type="submit" name="state" value="Finalizado" class="dropdown-item">Finalizado</button></li>
                                                    </ul>
                                                </div>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <a href="edit.php?type=podcasts&id=<?= $podcasts['id']; ?>" class="btn btn-sm btn-light text-primary me-1 border" title="Editar">
                                                <i class="fas fa-pen"></i> Editar
                                            </a>
                                            <a href="delete.php?type=podcasts&id=<?= $podcasts['id']; ?>" class="btn btn-sm btn-light text-danger border" onclick="return confirm('¿Estás seguro de eliminar este podcast?')" title="Eliminar">
                                                <i class="fas fa-trash"></i> Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
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

                <!-- TABLA TEMPORADAS -->
            <?php elseif ($section == $temporadas): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th style="width: 80px;">Logo</th>
                                <th>Podcast</th>
                                <th>Temporada</th>
                                <th class="text-center">Episodios</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Consulta mejorada trayendo la imagen del podcast y el conteo de episodios por temporada
                            $sql = "SELECT s.*, p.title as podcast_title, p.image as podcast_image,
                           (SELECT COUNT(*) FROM episodes e WHERE e.season_id = s.id) as total_episodios
                    FROM seasons s 
                    JOIN podcasts p ON s.podcast_id = p.id 
                    ORDER BY p.title, s.number";

                            $result = $conn->query($sql);
                            if ($result && $result->num_rows > 0):
                                while ($season = $result->fetch_assoc()):
                            ?>
                                    <tr>
                                        <td class="fw-semibold text-muted"><?= $season['id']; ?></td>
                                        <td>
                                            <?php if (!empty($season['podcast_image'])): ?>
                                                <img src="<?= htmlspecialchars($season['podcast_image']); ?>" alt="Logo" class="img-thumb-preview rounded">
                                            <?php else: ?>
                                                <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted rounded">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($season['podcast_title']); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <i class="fas fa-layer-group me-1 text-muted"></i>
                                                <?= htmlspecialchars($season['title'] ?: 'Temporada ' . $season['number']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1">
                                                <i class="fas fa-headphones me-1"></i> <?= $season['total_episodios']; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="edit.php?type=temporadas&id=<?= $season['id']; ?>" class="btn btn-sm btn-light text-primary me-1 border" title="Editar">
                                                <i class="fas fa-pen"></i> Editar
                                            </a>
                                            <a href="delete.php?type=temporadas&id=<?= $season['id']; ?>" class="btn btn-sm btn-light text-danger border" onclick="return confirm('¿Estás seguro de eliminar esta temporada?')" title="Eliminar">
                                                <i class="fas fa-trash"></i> Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-layer-group fa-2x mb-2 text-muted"></i>
                                            <h5>Sin Temporadas registradas</h5>
                                            <p class="small text-muted">Agrega temporadas para organizar tus episodios.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- VISTA DE EPISODIOS CON FILTRO Y CAMBIO DE VISTA -->
            <?php elseif ($section == $episodios): ?>
                <?php
                $result = $conn->query("SELECT e.*, s.number as season_number, p.title as podcast_title 
                        FROM episodes e 
                        JOIN seasons s ON e.season_id = s.id 
                        JOIN podcasts p ON s.podcast_id = p.id 
                        ORDER BY 
                            CASE 
                                WHEN e.status = 'PENDING' THEN 1
                                WHEN DATE(e.publish_date) = CURDATE() AND e.duration = '00:00:00' THEN 2
                                ELSE 3
                            END ASC, 
                            e.publish_date DESC");

                $episodes_list = [];
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $episodes_list[] = $row;
                    }
                }
                ?>

                <!-- Barra de Herramientas: Búsqueda y Selector de Vista -->
                <div class="row g-3 align-items-center mb-4">
                    <div class="col-md-6 col-lg-8">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" id="searchEpisode" class="form-control border-start-0 ps-0" placeholder="Buscar por título de capítulo o podcast...">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 d-flex justify-content-md-end">
                        <div class="btn-group" role="group" aria-label="Cambiar vista">
                            <button type="button" class="btn btn-outline-secondary active" id="btnViewGrid" title="Vista Tarjetas">
                                <i class="fas fa-th-large me-1"></i> Tarjetas
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnViewList" title="Vista Lista">
                                <i class="fas fa-list me-1"></i> Lista
                            </button>
                        </div>
                    </div>
                </div>

                <?php if (!empty($episodes_list)): ?>
                    <!-- 1. VISTA TARJETAS (Grid) -->
                    <div id="episodesGridView" class="row g-4">
                        <?php foreach ($episodes_list as $episode):
                            $searchData = strtolower($episode['title'] . ' ' . $episode['podcast_title']);
                        ?>
                            <div class="col-md-6 col-lg-4 episode-item" data-search="<?= htmlspecialchars($searchData); ?>">
                                <div class="episode-card">
                                    <div class="episode-thumb-container">
                                        <?php if (!empty($episode['youtube_id'])): ?>
                                            <img src="https://i.ytimg.com/vi/<?= htmlspecialchars($episode['youtube_id']); ?>/hqdefault.jpg" class="episode-thumb" alt="Miniatura">
                                        <?php else: ?>
                                            <div class="episode-thumb d-flex align-items-center justify-content-center text-white-50">
                                                <i class="fas fa-play-circle fs-1"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="episode-body">
                                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                            <h5 class="episode-title mb-0" title="<?= htmlspecialchars($episode['title']); ?>">
                                                <?= htmlspecialchars($episode['title']); ?>
                                            </h5>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
                                                Cap. <?= $episode['number']; ?>
                                            </span>
                                        </div>

                                        <div class="episode-meta">
                                            <span><i class="far fa-calendar-alt me-1"></i><?= date('d/m/Y H:i', strtotime($episode['publish_date'])); ?></span>
                                            <span>•</span>
                                            <?php
                                            $fechaEpisodio = date('Y-m-d', strtotime($episode['publish_date']));
                                            $hoy = date('Y-m-d');
                                            $status = $episode['status'] ?? ''; // Aseguramos que lea el status

                                            // Condición 1: Si es un directo de hoy con duración 00:00:00 -> Círculo Rojo (En vivo)
                                            if ($fechaEpisodio === $hoy && $episode['duration'] === '00:00:00'):
                                            ?>
                                                <span class="badge bg-danger text-white px-2 py-1">
                                                    <i class="fas fa-circle me-1" style="font-size: 6px; vertical-align: middle;"></i> En vivo
                                                </span>
                                            <?php
                                            // Condición 2: Si el estado es PENDING -> Círculo Naranja
                                            elseif ($status === 'PENDING'):
                                            ?>
                                                <span class="badge bg-warning text-dark px-2 py-1" title="Pendiente en playlist">
                                                    <i class="fas fa-circle me-1 text-orange" style="font-size: 6px; vertical-align: middle;"></i> Pendiente
                                                </span>
                                            <?php else: ?>
                                                <span><i class="far fa-clock me-1"></i><?= htmlspecialchars($episode['duration']); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="episode-podcast-info">
                                            <i class="fas fa-podcast text-primary"></i>
                                            <span class="text-truncate" style="max-width: 180px;">
                                                <?= htmlspecialchars($episode['podcast_title']); ?>
                                            </span>
                                            <span class="text-muted">•</span>
                                            <span class="badge bg-light text-dark border">
                                                Temporada <?= $episode['season_number']; ?>
                                            </span>
                                        </div>

                                        <div class="mt-auto pt-2 border-top d-flex justify-content-end gap-2">
                                            <a href="edit.php?type=<?= $episodios; ?>&id=<?= $episode['id']; ?>" class="btn btn-sm btn-light text-primary border" title="Editar">
                                                <i class="fas fa-pen me-1"></i> Editar
                                            </a>
                                            <a href="delete.php?type=<?= $episodios; ?>&id=<?= $episode['id']; ?>" class="btn btn-sm btn-light text-danger border" onclick="return confirm('¿Estás seguro de eliminar este episodio?')" title="Eliminar">
                                                <i class="fas fa-trash me-1"></i> Eliminar
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- 2. VISTA LISTA (Tabla Oculta inicialmente) -->
                    <div id="episodesListView" class="table-responsive d-none">
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
                                <?php foreach ($episodes_list as $episode):
                                    $searchData = strtolower($episode['title'] . ' ' . $episode['podcast_title']);
                                ?>
                                    <tr class="episode-item" data-search="<?= htmlspecialchars($searchData); ?>">
                                        <td style="width: 80px;">
                                            <?php if (!empty($episode['youtube_id'])): ?>
                                                <img src="https://i.ytimg.com/vi/<?= htmlspecialchars($episode['youtube_id']); ?>/mqdefault.jpg" class="img-thumb-preview" style="width:70px; height: 42px;">
                                            <?php else: ?>
                                                <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted" style="width:70px; height: 42px;">
                                                    <i class="fas fa-microphone"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($episode['title']); ?></td>
                                        <td>
                                            <span class="fw-medium"><?= htmlspecialchars($episode['podcast_title']); ?></span>
                                            <span class="badge bg-light text-dark border ms-1">T<?= $episode['season_number']; ?></span>
                                        </td>
                                        <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Cap. <?= $episode['number']; ?></span></td>
                                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($episode['publish_date'])); ?></td>
                                        <td class="small text-muted">
                                            <?php
                                            $fechaEpisodio = date('Y-m-d', strtotime($episode['publish_date']));
                                            $hoy = date('Y-m-d');
                                            $status = $episode['status'] ?? '';

                                            if ($fechaEpisodio === $hoy && $episode['duration'] === '00:00:00'):
                                            ?>
                                                <span class="badge bg-danger text-white px-2 py-1">
                                                    <i class="fas fa-circle me-1" style="font-size: 6px; vertical-align: middle;"></i> En vivo
                                                </span>
                                            <?php elseif ($status === 'PENDING'): ?>
                                                <span class="badge bg-warning text-dark px-2 py-1" title="Pendiente en playlist">
                                                    <i class="fas fa-circle me-1" style="font-size: 6px; vertical-align: middle;"></i> Pendiente
                                                </span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($episode['duration']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="edit.php?type=<?= $episodios; ?>&id=<?= $episode['id']; ?>" class="btn btn-sm btn-light text-primary me-1" title="Editar">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <a href="delete.php?type=<?= $episodios; ?>&id=<?= $episode['id']; ?>" class="btn btn-sm btn-light text-danger" onclick="return confirm('¿Estás seguro?')" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mensaje si no hay resultados tras el filtro de búsqueda -->
                    <div id="noResultsMsg" class="empty-state d-none">
                        <i class="fas fa-search mb-2"></i>
                        <h5>No se encontraron coincidencias</h5>
                        <p class="small">Intenta buscar con otros términos.</p>
                    </div>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-microphone-alt"></i>
                        <h5>Sin Episodios</h5>
                        <p class="small">Comienza publicando episodios en tus podcast existentes.</p>
                    </div>
                <?php endif; ?>

                <!-- CALENDARIO -->
            <?php elseif ($section == $calendario):

                // ==========================================
                // 1. FUNCIÓN DE CÁLCULO Y ACTUALIZACIÓN AUTOMÁTICA
                // ==========================================
                // ==========================================
                // FUNCIÓN PARA MÚLTIPLES HORARIOS SEMANALES
                // ==========================================
                function actualizarHorariosAutomaticos($conn)
                {
                    $podcastsResult = $conn->query("SELECT id FROM podcasts");
                    if (!$podcastsResult) return;

                    $dias_map_es = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];

                    while ($pod = $podcastsResult->fetch_assoc()) {
                        $podcastId = $pod['id'];

                        // 1. Obtener los últimos 20 episodios
                        $stmt = $conn->prepare("SELECT publish_date FROM episodes WHERE podcast_id = ? ORDER BY publish_date DESC LIMIT 20");
                        $stmt->bind_param("i", $podcastId);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        $episodios = [];
                        while ($row = $result->fetch_assoc()) {
                            $episodios[] = strtotime($row['publish_date']);
                        }

                        if (count($episodios) < 3) continue;

                        // 2. Agrupar por Día y REDONDEAR a la hora en punto (ej: 17:13 y 17:16 pasan a ser las 17:00)
                        $patrones = [];
                        foreach ($episodios as $timestamp) {
                            $diaSemana = $dias_map_es[date('w', $timestamp)];
                            $horaRedondeada = date('H:00:00', $timestamp); // Agrupa por hora exacta

                            $key = $diaSemana . '_' . $horaRedondeada;
                            if (!isset($patrones[$key])) {
                                $patrones[$key] = [
                                    'day' => $diaSemana,
                                    'time' => date('H:i:s', $timestamp), // Mantiene la hora exacta promedio o del primero
                                    'count' => 0
                                ];
                            }
                            $patrones[$key]['count']++;
                        }

                        // Ordenar los patrones por los que más se repiten
                        usort($patrones, function ($a, $b) {
                            return $b['count'] - $a['count'];
                        });

                        // 3. Filtrar para evitar que un mismo podcast aparezca 2 veces EL MISMO DÍA.
                        // Nos quedaremos con el mejor horario por cada día de la semana detectado.
                        $diasRegistrados = [];
                        $horariosValidos = [];

                        foreach ($patrones as $p) {
                            $dia = $p['day'];
                            // Si este día ya tiene un horario asignado para este podcast, lo ignoramos para evitar duplicados en el mismo día
                            if (!isset($diasRegistrados[$dia])) {
                                $diasRegistrados[$dia] = true;
                                $horariosValidos[] = $p;
                            }
                        }

                        // 4. Limpiar los horarios viejos de este podcast y guardar los limpios y filtrados
                        if (!empty($horariosValidos)) {
                            $stmtDel = $conn->prepare("DELETE FROM schedule WHERE podcast_id = ?");
                            $stmtDel->bind_param("i", $podcastId);
                            $stmtDel->execute();

                            $stmtIns = $conn->prepare("INSERT INTO schedule (podcast_id, day_of_week, start_time) VALUES (?, ?, ?)");
                            foreach ($horariosValidos as $h) {
                                // Opcional: si quieres mostrar la hora exacta del último o la redondeada, puedes usar $h['time'] o la redondeada
                                $stmtIns->bind_param("iss", $podcastId, $h['day'], $h['time']);
                                $stmtIns->execute();
                            }
                        }
                    }
                }

                // Ejecutar al cargar la página
                actualizarHorariosAutomaticos($conn);
            ?>

                <!-- ========================================== -->
                <!-- 2. TU VISTA HTML DEL HORARIO SEMANAL       -->
                <!-- ========================================== -->
                <div class="row g-3">
                    <?php
                    $dias_map = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
                    $dia_actual = $dias_map[date('w')];

                    // Consulta que lee los horarios ya actualizados y calcula el próximo capítulo
                    $scheduleQuery = "
        SELECT s.id AS schedule_id, s.day_of_week, s.start_time, p.id AS podcast_id, p.title, p.image,
               COALESCE(MAX(e.number) + 1, 1) AS episodio
        FROM schedule s
        JOIN podcasts p ON s.podcast_id = p.id
        LEFT JOIN seasons sn ON sn.podcast_id = p.id
        LEFT JOIN episodes e ON e.season_id = sn.id
        GROUP BY s.id
        ORDER BY FIELD(s.day_of_week, 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'), s.start_time;";

                    $result = $conn->query($scheduleQuery);
                    $days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
                    $scheduleData = [];

                    if ($result && $result->num_rows > 0) {
                        while ($item = $result->fetch_assoc()) {
                            $scheduleData[$item['day_of_week']][] = $item;
                        }
                    }

                    foreach ($days as $day):
                        $isToday = ($day === $dia_actual);
                    ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="card h-100 schedule-card <?= $isToday ? 'is-today' : ''; ?>">
                                <div class="card-header bg-light border-0 fw-bold py-3 d-flex align-items-center justify-content-between">
                                    <span><?= htmlspecialchars($day); ?></span>
                                    <?php if ($isToday): ?>
                                        <span class="badge bg-white text-primary fw-normal">Hoy</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-3">
                                    <?php if (isset($scheduleData[$day])):
                                        $total = count($scheduleData[$day]);
                                        foreach ($scheduleData[$day] as $index => $item):
                                    ?>
                                            <div class="d-flex align-items-center gap-3">
                                                <?php if (!empty($item['image'])): ?>
                                                    <img src="<?= htmlspecialchars($item['image']); ?>" class="img-thumb-preview" style="width: 50px; height: 50px;">
                                                <?php else: ?>
                                                    <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-text text-muted bg-light">
                                                        <i class="fas fa-podcast"></i>
                                                    </div>
                                                <?php endif; ?>

                                                <div>
                                                    <h6 class="mb-0 fw-semibold text-truncate" style="max-width: 140px;"><?= htmlspecialchars($item['title']); ?></h6>
                                                    <span class="badge bg-light text-secondary border mt-1">Cap. <?= $item['episodio']; ?></span>
                                                    <div class="small text-muted mt-1">
                                                        <i class="far fa-clock me-1"></i><?= date("H:i", strtotime($item['start_time'])); ?> hs
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($index < $total - 1): ?>
                                                <hr class="my-3 text-light-emphasis">
                                            <?php endif; ?>
                                        <?php endforeach;
                                    else: ?>
                                        <div class="text-center py-4 text-muted">
                                            <i class="fas fa-calendar-minus fs-3 mb-2 d-block opacity-50"></i>
                                            <span class="small">Sin entregas</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="mb-0">Podcast Manager &copy; <?= $año; ?> — Sistema de Gestión</p>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script de Filtro y Alternador de Vistas -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btnGrid = document.getElementById('btnViewGrid');
            const btnList = document.getElementById('btnViewList');
            const gridView = document.getElementById('episodesGridView');
            const listView = document.getElementById('episodesListView');
            const searchInput = document.getElementById('searchEpisode');
            const noResults = document.getElementById('noResultsMsg');

            if (btnGrid && btnList) {
                // Alternar a vista Grid
                btnGrid.addEventListener('click', function() {
                    btnGrid.classList.add('active');
                    btnList.classList.remove('active');
                    gridView.classList.remove('d-none');
                    listView.classList.add('d-none');
                });

                // Alternar a vista Lista
                btnList.addEventListener('click', function() {
                    btnList.classList.add('active');
                    btnGrid.classList.remove('active');
                    listView.classList.remove('d-none');
                    gridView.classList.add('d-none');
                });
            }

            if (searchInput) {
                // Búsqueda / Filtrado en vivo
                searchInput.addEventListener('keyup', function() {
                    const query = searchInput.value.toLowerCase().trim();
                    const items = document.querySelectorAll('.episode-item');
                    let visibleCount = 0;

                    items.forEach(function(item) {
                        const searchContent = item.getAttribute('data-search') || '';
                        if (searchContent.includes(query)) {
                            item.classList.remove('d-none');
                            visibleCount++;
                        } else {
                            item.classList.add('d-none');
                        }
                    });

                    // Mostrar mensaje de sin resultados si corresponde
                    if (noResults) {
                        if (visibleCount === 0) {
                            noResults.classList.remove('d-none');
                        } else {
                            noResults.classList.add('d-none');
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>