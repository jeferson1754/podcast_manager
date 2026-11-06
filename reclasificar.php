<?php
// reclasificar.php — mueve episodios al podcast correcto cuando un canal tiene varios
// (ej.: canal QuantumFracture -> "Quantum AM" y "Quantum FM"), según el nombre que aparece en el título.
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';


/** Episodios cuyo título nombra a OTRO podcast del mismo canal. */
function calcular_candidatos(mysqli $conn): array
{
    $subCanales = "SELECT channel_id FROM podcasts WHERE channel_id IS NOT NULL GROUP BY channel_id HAVING COUNT(*) > 1";

    $porCanal = [];
    foreach (consultar($conn, "SELECT id, title, state, channel_id FROM podcasts WHERE channel_id IN ($subCanales) ORDER BY id") as $p) {
        $porCanal[$p['channel_id']][] = $p;
    }

    $eps = consultar($conn, "SELECT e.id, e.title, e.number, e.publish_date, s.podcast_id AS actual_id,
                                    p.title AS actual_titulo, p.channel_id
                             FROM episodes e
                             JOIN seasons s ON s.id = e.season_id
                             JOIN podcasts p ON p.id = s.podcast_id
                             WHERE p.channel_id IN ($subCanales)
                             ORDER BY e.publish_date ASC, e.id ASC");

    $candidatos = [];
    foreach ($eps as $e) {
        $elegido = elegir_podcast_por_titulo($porCanal[$e['channel_id']] ?? [], (string) $e['title']);
        if ($elegido && (int) $elegido['id'] !== (int) $e['actual_id']) {
            $e['destino_id'] = (int) $elegido['id'];
            $e['destino_titulo'] = $elegido['title'];
            $candidatos[] = $e;
        }
    }
    return $candidatos;
}

// ------------------------------------------------------------------
// Aplicar (POST + CSRF). El destino se recalcula en el servidor: no se confía en lo que envía el navegador.
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();

    $marcados = array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []);
    $movidos = 0;
    $errores = 0;

    if ($marcados) {
        $conn->begin_transaction();
        try {
            foreach (calcular_candidatos($conn) as $c) {   // ya viene en orden cronológico
                if (!in_array((int) $c['id'], $marcados, true)) {
                    continue;
                }
                if (mover_episodio_a_podcast($conn, (int) $c['id'], (int) $c['destino_id'], (string) $c['title'], (string) $c['destino_titulo'])) {
                    $movidos++;
                } else {
                    $errores++;
                }
            }
            $errores ? $conn->rollback() : $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('reclasificar.php: ' . $e->getMessage());
            $errores++;
        }
    }

    if ($errores) {
        flash_set('danger', 'No se pudo completar la reclasificación; no se movió ningún episodio.');
    } else {
        flash_set('success', "Episodios movidos al podcast correcto: $movidos.");
    }
    header('Location: reclasificar.php');
    exit;
}

$candidatos = calcular_candidatos($conn);
$section = $podcast;
$tituloPagina = 'Reclasificar episodios';
include 'layout_header.php';
?>
<div class="main-card">
    <div class="page-header flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-shuffle text-primary me-2"></i>Reclasificar episodios</h3>
            <p class="text-muted small mb-0">
                Para canales con varios podcasts (ej. Quantum AM / Quantum FM): detecta episodios cuyo título
                nombra a otro podcast del mismo canal y los mueve al correcto.
            </p>
        </div>
        <a href="index.php?section=<?= h($podcast) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Volver</a>
    </div>

    <?php if (!$candidatos): ?>
        <div class="empty-state">
            <i class="fas fa-circle-check"></i>
            <h5>Todo en orden</h5>
            <p class="small">Ningún episodio nombra a un podcast distinto del que tiene asignado.</p>
        </div>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('¿Mover los episodios marcados a su podcast correcto? Se les asignará la última temporada del podcast destino.');">
            <?= csrf_field() ?>
            <div class="alert alert-info small">
                Se encontraron <strong><?= count($candidatos) ?></strong> episodio(s) mal asignados. Desmarca los que no quieras mover.
                Pasan a la <strong>última temporada</strong> del podcast destino, con el número del título (<code>#N</code>) si está libre o el siguiente disponible.
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input" id="chkTodos" checked aria-label="Seleccionar todos"></th>
                            <th>Episodio</th>
                            <th>Está en</th>
                            <th></th>
                            <th>Debe ir a</th>
                            <th>Publicación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidatos as $c): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input chk" name="ids[]" value="<?= (int) $c['id'] ?>" checked></td>
                                <td class="fw-semibold text-dark"><?= h($c['title']) ?> <span class="badge bg-light text-secondary border">Cap. <?= h($c['number']) ?></span></td>
                                <td><span class="badge bg-danger bg-opacity-10 text-danger border"><?= h($c['actual_titulo']) ?></span></td>
                                <td class="text-muted"><i class="fas fa-arrow-right"></i></td>
                                <td><span class="badge bg-success bg-opacity-10 text-success border"><?= h($c['destino_titulo']) ?></span></td>
                                <td class="small text-muted"><?= h(date('d/m/Y H:i', strtotime((string) $c['publish_date']) ?: 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-shuffle me-1"></i> Mover episodios marcados</button>
        </form>
        <script>
            document.getElementById('chkTodos').addEventListener('change', function() {
                document.querySelectorAll('.chk').forEach(c => c.checked = this.checked);
            });
        </script>
    <?php endif; ?>
</div>
<?php include 'layout_footer.php'; ?>