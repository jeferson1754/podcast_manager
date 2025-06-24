<?php // 
$type = isset($_GET['type']) ? $_GET['type'] : 'podcasts';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

include('bd.php');

if ($conn->connect_error) {
    die('Connection Error');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($type == 'podcasts') {
        $title = $_POST['title'];
        $image = $_POST['image'];
        $link = $_POST['link'];
        $state = $_POST['state'];
        $stmt = $conn->prepare("UPDATE podcasts SET title = ?, image = ?,description = ?, state = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $image, $link, $state, $id);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == 'seasons') {
        $podcast_id = $_POST['podcast_id'];
        $number = $_POST['number'];
        $stmt = $conn->prepare("UPDATE seasons SET podcast_id = ?, number = ? WHERE id = ?");
        $stmt->bind_param("iii", $podcast_id, $number, $id);
        $stmt->execute();
        $stmt->close();
    } elseif ($type == 'episodes') {
        $season_id = $_POST['season_id'];
        $number = $_POST['number'];
        $title = $_POST['title'];
        $duration = $_POST['duration'];
        $publish_date = $_POST['publish_date'];
        $stmt = $conn->prepare("UPDATE episodes SET season_id = ?, number = ?, title = ?, duration = ?, publish_date = ? WHERE id = ?");
        $stmt->bind_param("iisssi", $season_id, $number, $title, $duration, $publish_date, $id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: index.php?section=' . $type);
    exit;
} // Fetch current values 
if ($type == 'podcasts') {
    $result = $conn->query("SELECT * FROM podcasts WHERE id = $id");
    $data = $result->fetch_assoc();
} elseif ($type == 'seasons') {
    $result = $conn->query("SELECT * FROM seasons WHERE id = $id");
    $data = $result->fetch_assoc();
} elseif ($type == 'episodes') {
    $result = $conn->query("SELECT * FROM episodes WHERE id = $id");
    $data = $result->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar <?php echo ucfirst($type); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h2>Editar <?php echo ucfirst($type); ?></h2>
        <form action="" method="post"> <?php if ($type == 'podcasts'): ?>
                <div class="mb-3"> <label class="form-label">Titulo</label>
                    <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($data['title']); ?>" required>
                </div>

                <div class="mb-3"> <label class="form-label">Imagen (Link)</label>
                    <input type="text" class="form-control" name="image" value="<?php echo htmlspecialchars($data['image']); ?>" required>
                </div>

                <div class="mb-3"> <label class="form-label">Link de Youtube</label>
                    <input type="text" class="form-control" name="link" value="<?php echo htmlspecialchars($data['description']); ?>" required>
                </div>

                <div class="mb-3"> <label class="form-label">Estado</label> <select class="form-select" name="state" required>
                        <option value="Activo" <?php echo ($data['state'] == 'Activo') ? 'selected' : ''; ?>>Activo</option>
                        <option value="Inactivo" <?php echo ($data['state'] == 'Inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                        <option value="Finalizado" <?php echo ($data['state'] == 'Finalizado') ? 'selected' : ''; ?>>Finalizado</option>
                    </select> </div>
            <?php elseif ($type == 'seasons'): // Fetch podcasts for dropdown 

                                            $result2 = $conn2->query("SELECT id, title FROM podcasts");
            ?>
                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <select class="form-select" name="podcast_id" required>
                        <?php while ($pod = $result2->fetch_assoc()): ?>
                            <option value="<?php echo $pod['id']; ?>" <?php echo ($data['podcast_id'] == $pod['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pod['title']); ?></option>
                        <?php endwhile;
                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Numero de Temporada</label>
                    <input type="number" class="form-control" name="number" value="<?php echo htmlspecialchars($data['number']); ?>" required>
                </div>
            <?php elseif ($type == 'episodes'): // Fetch seasons for dropdown 
                                            $result2 = $conn2->query("SELECT seasons.id, podcasts.title, seasons.number FROM seasons INNER JOIN podcasts ON podcasts.id = seasons.podcast_id;");
            ?> <div class="mb-3">
                    <label class="form-label">Temporada</label>
                    <select class="form-select" name="season_id" required>
                        <?php while ($season = $result2->fetch_assoc()): ?>
                            <option value="<?php echo $season['id']; ?>" <?php echo ($data['season_id'] == $season['id']) ? 'selected' : ''; ?>><?php echo $season['title'] . ' Temporada ' . $season['number']; ?></option>
                        <?php endwhile;
                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">N° Capitulo</label>
                    <input type="number" class="form-control" name="number" value="<?php echo htmlspecialchars($data['number']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Titulo</label>
                    <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($data['title']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Duracion</label>
                    <input type="text" class="form-control" name="duration" value="<?php echo htmlspecialchars($data['duration']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha de Publicacion</label>
                    <input type="date" class="form-control" name="publish_date" value="<?php echo htmlspecialchars($data['publish_date']); ?>" required>
                </div> <?php endif; ?>
            <button type="submit" class="btn btn-success">Actualizar</button>
            <a href="index.php?section=<?php echo $type; ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>