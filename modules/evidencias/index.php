<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

$pageTitle = 'Evidencias · Metis';
$pageSubtitle = 'Repositorio central de archivos vinculados a expedientes';

function ev_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function ev_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function ev_corto(?string $value, int $length = 110): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return mb_strlen($value) > $length
        ? mb_substr($value, 0, $length) . '...'
        : $value;
}

function ev_icono(string $tipo, string $mime): string
{
    $tipo = strtolower($tipo);
    $mime = strtolower($mime);

    if (str_contains($mime, 'pdf')) {
        return 'bi-file-earmark-pdf';
    }

    if (str_contains($mime, 'image') || in_array($tipo, ['imagen', 'foto'], true)) {
        return 'bi-file-earmark-image';
    }

    if (str_contains($mime, 'audio')) {
        return 'bi-file-earmark-music';
    }

    if (str_contains($mime, 'video')) {
        return 'bi-file-earmark-play';
    }

    if (str_contains($mime, 'word') || str_contains($mime, 'document')) {
        return 'bi-file-earmark-word';
    }

    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) {
        return 'bi-file-earmark-excel';
    }

    return 'bi-paperclip';
}

function ev_badge(string $value): string
{
    return match (strtolower($value)) {
        'rojo', 'alta', 'pendiente' => 'danger',
        'amarillo', 'media' => 'warn',
        'verde', 'baja', 'cerrado', 'resuelta' => 'ok',
        default => 'soft',
    };
}

$error = '';

$q = clean((string)($_GET['q'] ?? ''));
$tipoFiltro = clean((string)($_GET['tipo'] ?? ''));
$desde = clean((string)($_GET['desde'] ?? ''));
$hasta = clean((string)($_GET['hasta'] ?? ''));

$where = ['c.colegio_id = ?'];
$params = [$colegioId];

if ($q !== '') {
    $where[] = '(e.nombre_archivo LIKE ? OR e.descripcion LIKE ? OR c.numero_caso LIKE ? OR c.relato LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($tipoFiltro !== '') {
    $where[] = 'e.tipo = ?';
    $params[] = $tipoFiltro;
}

if ($desde !== '') {
    $where[] = 'e.created_at >= ?';
    $params[] = $desde . ' 00:00:00';
}

if ($hasta !== '') {
    $where[] = 'e.created_at <= ?';
    $params[] = $hasta . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalEvidencias = 0;
$totalImagenes = 0;
$totalDocumentos = 0;
$totalAudiosVideos = 0;
$totalCasosConEvidencia = 0;
$evidencias = [];
$tipos = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
    ");
    $stmt->execute([$colegioId]);
    $totalEvidencias = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
          AND (
                e.tipo IN ('imagen', 'foto')
                OR e.mime_type LIKE 'image/%'
          )
    ");
    $stmt->execute([$colegioId]);
    $totalImagenes = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
          AND (
                e.tipo IN ('documento', 'archivo')
                OR e.mime_type LIKE '%pdf%'
                OR e.mime_type LIKE '%word%'
                OR e.mime_type LIKE '%excel%'
          )
    ");
    $stmt->execute([$colegioId]);
    $totalDocumentos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
          AND (
                e.tipo IN ('audio', 'video')
                OR e.mime_type LIKE 'audio/%'
                OR e.mime_type LIKE 'video/%'
          )
    ");
    $stmt->execute([$colegioId]);
    $totalAudiosVideos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.caso_id)
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
    ");
    $stmt->execute([$colegioId]);
    $totalCasosConEvidencia = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT DISTINCT e.tipo
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        WHERE c.colegio_id = ?
          AND e.tipo IS NOT NULL
          AND e.tipo <> ''
        ORDER BY e.tipo ASC
    ");
    $stmt->execute([$colegioId]);
    $tipos = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.caso_id,
            e.tipo,
            e.nombre_archivo,
            e.ruta,
            e.mime_type,
            e.descripcion,
            e.subido_por,
            e.created_at,
            c.numero_caso,
            c.estado,
            c.semaforo,
            c.prioridad,
            c.relato,
            u.nombre AS subido_por_nombre
        FROM caso_evidencias e
        INNER JOIN casos c ON c.id = e.caso_id
        LEFT JOIN usuarios u ON u.id = e.subido_por
        {$whereSql}
        ORDER BY e.created_at DESC, e.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $evidencias = $stmt->fetchAll();

} catch (Throwable $e) {
    $error = 'Error al cargar evidencias: ' . $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.ev-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 14px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 12px 32px rgba(15,23,42,.12);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.25rem;
    flex-wrap: wrap;
}

