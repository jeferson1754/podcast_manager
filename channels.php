<?php
include('bd.php');

// ------------------------------------------------------------------
// ACCIONES POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($conn->connect_error) {
        die('Connection Error');
    }

    $accion = $_POST['accion'] ?? '';
    $msg = 'error';

    try {
        if ($accion === 'crear_canal') {
            $name  = trim($_POST['name'] ?? '');
            $ytId  = trim($_POST['youtube_channel_id'] ?? '');
            $image = trim($_POST['image'] ?? '');
            $ytId  = $ytId !== '' ? $ytId : null;

            $stmt = $conn->prepare("INSERT INTO channels (name, youtube_channel_id, created_at, image) VALUES (?, ?, NOW(), ?)");
            $stmt->bind_param("sss", $name, $ytId, $image);
            $stmt->execute();
            $stmt->close();
            $msg = 'creado';
        } elseif ($accion === 'asignar_podcast') {
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $podcast_id = (int) ($_POST['podcast_id'] ?? 0);

            $stmt = $conn->prepare("UPDATE podcasts SET channel_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $channel_id, $podcast_id);
            $stmt->execute();
            $stmt->close();
            $msg = 'vinculado';
        } elseif ($accion === 'editar_canal') {
            $id        = (int) ($_POST['id'] ?? 0);
            $name      = trim($_POST['name'] ?? '');
            $image     = trim($_POST['image'] ?? '');
            $ytId      = trim($_POST['youtube_channel_id'] ?? '');
            $ytId      = $ytId !== '' ? $ytId : null;
            $aplicarAPodcasts = !empty($_POST['aplicar_a_podcasts']);

            if ($id <= 0 || $name === '') {
                throw new Exception('Datos inválidos');
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare("UPDATE channels SET name = ?, youtube_channel_id = ?, image = ? WHERE id = ?");
            $stmt->bind_param("sssi", $name, $ytId, $image, $id);
            $stmt->execute();
            $stmt->close();

            // Opcional: copiar la misma imagen a los podcasts de este canal
            if ($aplicarAPodcasts && $image !== '') {
                $stmt = $conn->prepare("UPDATE podcasts SET image = ? WHERE channel_id = ?");
                $stmt->bind_param("si", $image, $id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $msg = 'actualizado';
        } elseif ($accion === 'eliminar_canal') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('ID inválido');
            }

            $conn->begin_transaction();

            // Los podcasts del canal NO se borran: quedan sin canal asignado
            $stmt = $conn->prepare("UPDATE podcasts SET channel_id = NULL WHERE channel_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM channels WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $msg = 'eliminado';
        } elseif ($accion === 'fusionar_canales') {
            // El canal ORIGEN se elimina; sus podcasts pasan al canal DESTINO.
            $origen  = (int) ($_POST['origen_id'] ?? 0);
            $destino = (int) ($_POST['destino_id'] ?? 0);

            if ($origen <= 0 || $destino <= 0 || $origen === $destino) {
                throw new Exception('Canales inválidos para fusionar');
            }

            $conn->begin_transaction();

            // Datos del canal origen y verificación de que ambos existen
            $stmt = $conn->prepare("SELECT id, youtube_channel_id, image FROM channels WHERE id IN (?, ?)");
            $stmt->bind_param("ii", $origen, $destino);
            $stmt->execute();
            $res = $stmt->get_result();
            $filas = [];
            while ($r = $res->fetch_assoc()) {
                $filas[(int) $r['id']] = $r;
            }
            $stmt->close();

            if (count($filas) !== 2) {
                throw new Exception('Alguno de los canales no existe');
            }
            $ytOrigen  = trim((string) $filas[$origen]['youtube_channel_id']);
            $imgOrigen = trim((string) $filas[$origen]['image']);

            // 1. Mover los podcasts del origen al destino
            $stmt = $conn->prepare("UPDATE podcasts SET channel_id = ? WHERE channel_id = ?");
            $stmt->bind_param("ii", $destino, $origen);
            $stmt->execute();
            $stmt->close();

            // 2. Eliminar el canal origen (antes de copiar su youtube_channel_id,
            //    por si la columna tiene índice único)
            $stmt = $conn->prepare("DELETE FROM channels WHERE id = ?");
            $stmt->bind_param("i", $origen);
            $stmt->execute();
            $stmt->close();

            // 3. Completar en el destino lo que le falte (no pisa datos existentes)
            $ytOrigenParam = $ytOrigen !== '' ? $ytOrigen : null;
            $stmt = $conn->prepare("
                UPDATE channels
                SET youtube_channel_id = COALESCE(NULLIF(youtube_channel_id, ''), ?),
                    image = COALESCE(NULLIF(image, ''), NULLIF(?, ''))
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", $ytOrigenParam, $imgOrigen, $destino);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $msg = 'fusionado';
        }
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $e2) {
        }
        error_log('channels.php: ' . $e->getMessage());
        $msg = 'error';
    }

    header('Location: channels.php?msg=' . $msg);
    exit;
}

// Mensajes de resultado (lista blanca)
$mensajes = [
    'creado'     => ['success', 'Canal creado correctamente.'],
    'vinculado'  => ['success', 'Podcast vinculado al canal.'],
    'actualizado' => ['success', 'Canal actualizado correctamente.'],
    'eliminado'  => ['success', 'Canal eliminado. Sus podcasts quedaron sin canal asignado.'],
    'fusionado'  => ['success', 'Canales fusionados: los podcasts se movieron al canal destino y el canal origen fue eliminado.'],
    'error'      => ['danger', 'Ocurrió un error al procesar la acción.'],
];
$flash = $mensajes[$_GET['msg'] ?? ''] ?? null;
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

        .img-edit-preview {
            width: 80px;
            height: 80px;
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

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash[0]; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash[1]); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Columna Izquierda -->
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
                                        <option value="<?= $ch['id']; ?>"><?= htmlspecialchars($ch['name']); ?> (#<?= $ch['id']; ?>)</option>
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

            <!-- Columna Derecha -->
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
                                        <th>Podcasts Asociados</th>
                                        <th style="width: 150px;" class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql_channels = "SELECT c.id AS channel_id, c.name AS channel_name, c.image AS channel_image,
                                                            c.youtube_channel_id AS yt_id, p.title AS podcast_title
                                                     FROM channels c
                                                     LEFT JOIN podcasts p ON p.channel_id = c.id
                                                     ORDER BY c.name ASC, c.id ASC";
                                    $result_c = $conn->query($sql_channels);

                                    $channels_grouped = [];
                                    if ($result_c) {
                                        while ($row = $result_c->fetch_assoc()) {
                                            $cid = $row['channel_id'];
                                            $channels_grouped[$cid]['name']  = $row['channel_name'];
                                            $channels_grouped[$cid]['image'] = $row['channel_image'];
                                            $channels_grouped[$cid]['yt_id'] = $row['yt_id'];
                                            if (!isset($channels_grouped[$cid]['podcasts'])) {
                                                $channels_grouped[$cid]['podcasts'] = [];
                                            }
                                            if ($row['podcast_title']) {
                                                $channels_grouped[$cid]['podcasts'][] = $row['podcast_title'];
                                            }
                                        }
                                    }

                                    if (!empty($channels_grouped)):
                                        foreach ($channels_grouped as $ch_id => $data):
                                            $nPodcasts = count($data['podcasts']);
                                    ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($data['image'])): ?>
                                                        <img src="<?= htmlspecialchars($data['image']); ?>" alt="Logo" class="img-thumb-preview rounded" referrerpolicy="no-referrer">
                                                    <?php else: ?>
                                                        <div class="img-thumb-preview d-flex align-items-center justify-content-center bg-light text-muted rounded border">
                                                            <i class="fas fa-image"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="align-middle">
                                                    <div class="fw-bold text-dark">
                                                        <i class="fas fa-tv text-primary me-1"></i> <?= htmlspecialchars($data['name']); ?>
                                                        <span class="text-muted fw-normal small">#<?= (int) $ch_id; ?></span>
                                                    </div>
                                                    <?php if (!empty($data['yt_id'])): ?>
                                                        <div class="text-muted small"><?= htmlspecialchars($data['yt_id']); ?></div>
                                                    <?php else: ?>
                                                        <div class="text-warning small"><i class="fas fa-exclamation-triangle me-1"></i>Sin ID de YouTube</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="align-middle">
                                                    <?php if ($nPodcasts > 0): ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($data['podcasts'] as $p_title): ?>
                                                                <span class="badge bg-light text-dark border px-2 py-1">
                                                                    <i class="fas fa-podcast text-muted me-1"></i> <?= htmlspecialchars($p_title); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small fst-italic">Sin podcasts asignados aún.</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end align-middle text-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditar"
                                                        data-id="<?= (int) $ch_id; ?>"
                                                        data-name="<?= htmlspecialchars($data['name'], ENT_QUOTES); ?>"
                                                        data-image="<?= htmlspecialchars((string) $data['image'], ENT_QUOTES); ?>"
                                                        data-ytid="<?= htmlspecialchars((string) $data['yt_id'], ENT_QUOTES); ?>"
                                                        data-podcasts="<?= $nPodcasts; ?>"
                                                        title="Editar canal">
                                                        <i class="fas fa-pen"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        data-bs-toggle="modal" data-bs-target="#modalFusionar"
                                                        data-id="<?= (int) $ch_id; ?>"
                                                        data-name="<?= htmlspecialchars($data['name'], ENT_QUOTES); ?>"
                                                        data-podcasts="<?= $nPodcasts; ?>"
                                                        title="Fusionar con otro canal">
                                                        <i class="fas fa-code-merge"></i>
                                                    </button>
                                                    <form action="channels.php" method="post" class="d-inline"
                                                        onsubmit="return confirm('¿Eliminar el canal «<?= htmlspecialchars(addslashes($data['name']), ENT_QUOTES); ?>»?<?= $nPodcasts > 0 ? '\n\nSus ' . $nPodcasts . ' podcast(s) NO se borrarán, pero quedarán sin canal asignado.' : ''; ?>');">
                                                        <input type="hidden" name="accion" value="eliminar_canal">
                                                        <input type="hidden" name="id" value="<?= (int) $ch_id; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar canal">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php
                                        endforeach;
                                    else:
                                        ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
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

    <!-- Modal: Editar canal -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" action="channels.php" method="post">
                <input type="hidden" name="accion" value="editar_canal">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen me-1"></i> Editar canal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <img id="edit-preview" src="" alt="Vista previa" class="img-edit-preview rounded border d-none" referrerpolicy="no-referrer">
                        <div id="edit-preview-vacio" class="img-edit-preview d-inline-flex align-items-center justify-content-center bg-light text-muted rounded border">
                            <i class="fas fa-image fa-lg"></i>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre del Canal</label>
                        <input type="text" class="form-control" name="name" id="edit-name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Imagen del Canal (Link)</label>
                        <input type="text" class="form-control" name="image" id="edit-image" placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ID de YouTube del Canal</label>
                        <input type="text" class="form-control" name="youtube_channel_id" id="edit-ytid" placeholder="UCxxxxxx...">
                    </div>
                    <div class="form-check" id="edit-aplicar-wrap">
                        <input class="form-check-input" type="checkbox" name="aplicar_a_podcasts" value="1" id="edit-aplicar">
                        <label class="form-check-label" for="edit-aplicar">
                            Usar también esta imagen en los podcasts de este canal (<span id="edit-npod">0</span>)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Fusionar canales -->
    <div class="modal fade" id="modalFusionar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" action="channels.php" method="post"
                onsubmit="return confirm('¿Confirmas la fusión? El canal origen se eliminará y esta acción no se puede deshacer.');">
                <input type="hidden" name="accion" value="fusionar_canales">
                <input type="hidden" name="origen_id" id="fus-origen">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-code-merge me-1"></i> Fusionar canales</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Canal que <strong>se eliminará</strong>:
                        <span class="badge bg-danger" id="fus-origen-nombre"></span>
                        <span class="text-muted small">(<span id="fus-npod">0</span> podcast(s))</span>
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Canal que <strong>se conserva</strong> (recibe los podcasts)</label>
                        <select class="form-select" name="destino_id" id="fus-destino" required>
                            <option value="" disabled selected>Elige el canal destino</option>
                            <?php foreach ($channels_grouped as $opt_id => $opt): ?>
                                <option value="<?= (int) $opt_id; ?>">
                                    <?= htmlspecialchars($opt['name']); ?> (#<?= (int) $opt_id; ?> · <?= count($opt['podcasts']); ?> podcast(s)<?= empty($opt['yt_id']) ? ' · sin ID YT' : ''; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-info small mb-0">
                        Los podcasts del canal origen pasan al destino. Si el destino no tiene
                        ID de YouTube o imagen, los hereda del origen; si ya los tiene, no se tocan.
                        Los episodios no se modifican.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-code-merge me-1"></i> Fusionar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modalFusionar = document.getElementById('modalFusionar');
        modalFusionar.addEventListener('show.bs.modal', (ev) => {
            const b = ev.relatedTarget;
            document.getElementById('fus-origen').value = b.dataset.id;
            document.getElementById('fus-origen-nombre').textContent = b.dataset.name + ' (#' + b.dataset.id + ')';
            document.getElementById('fus-npod').textContent = b.dataset.podcasts;
            const sel = document.getElementById('fus-destino');
            sel.value = '';
            Array.from(sel.options).forEach(o => {
                o.disabled = (o.value === b.dataset.id) || o.value === '';
                o.hidden = (o.value === b.dataset.id);
            });
        });

        const modalEditar = document.getElementById('modalEditar');
        const inpImage = document.getElementById('edit-image');
        const preview = document.getElementById('edit-preview');
        const previewVacio = document.getElementById('edit-preview-vacio');

        function actualizarPreview() {
            const url = inpImage.value.trim();
            if (url) {
                preview.src = url;
                preview.classList.remove('d-none');
                previewVacio.classList.add('d-none');
            } else {
                preview.removeAttribute('src');
                preview.classList.add('d-none');
                previewVacio.classList.remove('d-none');
            }
        }

        preview.addEventListener('error', () => {
            preview.classList.add('d-none');
            previewVacio.classList.remove('d-none');
        });
        inpImage.addEventListener('input', actualizarPreview);

        modalEditar.addEventListener('show.bs.modal', (ev) => {
            const b = ev.relatedTarget;
            document.getElementById('edit-id').value = b.dataset.id;
            document.getElementById('edit-name').value = b.dataset.name;
            inpImage.value = b.dataset.image || '';
            document.getElementById('edit-ytid').value = b.dataset.ytid || '';
            document.getElementById('edit-npod').textContent = b.dataset.podcasts;
            document.getElementById('edit-aplicar').checked = false;
            document.getElementById('edit-aplicar-wrap').classList.toggle('d-none', b.dataset.podcasts === '0');
            actualizarPreview();
        });
    </script>
</body>

</html>