<?php
declare(strict_types=1);
/**
 * Metis · Diagnóstico de casos — admin/diagnostico_casos.php
 * Herramienta de depuración para inspeccionar casos y testear UPDATEs.
 * Solo accesible a superadmin.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$user      = Auth::user() ?? [];
$rolCodigo = (string)($user['rol_codigo'] ?? '');
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);

if ($rolCodigo !== 'superadmin') {
    http_response_code(403);
    exit('Solo disponible para superadmin.');
}

$pdo = DB::conn();

// ── Resultado del dry-run ─────────────────────────────────────────────────
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);
    $accionDiag = clean((string)($_POST['_accion'] ?? ''));

    if ($accionDiag === 'inspeccionar') {
        $casoIdTest = (int)($_POST['caso_id_test'] ?? 0);
        if ($casoIdTest > 0) {
            // 1. Leer fila completa sin filtro de colegio
            $stmtRaw = $pdo->prepare("
                SELECT c.id, c.colegio_id, c.numero_caso, c.estado, c.semaforo, c.prioridad,
                       c.estado_caso_id, c.updated_at,
                       col.nombre AS nombre_colegio,
                       ec.nombre  AS nombre_estado_formal
                FROM casos c
                LEFT JOIN colegios col ON col.id = c.colegio_id
                LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmtRaw->execute([$casoIdTest]);
            $casoRaw = $stmtRaw->fetch(PDO::FETCH_ASSOC);

            // 2. Leer con filtro de colegio de la sesión (como hace ver.php)
            $stmtFilt = $pdo->prepare("SELECT id FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1");
            $stmtFilt->execute([$casoIdTest, $colegioId]);
            $casoFilt = $stmtFilt->fetch(PDO::FETCH_ASSOC);

            // 3. Dry-run UPDATE con solo WHERE id = ?
            $dryRunSinColegio = null;
            try {
                $pdo->beginTransaction();
                $stmtU = $pdo->prepare("UPDATE casos SET semaforo='verde', updated_at=NOW() WHERE id=?");
                $stmtU->execute([$casoIdTest]);
                $dryRunSinColegio = $stmtU->rowCount();
                $pdo->rollBack();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $dryRunSinColegio = 'ERROR: ' . $e->getMessage();
            }

            // 4. Dry-run UPDATE con WHERE id = ? AND colegio_id = ?
            $dryRunConColegio = null;
            try {
                $pdo->beginTransaction();
                $stmtU2 = $pdo->prepare("UPDATE casos SET semaforo='verde', updated_at=NOW() WHERE id=? AND colegio_id=?");
                $stmtU2->execute([$casoIdTest, $colegioId]);
                $dryRunConColegio = $stmtU2->rowCount();
                $pdo->rollBack();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $dryRunConColegio = 'ERROR: ' . $e->getMessage();
            }

            $resultado = [
                'tipo'             => 'inspeccion',
                'caso_id_test'     => $casoIdTest,
                'caso_raw'         => $casoRaw,
                'caso_filtrado'    => $casoFilt,
                'dry_sin_colegio'  => $dryRunSinColegio,
                'dry_con_colegio'  => $dryRunConColegio,
            ];
        }
    }

    if ($accionDiag === 'listar_casos') {
        $limite = min((int)($_POST['limite'] ?? 20), 100);
        $stmtL = $pdo->query("
            SELECT c.id, c.colegio_id, c.numero_caso, c.semaforo, c.prioridad,
                   col.nombre AS nombre_colegio
            FROM casos c
            LEFT JOIN colegios col ON col.id = c.colegio_id
            ORDER BY c.id DESC
            LIMIT {$limite}
        ");
        $listaCasos = $stmtL->fetchAll(PDO::FETCH_ASSOC);
        $resultado = [
            'tipo'       => 'lista',
            'casos'      => $listaCasos,
        ];
    }
}

// ── Datos de contexto ─────────────────────────────────────────────────────
$totalCasos = 0;
try {
    $totalCasos = (int)$pdo->query("SELECT COUNT(*) FROM casos")->fetchColumn();
} catch (Throwable $e) {}

$misCasos = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM casos WHERE colegio_id = ?");
    $stmt->execute([$colegioId]);
    $misCasos = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

$pageTitle    = 'Diagnóstico de Casos · Metis';
$pageSubtitle = 'Herramienta para inspeccionar y testear operaciones sobre la tabla casos';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.dc-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2563eb 100%);
    color: #fff; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.dc-hero h2 { margin: 0 0 .35rem; font-size: 1.6rem; font-weight: 900; }
.dc-hero p  { margin: 0; color: #bfdbfe; font-size: .9rem; }
.dc-btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    border-radius: 999px; padding: .5rem .95rem; font-size: .82rem; font-weight: 700;
    text-decoration: none; border: 1px solid rgba(255,255,255,.3);
    color: #fff; background: rgba(255,255,255,.12); margin-top: .9rem;
}
.dc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.1rem; }
.dc-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
    padding: 1.2rem; box-shadow: 0 4px 12px rgba(15,23,42,.05);
}
.dc-card-title {
    font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
    color: #2563eb; margin-bottom: 1rem; display: flex; align-items: center; gap: .4rem;
}
.dc-kv { display: flex; justify-content: space-between; align-items: center;
         padding: .45rem .6rem; border-radius: 8px; font-size: .83rem; margin-bottom: .3rem; }
.dc-kv:nth-child(odd) { background: #f8fafc; }
.dc-kv-key  { color: #64748b; font-weight: 600; }
.dc-kv-val  { color: #0f172a; font-weight: 700; font-family: monospace; }
.dc-badge   { display: inline-flex; align-items: center; border-radius: 999px;
              padding: .2rem .6rem; font-size: .72rem; font-weight: 800; }
.dc-badge.ok      { background: #dcfce7; color: #166534; }
.dc-badge.danger  { background: #fee2e2; color: #991b1b; }
.dc-badge.warn    { background: #fef9c3; color: #854d0e; }
.dc-badge.neutral { background: #f1f5f9; color: #475569; }
.dc-form-row { display: flex; gap: .7rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1rem; }
.dc-label { display: block; font-size: .75rem; font-weight: 700; color: #334155; margin-bottom: .3rem; }
.dc-input {
    padding: .5rem .75rem; border: 1px solid #cbd5e1; border-radius: 8px;
    font-size: .88rem; font-family: inherit; color: #0f172a; background: #fff;
}
.dc-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.dc-btn {
    padding: .52rem 1.2rem; border: none; border-radius: 8px; font-size: .84rem;
    font-weight: 700; cursor: pointer; font-family: inherit;
}
.dc-btn-primary { background: #1e3a8a; color: #fff; }
.dc-btn-primary:hover { background: #1e40af; }
.dc-btn-soft    { background: #e0e7ff; color: #1e3a8a; }
.dc-btn-soft:hover { background: #c7d2fe; }
.dc-alert { border-radius: 12px; padding: .85rem 1rem; margin-bottom: 1rem; font-size: .84rem; }
.dc-alert.ok      { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.dc-alert.danger  { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
.dc-alert.warn    { background: #fef9c3; border: 1px solid #fde047; color: #854d0e; }
.dc-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.dc-table th { background: #f1f5f9; padding: .5rem .75rem; text-align: left;
               font-size: .7rem; font-weight: 800; letter-spacing: .06em;
               text-transform: uppercase; color: #64748b; }
.dc-table td { padding: .5rem .75rem; border-top: 1px solid #e2e8f0; color: #0f172a; }
.dc-table tr:hover td { background: #f8fafc; }
.dc-separator { border: none; border-top: 1px solid #e2e8f0; margin: 1.2rem 0; }
@media (max-width: 700px) { .dc-grid { grid-template-columns: 1fr; } }
</style>

<!-- Hero -->
<section class="dc-hero">
    <h2><i class="bi bi-database-gear"></i> Diagnóstico de Casos</h2>
    <p>Inspecciona cualquier caso en la DB y testea UPDATEs con dry-run (BEGIN → UPDATE → ROLLBACK). Sin efecto real.</p>
    <a class="dc-btn-back" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
        <i class="bi bi-arrow-left"></i> Diagnóstico general
    </a>
</section>

<!-- Contexto de sesión -->
<div class="dc-grid">
    <div class="dc-card">
        <div class="dc-card-title"><i class="bi bi-person-badge"></i> Sesión actual</div>
        <div class="dc-kv"><span class="dc-kv-key">usuario_id</span>       <span class="dc-kv-val"><?= e((string)$userId) ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">nombre</span>           <span class="dc-kv-val"><?= e((string)($user['nombre'] ?? '—')) ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">rol_codigo</span>       <span class="dc-kv-val"><?= e($rolCodigo) ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">colegio_id (sesión)</span> <span class="dc-kv-val" style="color:#2563eb;"><?= e((string)$colegioId) ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">APP_ENV</span>          <span class="dc-kv-val"><?= e(APP_ENV) ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">APP_URL</span>          <span class="dc-kv-val" style="font-size:.76rem;"><?= e(APP_URL) ?></span></div>
    </div>
    <div class="dc-card">
        <div class="dc-card-title"><i class="bi bi-table"></i> Tabla casos</div>
        <div class="dc-kv"><span class="dc-kv-key">Total filas</span>      <span class="dc-kv-val"><?= number_format($totalCasos, 0, ',', '.') ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">Filas colegio_id=<?= $colegioId ?></span> <span class="dc-kv-val"><?= number_format($misCasos, 0, ',', '.') ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">Filas otros colegios</span> <span class="dc-kv-val"><?= number_format($totalCasos - $misCasos, 0, ',', '.') ?></span></div>
        <div class="dc-kv"><span class="dc-kv-key">Motor DB</span>
            <span class="dc-kv-val"><?= e((string)$pdo->query("SELECT @@version")->fetchColumn()) ?></span>
        </div>
    </div>
</div>

<hr class="dc-separator">

<!-- Formulario: inspeccionar caso -->
<div class="dc-card" style="margin-bottom:1.1rem;">
    <div class="dc-card-title"><i class="bi bi-search"></i> Inspeccionar caso por ID</div>
    <form method="post">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="inspeccionar">
        <div class="dc-form-row">
            <div>
                <label class="dc-label" for="caso_id_test">caso_id</label>
                <input class="dc-input" type="number" id="caso_id_test" name="caso_id_test" min="1"
                       value="<?= e((string)(int)($_POST['caso_id_test'] ?? '')) ?>"
                       placeholder="ej. 42" style="width:130px;" required>
            </div>
            <button type="submit" class="dc-btn dc-btn-primary">
                <i class="bi bi-search"></i> Inspeccionar + Dry-run
            </button>
        </div>
        <p style="font-size:.76rem;color:#64748b;margin:0;">
            Ejecuta un SELECT sin filtro, un SELECT con colegio_id de sesión, y dos UPDATEs en transacción que se revierten automáticamente.
            <strong>No modifica ningún dato.</strong>
        </p>
    </form>
</div>

<?php if ($resultado && $resultado['tipo'] === 'inspeccion'): ?>
<?php
$cr   = $resultado['caso_raw'];
$match = $cr && (int)$cr['colegio_id'] === $colegioId;
$sinCol = $resultado['dry_sin_colegio'];
$conCol = $resultado['dry_con_colegio'];
?>

<?php if (!$cr): ?>
    <div class="dc-alert danger"><strong>✗ No existe</strong> — ninguna fila con id = <?= (int)$resultado['caso_id_test'] ?> en la tabla casos.</div>
<?php else: ?>

    <!-- Resultado inspección -->
    <?php if (!$match): ?>
    <div class="dc-alert danger">
        <strong>⚠ MISMATCH de colegio_id</strong> — La sesión tiene colegio_id=<strong><?= $colegioId ?></strong>
        pero el caso tiene colegio_id=<strong><?= (int)$cr['colegio_id'] ?></strong> (<?= e((string)($cr['nombre_colegio'] ?? '?')) ?>).
        El UPDATE <code>WHERE id=? AND colegio_id=?</code> siempre afectará 0 filas para este caso con este usuario.
    </div>
    <?php else: ?>
    <div class="dc-alert ok">
        <strong>✓ colegio_id coincide</strong> — sesión y DB tienen colegio_id=<?= $colegioId ?>.
    </div>
    <?php endif; ?>

    <div class="dc-grid">
        <div class="dc-card">
            <div class="dc-card-title"><i class="bi bi-file-earmark-text"></i> Datos del caso (sin filtro)</div>
            <div class="dc-kv"><span class="dc-kv-key">id</span>               <span class="dc-kv-val"><?= e((string)$cr['id']) ?></span></div>
            <div class="dc-kv"><span class="dc-kv-key">numero_caso</span>      <span class="dc-kv-val"><?= e((string)$cr['numero_caso']) ?></span></div>
            <div class="dc-kv">
                <span class="dc-kv-key">colegio_id (DB)</span>
                <span class="dc-kv-val" style="color:<?= $match ? '#166534' : '#dc2626' ?>;">
                    <?= e((string)$cr['colegio_id']) ?> — <?= e((string)($cr['nombre_colegio'] ?? '?')) ?>
                    <?= $match ? ' ✓' : ' ≠ sesión (' . $colegioId . ')' ?>
                </span>
            </div>
            <div class="dc-kv"><span class="dc-kv-key">estado</span>           <span class="dc-kv-val"><?= e((string)$cr['estado']) ?></span></div>
            <div class="dc-kv"><span class="dc-kv-key">estado_caso_id</span>   <span class="dc-kv-val"><?= e((string)($cr['estado_caso_id'] ?? 'NULL')) ?> — <?= e((string)($cr['nombre_estado_formal'] ?? '—')) ?></span></div>
            <div class="dc-kv"><span class="dc-kv-key">semaforo</span>         <span class="dc-kv-val"><?= e((string)$cr['semaforo']) ?></span></div>
            <div class="dc-kv"><span class="dc-kv-key">prioridad</span>        <span class="dc-kv-val"><?= e((string)$cr['prioridad']) ?></span></div>
            <div class="dc-kv"><span class="dc-kv-key">updated_at</span>       <span class="dc-kv-val"><?= e((string)$cr['updated_at']) ?></span></div>
        </div>

        <div class="dc-card">
            <div class="dc-card-title"><i class="bi bi-play-circle"></i> Dry-run UPDATEs (ROLLBACK automático)</div>
            <div style="font-size:.78rem;color:#64748b;margin-bottom:.85rem;">
                Ningún cambio es persistido. Solo muestra cuántas filas afectaría cada variante.
            </div>

            <div class="dc-kv">
                <span class="dc-kv-key">WHERE id = ?</span>
                <span class="dc-kv-val">
                    <?php if (is_string($sinCol)): ?>
                        <span class="dc-badge danger"><?= e($sinCol) ?></span>
                    <?php elseif ((int)$sinCol > 0): ?>
                        <span class="dc-badge ok"><?= (int)$sinCol ?> fila(s) — funciona ✓</span>
                    <?php else: ?>
                        <span class="dc-badge danger">0 filas — no matchea</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="dc-kv">
                <span class="dc-kv-key">WHERE id = ? AND colegio_id = <?= $colegioId ?></span>
                <span class="dc-kv-val">
                    <?php if (is_string($conCol)): ?>
                        <span class="dc-badge danger"><?= e($conCol) ?></span>
                    <?php elseif ((int)$conCol > 0): ?>
                        <span class="dc-badge ok"><?= (int)$conCol ?> fila(s) — funciona ✓</span>
                    <?php else: ?>
                        <span class="dc-badge danger">0 filas — no matchea</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="dc-kv">
                <span class="dc-kv-key">ver.php carga el caso</span>
                <span class="dc-kv-val">
                    <?php if ($resultado['caso_filtrado']): ?>
                        <span class="dc-badge ok">Sí — accesible con este usuario ✓</span>
                    <?php else: ?>
                        <span class="dc-badge danger">No — la vista también fallaría (404)</span>
                    <?php endif; ?>
                </span>
            </div>

            <hr class="dc-separator" style="margin:.85rem 0;">
            <?php if (!$match && (int)$sinCol > 0 && (int)$conCol === 0): ?>
            <div class="dc-alert warn" style="margin:0;">
                <strong>Diagnóstico confirmado:</strong> el caso existe pero su <code>colegio_id</code> en DB
                (<?= (int)$cr['colegio_id'] ?>) no coincide con el de la sesión (<?= $colegioId ?>).
                El UPDATE sin <code>AND colegio_id</code> sí funciona. Opciones:
                <ul style="margin:.5rem 0 0 1.2rem;padding:0;">
                    <li>Corregir el <code>colegio_id</code> del caso en la DB.</li>
                    <li>O usar <code>WHERE id = ?</code> en el UPDATE (la validación de pertenencia ya la hace <code>ver_cargar_caso()</code>).</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<hr class="dc-separator">

<!-- Formulario: listar últimos casos -->
<div class="dc-card" style="margin-bottom:1.1rem;">
    <div class="dc-card-title"><i class="bi bi-list-ul"></i> Listar últimos casos (todos los colegios)</div>
    <form method="post">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="listar_casos">
        <div class="dc-form-row">
            <div>
                <label class="dc-label" for="limite">Cantidad</label>
                <select class="dc-input" id="limite" name="limite" style="width:100px;">
                    <option value="10">10</option>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <button type="submit" class="dc-btn dc-btn-soft">
                <i class="bi bi-table"></i> Listar
            </button>
        </div>
    </form>
</div>

<?php if ($resultado && $resultado['tipo'] === 'lista'): ?>
<div class="dc-card">
    <div class="dc-card-title"><i class="bi bi-table"></i> Últimos <?= count($resultado['casos']) ?> casos</div>
    <?php if (empty($resultado['casos'])): ?>
        <p style="color:#94a3b8;text-align:center;padding:1rem 0;">Sin casos en la tabla.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="dc-table">
            <thead>
                <tr>
                    <th>id</th>
                    <th>colegio_id</th>
                    <th>nombre_colegio</th>
                    <th>numero_caso</th>
                    <th>semaforo</th>
                    <th>prioridad</th>
                    <th>mi sesión?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultado['casos'] as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td style="font-weight:700;color:<?= (int)$row['colegio_id'] === $colegioId ? '#166534' : '#dc2626' ?>;">
                        <?= (int)$row['colegio_id'] ?>
                    </td>
                    <td><?= e((string)($row['nombre_colegio'] ?? '?')) ?></td>
                    <td><?= e((string)$row['numero_caso']) ?></td>
                    <td><?= e((string)$row['semaforo']) ?></td>
                    <td><?= e((string)$row['prioridad']) ?></td>
                    <td>
                        <?php if ((int)$row['colegio_id'] === $colegioId): ?>
                            <span class="dc-badge ok">✓ Sí</span>
                        <?php else: ?>
                            <span class="dc-badge danger">✗ No (sesión=<?= $colegioId ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