.ev-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.45rem;
    font-weight: 600;
}

.ev-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 820px;
    line-height: 1.55;
}

.ev-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.ev-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.ev-btn:hover {
    color: #fff;
}

.ev-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.ev-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.ev-kpi span {
    color: #64748b;
    display: block;
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.ev-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.75rem;
    line-height: 1;
    margin-top: .35rem;
}

.ev-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.ev-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.ev-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 600;
}

.ev-panel-body {
    padding: 1.2rem;
}

.ev-filter {
    display: grid;
    grid-template-columns: 1.4fr .8fr .75fr .75fr auto;
    gap: .8rem;
    align-items: end;
}

.ev-label {
    display: block;
    font-size: .76rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: .35rem;
}

.ev-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: .62rem .75rem;
    font-size: .88rem;
    outline: none;
}

.ev-control:focus {
    border-color: #0f766e;
    box-shadow: 0 0 0 4px rgba(15,118,110,.12);
}

.ev-submit,
.ev-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: .64rem 1rem;
    font-weight: 600;
    font-size: .84rem;
    text-decoration: none;
    white-space: nowrap;
}

.ev-link {
    background: #ecfeff;
    color: #0f766e;
    border: 1px solid #99f6e4;
}

.ev-list {
    display: grid;
    gap: .9rem;
}

.ev-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: start;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
}

.ev-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    background: #ccfbf1;
    color: #0f766e;
    font-size: 1.25rem;
    flex: 0 0 auto;
}

.ev-name {
    color: #0f172a;
    font-weight: 600;
    margin-bottom: .2rem;
    word-break: break-word;
}

.ev-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .25rem;
}

.ev-text {
    color: #334155;
    line-height: 1.45;
    font-size: .86rem;
    margin-top: .45rem;
}

.ev-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .6rem;
    font-size: .72rem;
    font-weight: 600;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.ev-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.ev-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.ev-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.ev-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.ev-actions-row {
    display: flex;
    gap: .45rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.ev-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}

.ev-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 600;
}

