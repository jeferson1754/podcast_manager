<?php
$type = isset($_GET['type']) ? $_GET['type'] : 'podcasts';

include('bd.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Connect to DB 

    if ($conn->connect_error) {
        die('Connection Error');
    }
    if ($type == 'podcasts') {
        $title = $_POST['title'];
        $image = $_POST['image'];
        $link = $_POST['link'];
        $state = $_POST['state'];
        $stmt = $conn->prepare("INSERT INTO podcasts (title, image, description, state) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title, $image, $link, $state);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == 'seasons') {
        $podcast_id = $_POST['podcast_id'];
        $number = $_POST['number'];
        $stmt = $conn->prepare("INSERT INTO seasons (podcast_id, number) VALUES (?, ?)");
        $stmt->bind_param("ii", $podcast_id, $number);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == 'episodes') {
        $season_id = $_POST['season_id'];
        $number = $_POST['number'];
        $title = $_POST['title'];
        $duration = $_POST['duration'];
        $publish_date = $_POST['publish_date'];
        $stmt = $conn->prepare("INSERT INTO episodes (season_id, number, title, duration, publish_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $season_id, $number, $title, $duration, $publish_date);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == 'schedule') {
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
            <?php if ($type == 'podcasts'): ?>

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
            <?php elseif ($type == 'seasons'): // Fetch podcasts for the dropdown 
                $result = $conn2->query("SELECT id, title FROM podcasts");
            ?>
                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <select class="form-select" name="podcast_id" required>
                        <?php while ($pod = $result->fetch_assoc()): ?>
                            <option value="<?php echo $pod['id']; ?>"><?php echo htmlspecialchars($pod['title']); ?></option>
                        <?php endwhile;

                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Numero de Temporada</label>
                    <input type="number" class="form-control" name="number" required>
                </div>

            <?php elseif ($type == 'episodes'): // Fetch seasons for the dropdown 
                $result = $conn2->query("SELECT seasons.id, podcasts.title, seasons.number FROM seasons INNER JOIN podcasts ON podcasts.id = seasons.podcast_id;");
            ?>
                <div class="mb-3">
                    <label class="form-label">Temporada</label>
                    <select class="form-select" name="season_id" required>
                        <?php while ($season = $result->fetch_assoc()): ?>
                            <option value="<?php echo $season['id']; ?>"> <?php echo $season['title'] . ' Temporada ' . $season['number']; ?></option>
                        <?php endwhile;
                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Numero de Episodio</label>
                    <input type="number" class="form-control" name="number" value="1" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Titulo</label>
                    <input type="text" class="form-control" name="title" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Duracion (e.g., 25:30)</label>
                    <input type="text" class="form-control" name="duration" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha de Publicacion</label>
                    <input type="date" class="form-control" name="publish_date" value="<?= $fecha_hoy; ?>" required>
                </div>
            <?php elseif ($type == 'schedule'): // Fetch schedule data for the dropdown
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