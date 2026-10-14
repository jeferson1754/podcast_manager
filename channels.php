<?php
include('bd.php');

// Manejo de acciones POST (Crear Canal o Vincular Podcast)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($conn->connect_error) {
        die('Connection Error');
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_canal') {
        $name = $_POST['name'];
        $youtube_channel_id = $_POST['youtube_channel_id'];
        $image = $_POST['image']; // Nuevo campo para la imagen del canal

        $stmt = $conn->prepare("INSERT INTO channels (name, youtube_channel_id, image) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $youtube_channel_id, $image);
        $stmt->execute();
        $stmt->close();
    } elseif ($accion === 'asignar_podcast') {
        $channel_id = $_POST['channel_id'];
        $podcast_id = $_POST['podcast_id'];

        $stmt = $conn->prepare("UPDATE podcasts SET channel_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $channel_id, $podcast_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: channels.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Canales y Podcasts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .img-thumb-preview {
            width: 45px;
            height: 45px;
            object-fit: cover;
        }
    </style>
</head>

<body>
    <div class="container mt-5 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tv me-2 text-primary"></i> Gestión de Canales</h2>
                <p class="text-muted mb-0">Administra los canales de YouTube, sus imágenes y los podcasts asociados.</p>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Volver al Inicio
            </a>
        </div>

        <div class="row">
            <!-- Columna Izquierda: Formulario para Crear Canal -->
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-1"></i> Nuevo Canal</h5>
                    </div>
                    <div class="card-body">
                        <form action="channels.php" method="post">
                            <input type="hidden" name="accion" value="crear_canal">
                            <div class="mb-3">
                                <label class="form-label">Nombre del Canal</label>
                                <input type="text" class="form-control" name="name" placeholder="Ej: Canal Principal" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Imagen del Canal (Link)</label>
                                <input type="text" class="form-control" name="image" placeholder="https://... o ruta de imagen">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ID de YouTube del Canal (Opcional)</label>
                                <input type="text" class="form-control" name="youtube_channel_id" placeholder="Ej: UCxxxxxx...">
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-save me-1"></i> Guardar Canal
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Formulario para Asignar Podcast existente a un Canal -->
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-link me-1"></i> Asignar Podcast a Canal</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $channels_opt = $conn2->query("SELECT id, name FROM channels ORDER BY name ASC");
                        $podcasts_opt = $conn2->query("SELECT id, title FROM podcasts ORDER BY title ASC");
                        ?>
                        <form action="channels.php" method="post">
                            <input type="hidden" name="accion" value="asignar_podcast">
                            <div class="mb-3">
                                <label class="form-label">Seleccionar Canal</label>
                                <select class="form-select" name="channel_id" required>
                                    <option value="" disabled selected>Elige un canal</option>
                                    <?php while ($ch = $channels_opt->fetch_assoc()): ?>
                                        <option value="<?= $ch['id']; ?>"><?= htmlspecialchars($ch['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Seleccionar Podcast</label>
                                <select class="form-select" name="podcast_id" required>
                                    <option value="" disabled selected>Elige un podcast</option>
                                    <?php while ($pod = $podcasts_opt->fetch_assoc()): ?>
                                        <option value="<?= $pod['id']; ?>"><?= htmlspecialchars($pod['title']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-exchange-alt me-1"></i> Vincular Podcast
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Listado de Canales, sus Imágenes y sus Podcasts -->
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-1"></i> Canales Registrados y sus Podcasts</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 70px;">Logo</th>
                                        <th>Canal</th>
                                        <th>Podcasts Asociados en este Canal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Consultamos los canales, sus imágenes y agrupamos sus podcasts vinculados
                                    $sql_channels = "SELECT c.id as channel_id, c.name as channel_name, c.image as channel_image, p.title as podcast_title 
                                                     FROM channels c 
                                                     LEFT JOIN podcasts p ON p.channel_id = c.id 
                                                     ORDER BY c.name ASC";
                                    $result_c = $conn->query($sql_channels);

                                    $channels_grouped = [];
                                    if ($result_c) {
                                        while ($row = $result_c->fetch_assoc()) {
                                            $channels_grouped[$row['channel_id']]['name'] = $row['channel_name'];
                                            $channels_grouped[$row['channel_id']]['image'] = $row['channel_image'];
                                            if ($row['podcast_title']) {
                                                $channels_grouped[$row['channel_id']]['podcasts'][] = $row['podcast_title'];
                                            }
                                        }
                                    }

                                    if (!empty($channels_grouped)):
                                        foreach ($channels_grouped as $ch_id => $data):
                                    ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($data['image'])): ?>
                                                        <img src="<?= htmlspecialchars($data['image']); ?>" alt="Logo" class="img-thumb-preview rounded">
                                                    <?php else: ?>
                                                        <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted rounded border">
                                                            <i class="fas fa-image"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold text-dark align-middle">
                                                    <i class="fas fa-tv text-primary me-1"></i> <?= htmlspecialchars($data['name']); ?>
                                                </td>
                                                <td class="align-middle">
                                                    <?php if (!empty($data['podcasts'])): ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($data['podcasts'] as $p_title): ?>
                                                                <span class="badge bg-light text-dark border px-2 py-1">
                                                                    <i class="fas fa-podcast text-muted me-1"></i> <?= htmlspecialchars($p_title); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small italic">Sin podcasts asignados aún.</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php
                                        endforeach;
                                    else:
                                        ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">
                                                No hay canales registrados todavía.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>