@media (max-width: 1180px) {
    .ev-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .ev-filter {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ev-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .ev-actions-row {
        grid-column: 1 / -1;
        justify-content: flex-start;
    }
}

@media (max-width: 680px) {
    .ev-kpis,
    .ev-filter {
        grid-template-columns: 1fr;
    }

    .ev-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="ev-hero">
    <div>
        <h2><i class="bi bi-paperclip" style="opacity:.8;margin-right:.35rem;"></i>Evidencias</h2>
        <p>Repositorio central de archivos asociados a expedientes de convivencia: documentos, imágenes, audios, videos y antecedentes de respaldo.</p>
    </div>
    <div class="ev-actions">
        <a class="ev-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-megaphone"></i> Denuncias
        </a>
        <a class="ev-btn" href="<?= APP_URL ?>/modules/seguimiento/index.php">
            <i class="bi bi-clipboard2-check"></i> Seguimiento
        </a>
        <a class="ev-btn" href="<?= APP_URL ?>/modules/reportes/index.php">
            <i class="bi bi-bar-chart"></i> Reportes
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="ev-error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="ev-kpis">
    <div class="ev-kpi">
        <span>Total evidencias</span>
        <strong><?= number_format($totalEvidencias, 0, ',', '.') ?></strong>
    </div>

    <div class="ev-kpi">
        <span>Imágenes</span>
        <strong><?= number_format($totalImagenes, 0, ',', '.') ?></strong>
    </div>

    <div class="ev-kpi">
        <span>Documentos</span>
        <strong><?= number_format($totalDocumentos, 0, ',', '.') ?></strong>
    </div>

    <div class="ev-kpi">
        <span>Audio / video</span>
        <strong><?= number_format($totalAudiosVideos, 0, ',', '.') ?></strong>
    </div>

    <div class="ev-kpi">
        <span>Casos con evidencia</span>
        <strong><?= number_format($totalCasosConEvidencia, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="ev-panel">
    <div class="ev-panel-head">
        <h3 class="ev-panel-title">
            <i class="bi bi-funnel"></i>
            Filtros
        </h3>

        <a class="ev-link" href="<?= APP_URL ?>/modules/evidencias/index.php">
            Limpiar
        </a>
    </div>

    <div class="ev-panel-body">
        <form method="get" class="ev-filter">
            <div>
                <label class="ev-label">Buscar</label>
                <input
                    class="ev-control"
                    type="text"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="Archivo, descripción, número de caso o relato"
                >
            </div>

            <div>
                <label class="ev-label">Tipo</label>
                <select class="ev-control" name="tipo">
                    <option value="">Todos</option>

                    <?php foreach ($tipos as $tipoRow): ?>
                        <?php $tipo = (string)($tipoRow['tipo'] ?? ''); ?>
                        <option value="<?= e($tipo) ?>" <?= $tipoFiltro === $tipo ? 'selected' : '' ?>>
                            <?= e(ev_label($tipo)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="ev-label">Desde</label>
                <input class="ev-control" type="date" name="desde" value="<?= e($desde) ?>">
            </div>

            <div>
                <label class="ev-label">Hasta</label>
                <input class="ev-control" type="date" name="hasta" value="<?= e($hasta) ?>">
            </div>

            <div>
                <button class="ev-submit" type="submit">
                    <i class="bi bi-search"></i>
                    Filtrar
                </button>
            </div>
        </form>
    </div>
</section>

<section class="ev-panel">
    <div class="ev-panel-head">
        <h3 class="ev-panel-title">
            <i class="bi bi-paperclip"></i>
            Evidencias registradas
        </h3>

        <span class="ev-meta">
            <?= number_format(count($evidencias), 0, ',', '.') ?> registro(s)
        </span>
    </div>

    <div class="ev-panel-body">
        <?php if (!$evidencias): ?>
            <div class="ev-empty">
                No hay evidencias con los criterios actuales.
            </div>
        <?php else: ?>
            <div class="ev-list">
                <?php foreach ($evidencias as $ev): ?>
                    <?php
                    $tipo = (string)($ev['tipo'] ?? 'archivo');
                    $mime = (string)($ev['mime_type'] ?? '');
                    $icon = ev_icono($tipo, $mime);
                    ?>

                    <article class="ev-item">
                        <div class="ev-icon">
                            <i class="bi <?= e($icon) ?>"></i>
                        </div>

                        <div>
                            <div class="ev-name"><?= e($ev['nombre_archivo']) ?></div>

                            <div>
                                <span class="ev-badge soft"><?= e(ev_label($tipo)) ?></span>
                                <span class="ev-badge <?= e(ev_badge((string)$ev['semaforo'])) ?>">
                                    Semáforo <?= e(ev_label((string)$ev['semaforo'])) ?>
                                </span>
                                <span class="ev-badge <?= e(ev_badge((string)$ev['prioridad'])) ?>">
                                    Prioridad <?= e(ev_label((string)$ev['prioridad'])) ?>
                                </span>
                            </div>

                            <div class="ev-meta">
                                Caso:
                                <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$ev['caso_id'] ?>">
                                    <?= e($ev['numero_caso']) ?>
                                </a>
                                · Subida:
                                <?= e(ev_fecha((string)$ev['created_at'])) ?>

                                <?php if (!empty($ev['subido_por_nombre'])): ?>
                                    · Por <?= e($ev['subido_por_nombre']) ?>
                                <?php endif; ?>

                                <?php if (!empty($mime)): ?>
                                    · <?= e($mime) ?>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($ev['descripcion'])): ?>
                                <div class="ev-text">
                                    <?= e($ev['descripcion']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ev['relato'])): ?>
                                <div class="ev-text">
                                    <strong>Relato asociado:</strong>
                                    <?= e(ev_corto((string)$ev['relato'], 150)) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ev-actions-row">
                            <a class="ev-link" href="<?= APP_URL ?>/modules/evidencias/descargar.php?id=<?= (int)$ev['id'] ?>&modo=inline" target="_blank">
                                <i class="bi bi-eye"></i>
                                Ver
                            </a>

                            <a class="ev-link" href="<?= APP_URL ?>/modules/evidencias/descargar.php?id=<?= (int)$ev['id'] ?>&modo=download">
                                <i class="bi bi-download"></i>
                                Descargar
                            </a>

                            <a class="ev-link" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$ev['caso_id'] ?>&tab=evidencias">
                                <i class="bi bi-folder2-open"></i>
                                Expediente
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>