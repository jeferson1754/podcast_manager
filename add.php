<?php
$type = isset($_GET['type']) ? $_GET['type'] : $podcast;

include('bd.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Connect to DB 

    if ($conn->connect_error) {
        die('Connection Error');
    }
    if ($type == $podcast) {
        $title = $_POST['title'];
        $image = $_POST['image'];
        $link = $_POST['link'];
        $state = $_POST['state'];
        $stmt = $conn->prepare("INSERT INTO podcasts (title, image, description, state) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title, $image, $link, $state);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == $temporadas) {
        $podcast_id = $_POST['podcast_id'];
        $number = $_POST['number'];

        // Verifica si ya existe
        $stmt = $conn->prepare("SELECT id FROM seasons WHERE podcast_id = ? AND number = ? ");
        $stmt->bind_param("ii", $podcast_id, $number);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // Ya existe → actualizar (aquí puedes actualizar algún campo adicional si quieres)
            $stmt->close();
            $stmtUpdate = $conn->prepare("UPDATE seasons SET number = ? WHERE id = ? ");
            $stmtUpdate->bind_param("ii", $number, $podcast_id);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        } else {
            // No existe → insertar
            $stmt->close();
            $stmtInsert = $conn->prepare("INSERT INTO seasons (podcast_id, number) VALUES (?, ?)");
            $stmtInsert->bind_param("ii", $podcast_id, $number);
            $stmtInsert->execute();
            $stmtInsert->close();
        }
    } elseif ($type == $episodios) {
        $season_id = $_POST['season_id'];
        $number = $_POST['number'];
        $title = $_POST['title'];
        $duration = $_POST['duration'];
        $publish_date = $_POST['publish_date'];
        $stmt = $conn->prepare("INSERT INTO episodes (season_id, number, title, duration, publish_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $season_id, $number, $title, $duration, $publish_date);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == $calendario) {
        $podcast_id = $_POST['schedule_id'];
        $number = $_POST['start_time'];
        $duration = $_POST['day'];
        $stmt = $conn->prepare("INSERT INTO `schedule`( `day_of_week`, `start_time`, `podcast_id`) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $duration, $number, $podcast_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: index.php?section=' . $type);
    exit;
} // For GET requests, show a simple HTML form (without JS validations) 
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agregar Nuevos <?php echo ucfirst($type); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h2>Agregar Nuevos <?php echo ucfirst($type); ?></h2>
        <form action="" method="post">
            <?php if ($type == $podcast): ?>

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
            <?php elseif ($type == $temporadas): // Fetch podcasts for the dropdown 
                $result = $conn2->query("SELECT podcasts.id, podcasts.title, COALESCE(MAX(seasons.number), 0) + 1 AS number FROM podcasts LEFT JOIN seasons ON seasons.podcast_id = podcasts.id GROUP BY podcasts.id, podcasts.title;");
            ?>
                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <select class="form-select" name="podcast_id" id="podcastSelect" required onchange="actualizarTemporada()">
                        <option value="" disabled selected>Selecciona un podcast</option>
                        <?php while ($pod = $result->fetch_assoc()): ?>
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

            <?php elseif ($type == $episodios): // Fetch seasons for the dropdown 
                $result = $conn2->query("
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
                <div class="mb-3">
                    <label class="form-label">Temporada</label>
                    <select class="form-select" name="season_id" id="seasonSelect" required onchange="actualizarCapitulo()">
                        <option value="" disabled selected>Selecciona una temporada</option>
                        <?php while ($season = $result->fetch_assoc()): ?>
                            <option
                                value="<?php echo $season['season_id']; ?>"
                                data-capitulo="<?php echo $season['capitulo']; ?>">
                                <?php echo htmlspecialchars($season['title']) . ' - Temporada ' . $season['temporada']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Número de Episodio (+)</label>
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
                    <label class="form-label">Titulo</label>
                    <input type="text" class="form-control" name="title" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Duración (máx. 10:00:00)</label>
                    <input type="text" id="durationInput" name="duration" class="form-control" maxlength="8" placeholder="H:MM:SS" required>
                    <div id="errorMsg" class="invalid-feedback d-none">Duración inválida. Formato: H:MM:SS, máximo 10:00:00</div>
                </div>

                <script>
                    const input = document.getElementById('durationInput');
                    const errorMsg = document.getElementById('errorMsg');

                    input.addEventListener('input', function(e) {
                        let val = input.value;

                        // Quitar todo lo que no sea número
                        val = val.replace(/\D/g, '');

                        // Limitar máximo 6 dígitos (HHMMSS)
                        if (val.length > 6) val = val.slice(0, 6);

                        // Insertar ":" automáticamente
                        // Dependiendo de longitud:
                        // 1-2 dígitos: horas
                        // 3-4 dígitos: horas + minutos
                        // 5-6 dígitos: horas + minutos + segundos

                        if (val.length <= 2) {
                            // Solo horas
                            val = val;
                        } else if (val.length <= 4) {
                            val = val.slice(0, val.length - 2) + ':' + val.slice(-2);
                        } else {
                            val = val.slice(0, val.length - 4) + ':' + val.slice(-4, val.length - 2) + ':' + val.slice(-2);
                        }

                        input.value = val;

                        // Validar formato completo
                        const pattern = /^([0-9]{1,2}):([0-5][0-9]):([0-5][0-9])$/;
                        const match = val.match(pattern);

                        if (!match) {
                            marcarInvalido();
                            return;
                        }

                        const horas = parseInt(match[1], 10);
                        const minutos = parseInt(match[2], 10);
                        const segundos = parseInt(match[3], 10);

                        if (horas > 10 || (horas === 10 && (minutos > 0 || segundos > 0))) {
                            marcarInvalido();
                        } else {
                            marcarValido();
                        }
                    });

                    function marcarInvalido() {
                        input.classList.add('is-invalid');
                        errorMsg.classList.remove('d-none');
                    }

                    function marcarValido() {
                        input.classList.remove('is-invalid');
                        errorMsg.classList.add('d-none');
                    }
                </script>



                <div class="mb-3">
                    <label class="form-label">Fecha de Publicacion</label>
                    <input type="datetime-local" class="form-control" name="publish_date" value="<?= $fecha_hoy . ' ' . $hora_actual; ?>" required>
                </div>
            <?php elseif ($type == $calendario): // Fetch schedule data for the dropdown
                $result = $conn2->query("SELECT id, title FROM podcasts");
            ?>
                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <select class="form-select" name="schedule_id" required>
                        <?php while ($schedule = $result->fetch_assoc()): ?>
                            <option value="<?php echo $schedule['id']; ?>"> <?php echo $schedule['title']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Hora Emision</label>
                    <input type="time" class="form-control" name="start_time" value="<?= $hora_actual; ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Día</label>
                    <select class="form-select" name="day" required>
                        <option value="">Seleccionar un día</option>
                        <?php foreach ($dias as $dia): ?>
                            <option value="<?= $dia ?>" <?= $dia === $dia_actual ? 'selected' : '' ?>>
                                <?= $dia ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button type=" submit" class="btn btn-success">Guardar</button>
            <a href="index.php?section=<?= $type; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>