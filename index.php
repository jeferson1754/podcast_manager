<?php // 
include('bd.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Podcast Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style id="app-style">
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            padding: 0;
            margin: 0;
            min-height: 100vh;
        }

        .navbar {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .nav-item .nav-link.active {
            background-color: #0d6efd;
            color: white !important;
            border-radius: 4px;
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 1rem;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .loading-spinner {
            display: flex;
            justify-content: center;
            padding: 3rem;
        }

        .form-label {
            font-weight: 500;
        }

        .footer {
            text-align: center;
            margin-top: 2rem;
            padding: 1rem;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .action-buttons .btn {
            margin-right: 0.5rem;
        }

        .state-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .state-active {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .state-inactive {
            background-color: #f8d7da;
            color: #842029;
        }

        .state-finished {
            background-color: #e2e3e5;
            color: #41464b;
        }

        .modal-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }

        .was-validated .form-control:invalid:focus,
        .was-validated .form-select:invalid:focus {
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white py-3">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                <i class="fas fa-podcast text-primary me-2"></i>Podcast Manager
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item me-2">
                        <a class="nav-link px-3 py-2 <?php echo (!isset($_GET['section']) || $_GET['section'] == 'podcasts') ? 'active' : ''; ?>" href="index.php?section=podcasts">Podcasts</a>
                    </li>
                    <li class="nav-item me-2">
                        <a class="nav-link px-3 py-2 <?php echo (isset($_GET['section']) && $_GET['section'] == 'seasons') ? 'active' : ''; ?>" href="index.php?section=seasons">Temporadas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 py-2 <?php echo (isset($_GET['section']) && $_GET['section'] == 'episodes') ? 'active' : ''; ?>" href="index.php?section=episodes">Episodios</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 py-2 <?php echo (isset($_GET['section']) && $_GET['section'] == 'schedule') ? 'active' : ''; ?>" href="index.php?section=schedule">Calendario Semanal</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-container">
        <!-- Section Title and Add Button -->
        <div class="table-header">
            <h2 id="current-section-title">
                <?php
                $section = isset($_GET['section']) ? $_GET['section'] : 'podcasts';
                echo ucfirst($section);
                ?>
            </h2>
            <div>
                <a href="add.php?type=<?php echo $section; ?>" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Nuevo Podcast
                </a>
            </div>
        </div>

        <!-- Data Tables (Only one is shown at a time based on section) -->
        <?php if (!isset($_GET['section']) || $_GET['section'] == 'podcasts'): ?>
            <div id="podcasts-table-container" class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Logo</th>
                            <th>Nombre</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

                        $result = $conn->query("SELECT * FROM podcasts ORDER BY id");

                        if ($result->num_rows > 0) {
                            $index = 1;
                            while ($podcast = $result->fetch_assoc()) {
                                $stateClass = $podcast['state'] === 'Activo' ? 'state-active' : ($podcast['state'] === 'Inactivo' ? 'state-inactive' : 'state-finished');
                                echo '<tr>';
                                echo '<td>' . $index++ . '</td>';
                                echo '<td>';
                                if (!empty($podcast['image'])) {
                                    echo '<img src="' . htmlspecialchars($podcast['image']) . '" alt="Imagen de ' . $podcast['title'] . '" class="img-thumbnail" style="max-width: 80px;">';
                                } else {
                                    echo '<span class="text-muted"><i class="fas fa-image"></i> Sin Imagen</span>';
                                }
                                echo '</td>';
                                echo '<td><a href="' . htmlspecialchars($podcast['description'], ENT_QUOTES, 'UTF-8') . '" target="_blank" style="text-decoration: none; color: black;">' . htmlspecialchars($podcast['title'], ENT_QUOTES, 'UTF-8') . '</a></td>';


                                echo '<td>
                    <form method="post" action="change_state.php" class="d-inline">
                      <input type="hidden" name="id" value="' . $podcast['id'] . '">
                      <input type="hidden" name="type" value="podcast">
                      <div class="dropdown">
                        <span class="state-badge ' . $stateClass . ' dropdown-toggle" role="button" data-bs-toggle="dropdown">
                          ' . $podcast['state'] . '
                        </span>
                        <ul class="dropdown-menu">
                          <li><button type="submit" name="state" value="Activo" class="dropdown-item">Activo</button></li>
                          <li><button type="submit" name="state" value="Inactivo" class="dropdown-item">Pausado</button></li>
                          <li><button type="submit" name="state" value="Finalizado" class="dropdown-item">Finalizado</button></li>
                        </ul>
                      </div>
                    </form>
                  </td>';
                                echo '<td class="action-buttons">
                    <a href="edit.php?type=podcasts&id=' . $podcast['id'] . '" class="btn btn-sm btn-outline-primary">
                      <i class="fas fa-edit"></i> Editar
                    </a>
                    <a href="delete.php?type=podcasts&id=' . $podcast['id'] . '" class="btn btn-sm btn-outline-danger" 
                       onclick="return confirm(\'Estas seguro de eliminar este podcast?\')">
                      <i class="fas fa-trash"></i> Eliminar
                    </a>
                  </td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="5" class="text-center">Sin Podcast</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
                <?php
                if ($result->num_rows == 0) {
                    echo '<div class="empty-state">
                          <i class="fas fa-podcast"></i>
                          <h4>Sin Podcast</h4>
                          <p>Click "Nuevo Podcast" para guardar tu primer podcast</p>
                        </div>';
                }
                ?>
            </div>
        <?php elseif ($_GET['section'] == 'seasons'): ?>
            <div id="seasons-table-container" class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Podcast</th>
                            <th>Numero</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // PHP code to fetch seasons from database
                        $result = $conn->query("SELECT s.*, p.title as podcast_title FROM seasons s 
                                                JOIN podcasts p ON s.podcast_id = p.id 
                                                ORDER BY p.title, s.number");
                        if ($result->num_rows > 0) {
                            $index = 1;
                            while ($season = $result->fetch_assoc()) {
                                echo '<tr>';
                                echo '<td>' . $index++ . '</td>';
                                echo '<td>' . htmlspecialchars($season['podcast_title']) . '</td>';
                                echo '<td>' . $season['number'] . '</td>';
                                echo '<td class="action-buttons">
                                  <a href="edit.php?type=seasons&id=' . $season['id'] . '" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i> Editar
                                  </a>
                                  <a href="delete.php?type=seasons&id=' . $season['id'] . '" class="btn btn-sm btn-outline-danger" 
                                     onclick="return confirm(\'Estas seguro de eliminar esta temporada?\')">
                                    <i class="fas fa-trash"></i> Eliminar
                                  </a>
                                </td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="6" class="text-center">Sin Temporadas</td></tr>';
                        }
                        ?>

                    </tbody>
                </table>
                <?php
                if ($result->num_rows == 0) {
                    echo '<div class="empty-state">
                           <i class="fas fa-layer-group"></i>
                            <h4>Sin Temporadaras</h4>
                          <p>Click "Nuevo Podcast" para guardar sus temporadas</p>
                        </div>';
                }
                ?>
            </div>
        <?php elseif ($_GET['section'] == 'episodes'): ?>
            <div id="episodes-table-container" class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Podcast</th>
                            <th>Temporada</th>
                            <th>N° Capitulo</th>
                            <th>Titulo</th>
                            <th>Duracion</th>
                            <th>Fecha Publicaion</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // PHP code to fetch episodes from database
                        $result = $conn->query("SELECT e.*, s.number as season_number, p.title as podcast_title 
                                                FROM episodes e 
                                                JOIN seasons s ON e.season_id = s.id 
                                                JOIN podcasts p ON s.podcast_id = p.id 
                                                ORDER BY p.title, s.number, e.number");

                        if ($result->num_rows > 0) {
                            $index = 1;
                            while ($episode = $result->fetch_assoc()) {
                                echo '<tr>';
                                echo '<td>' . $index++ . '</td>';
                                echo '<td>' . htmlspecialchars($episode['podcast_title']) . '</td>';
                                echo '<td> T' . $episode['season_number'] . '</td>';
                                echo '<td>' . $episode['number'] . '</td>';
                                echo '<td>' . htmlspecialchars($episode['title']) . '</td>';
                                echo '<td>' . $episode['duration'] . '</td>';
                                echo '<td>' . date('d-m-Y', strtotime($episode['publish_date'])) . '</td>';
                                echo '<td class="action-buttons">
                                  <a href="edit.php?type=episodes&id=' . $episode['id'] . '" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i> Editar
                                  </a>
                                  <a href="delete.php?type=episodes&id=' . $episode['id'] . '" class="btn btn-sm btn-outline-danger" 
                                     onclick="return confirm(\'Estas seguro de eliminar este episodio?\')">
                                    <i class="fas fa-trash"></i> Eliminar
                                  </a>
                                </td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="8" class="text-center">No episodes found</td></tr>';
                        }
                        ?>

                    </tbody>
                </table>
                <?php
                if ($result->num_rows == 0) {
                    echo '<div class="empty-state">
                          <i class="fas fa-microphone-alt"></i>
                          <h4>Sin capitulos</h4>
                          <p>Click "Nuevo Podcast" para crear nuevos episodios</p>
                        </div>';
                }
                ?>
            </div>
        <?php elseif ($_GET['section'] == 'schedule'): ?>
            <div id="schedule-container">
                <div class="row">
                    <?php

                    if ($conn->connect_error) {
                        die('Connection Error: ' . $conn->connect_error);
                    }

                    // Get schedule information (this is a placeholder, you'd need to create a schedule table in your DB)
                    $scheduleQuery = "SELECT * FROM schedule ORDER BY day_of_week, start_time";
                    $scheduleResult = $conn->query($scheduleQuery);



                    $days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

                    // Agrupar podcasts por día
                    $scheduleData = [];
                    if ($scheduleResult && $scheduleResult->num_rows > 0) {
                        while ($schedule = $scheduleResult->fetch_assoc()) {
                            $day = $schedule['day_of_week'];
                            if (!isset($scheduleData[$day])) {
                                $scheduleData[$day] = [];
                            }
                            $scheduleData[$day][] = $schedule;
                        }
                    }


                    // Mostrar programación por día
                    foreach ($days as $day) {
                        echo '<div class="col-md-6 col-lg-4 mb-4">';
                        echo '<div class="card h-100">';
                        // Cambiar color si es el día actual
                        $headerClass = ($day === $dia_actual) ? 'bg-success' : 'bg-primary';

                        echo '<div class="card-header ' . $headerClass . ' text-white">';
                        echo '<h5 class="mb-0">' . $day . '</h5>';
                        echo '</div>';
                        echo '<div class="card-body">';

                        if (isset($scheduleData[$day])) {
                            foreach ($scheduleData[$day] as $scheduleItem) {
                                $podcastId = $scheduleItem['podcast_id'];
                                $podcastQuery = "
                SELECT p.*, COALESCE(MAX(e.number) + 1, 1) AS episodio
                FROM podcasts p
                LEFT JOIN seasons s ON s.podcast_id = p.id
                LEFT JOIN episodes e ON e.season_id = s.id
                WHERE p.id = $podcastId
                GROUP BY p.id
            ";
                                $podcastResult = $conn->query($podcastQuery);

                                if ($podcastResult && $podcastResult->num_rows > 0) {
                                    $podcast = $podcastResult->fetch_assoc();

                                    echo '<div class="d-flex align-items-center mb-3">';
                                    if (!empty($podcast['image'])) {
                                        echo '<img src="' . htmlspecialchars($podcast['image']) . '" alt="' . htmlspecialchars($podcast['title']) . '" class="me-3 img-thumbnail" style="width: 80px; height: 80px; object-fit: cover;">';
                                    } else {
                                        echo '<div class="me-3 bg-light d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-podcast fa-2x text-secondary"></i></div>';
                                    }

                                    echo '<div>';
                                    echo '<h5 class="card-title mb-1">' . htmlspecialchars($podcast['title']) . '</h5>';
                                    $episodio = !empty($podcast['episodio']) ? htmlspecialchars($podcast['episodio']) : '?';
                                    echo '<p class="card-text small text-muted">Capítulo ' . $episodio . '</p>';
                                    echo '<div><strong>Hora:</strong> ' . htmlspecialchars($scheduleItem['start_time']) . '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            }
                        } else {
                            echo '<div class="text-center py-4">';
                            echo '<i class="fas fa-calendar-day fa-3x text-muted mb-3"></i>';
                            echo '<p>No hay podcast para este día</p>';
                            echo '</div>';
                        }

                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }

                    $conn->close();
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>Podcast Manager © <?= $año; ?></p>
        </div>
    </div>


    <!-- Bootstrap JS for dropdown functionality only -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>