<?php
$type = isset($_GET['type']) ? $_GET['type'] : $podcast;

include('bd.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($conn->connect_error) {
        die('Connection Error');
    }
    if ($type ==$podcast) {
        $title =$_POST['title'];
        $image =$_POST['image'];
        $link =$_POST['link'];
        $state =$_POST['state'];
        $stmt =$conn->prepare("INSERT INTO podcasts (title, image, description, state) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title,$image, $link,$state);
        $stmt->execute();$stmt->close();
    } elseif ($type == $temporadas) {$podcast_id = $_POST['podcast_id'];$number = $_POST['number'];$title = $_POST['title'] ?? ('Temporada ' .$number);

        $stmt =$conn->prepare("SELECT id FROM seasons WHERE podcast_id = ? AND number = ?");
        $stmt->bind_param("ii", $podcast_id, $number);$stmt->execute();
        $result =$stmt->get_result();

        if ($result->num_rows > 0) {$row = $result->fetch_assoc();$season_id = $row['id'];$stmt->close();

            $stmtUpdate =$conn->prepare("UPDATE seasons SET title = ? WHERE id = ?");
            $stmtUpdate->bind_param("si", $title,$season_id);
            $stmtUpdate->execute();$stmtUpdate->close();
        } else {
            $stmt->close();

            $stmtInsert =$conn->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, ?, ?)");
            $stmtInsert->bind_param("iis", $podcast_id, $number,$title);
            $stmtInsert->execute();$stmtInsert->close();
        }
    } elseif ($type == $episodios) {$season_id = $_POST['season_id'];$number = $_POST['number'];$title = $_POST['title'];$duration = $_POST['duration'];$publish_date = $_POST['publish_date'];$youtube_link = trim($_POST['youtube_link'] ?? '');$youtube_id = null;
        if (!empty($youtube_link)) {
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $youtube_link,$match)) {
                $youtube_id =$match[1];
            }
        }

        $stmt =$conn->prepare("INSERT INTO episodes (season_id, number, title, duration, publish_date, youtube_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $season_id,$number, $title,$duration, $publish_date,$youtube_id);
        $stmt->execute();$stmt->close();
    } elseif ($type ==$calendario) {
        $podcast_id =$_POST['schedule_id'];
        $number =$_POST['start_time'];
        $duration =$_POST['day'];
        $stmt =$conn->prepare("INSERT INTO `schedule`( `day_of_week`, `start_time`, `podcast_id`) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $duration, $number,$podcast_id);
        $stmt->execute();$stmt->close();
    }
    header('Location: index.php?section=' . $type);
    exit;
} 
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agregar Nuevos <?php echo ucfirst($type); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="container mt-5 mb-5">
        <h2>Agregar Nuevos <?php echo ucfirst($type); ?></h2>
        <form action="" method="post">
            <?php if ($type ==$podcast): ?>
                <div class="mb-3">
                    <label class="form-label">Titulo</label>
                    <input type="text" class="form-control" name="title" required>
                </div>
                <div class="mb-3"> <label class="form-label">Imagen (Link)</label>
                    <input type="text" class="form-control" name="image" required>
                </div>
                <div class="mb-3"> <label class="form-label">Link de Youtube</label>
                    <input type="text" class="form-control" name="link" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Estado</label>
                    <select class="form-select" name="state" required>
                        <option value="Activo">Activo</option>
                        <option value="Pausado">Pausado</option>
                        <option value="Finalizado">Finalizado</option>
                    </select>
                </div>
            <?php elseif ($type ==$temporadas): 
                $result =$conn2->query("SELECT podcasts.id, podcasts.title, COALESCE(MAX(seasons.number), 0) + 1 AS number FROM podcasts LEFT JOIN seasons ON seasons.podcast_id = podcasts.id GROUP BY podcasts.id, podcasts.title;");
            ?>
                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <select class="form-select" name="podcast_id" id="podcastSelect" required onchange="actualizarTemporada()">
                        <option value="" disabled selected>Selecciona un podcast</option>
                        <?php while ($pod =$result->fetch_assoc()): ?>
                            <option value="<?php echo $pod['id']; ?>" data-number="<?php echo $pod['number']; ?>">
                                <?php echo htmlspecialchars($pod['title']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Número de Temporada (+1)</label>
                    <input type="number" class="form-control" name="number" min="1" id="seasonNumber" required>
                </div>
                <script>
                    function actualizarTemporada() {
                        const select = document.getElementById('podcastSelect');
                        const selectedOption = select.options[select.selectedIndex];
                        const nextSeasonNumber = selectedOption.getAttribute('data-number');
                        document.getElementById('seasonNumber').value = nextSeasonNumber;
                    }
                </script>

            <?php elseif ($type ==$episodios): 
                $result =$conn2->query("
                SELECT 
                    s.id AS season_id,
                    p.id AS podcast_id,
                    p.title as title, 
                    s.number AS temporada,
                    COALESCE(MAX(e.number), 0) + 1 AS capitulo
                FROM podcasts p
                INNER JOIN seasons s ON s.podcast_id = p.id
                LEFT JOIN episodes e ON e.season_id = s.id
                WHERE s.number = (
                    SELECT MAX(s2.number)
                    FROM seasons s2
                    WHERE s2.podcast_id = p.id
                )
                GROUP BY s.id, p.id, p.title, s.number;
            ");
            ?>
                <!-- BLOQUE AUTOCOMPLETAR VÍA API DE YOUTUBE -->
                <div class="mb-3 p-3 bg-light border rounded">
                    <label class="form-label fw-bold text-primary"><i class="fab fa-youtube me-1"></i> Autocompletar con API de YouTube</label>
                    <div class="input-group">
                        <input type="url" class="form-control" id="youtubeLinkInput" name="youtube_link" placeholder="https://www.youtube.com/watch?v=...">
                        <button class="btn btn-outline-primary" type="button" id="btnAutoFill">
                            <i class="fas fa-magic me-1"></i> Cargar con API
                        </button>
                    </div>
                    <div class="form-text" id="autoFillFeedback">Pega el link para traer título, duración y fecha exacta en zona horaria Santiago.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Temporada</label>
                    <select class="form-select" name="season_id" id="seasonSelect" required onchange="actualizarCapitulo()">
                        <option value="" disabled selected>Selecciona una temporada</option>
                        <?php while ($season =$result->fetch_assoc()): ?>
                            <option
                                value="<?php echo $season['season_id']; ?>"
                                data-capitulo="<?php echo $season['capitulo']; ?>">
                                <?php echo htmlspecialchars($season['title']) . ' - Temporada ' .$season['temporada']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Número de Episodio</label>
                    <input type="number" class="form-control" name="number" id="episodeNumber" min="1" required>
                </div>

                <script>
                    function actualizarCapitulo() {
                        const select = document.getElementById('seasonSelect');
                        const selectedOption = select.options[select.selectedIndex];
                        const capitulo = selectedOption.getAttribute('data-capitulo');
                        document.getElementById('episodeNumber').value = capitulo;
                    }
                </script>

                <div class="mb-3">
                    <label class="form-label">Título</label>
                    <input type="text" class="form-control" id="titleInput" name="title" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Duración (máx. 10:00:00)</label>
                    <input type="text" id="durationInput" name="duration" class="form-control" maxlength="8" placeholder="H:MM:SS" required>
                    <div id="errorMsg" class="invalid-feedback d-none">Duración inválida. Formato: H:MM:SS, máximo 10:00:00</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Fecha de Publicación</label>
                    <input type="datetime-local" class="form-control" id="publishDateInput" name="publish_date" value="<?= $fecha_hoy . ' ' .$hora_actual; ?>" required>
                </div>

                <!-- Script JS para consultar get_video_info.php -->
                <script>
                    document.getElementById('btnAutoFill').addEventListener('click', function() {
                        const url = document.getElementById('youtubeLinkInput').value.trim();
                        const feedback = document.getElementById('autoFillFeedback');
                        const titleInput = document.getElementById('titleInput');
                        const durationInput = document.getElementById('durationInput');
                        const publishDateInput = document.getElementById('publishDateInput');

                        if (!url) {
                            alert('Por favor ingresa un link de YouTube primero.');
                            return;
                        }

                        feedback.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i> Consultando a la API de YouTube...</span>';

                        fetch(`get_video_info.php?url=${encodeURIComponent(url)}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    titleInput.value = data.title;
                                    durationInput.value = data.duration;
                                    publishDateInput.value = data.publish_date;
                                    feedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> ¡Datos cargados con éxito desde la API!</span>';
                                } else {
                                    throw new Error(data.error);
                                }
                            })
                            .catch(error => {
                                feedback.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i> Error: ' + error.message + '</span>';
                            });
                    });
                </script>
            <?php endif; ?>

            <button type="submit" class="btn btn-success">Guardar</button>
            <a href="index.php?section=<?= $type; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>