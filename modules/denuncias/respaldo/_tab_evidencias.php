<?php
// Determinar ícono Bootstrap Icons según extensión
function iconByExt(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'                     => 'bi-file-earmark-pdf',
        'doc','docx'              => 'bi-file-earmark-word',
        'xls','xlsx','csv'        => 'bi-file-earmark-excel',
        'ppt','pptx'              => 'bi-file-earmark-ppt',
        'jpg','jpeg','png','webp',
        'gif','bmp','svg'         => 'bi-file-earmark-image',
        'mp4','mov','avi','mkv'   => 'bi-file-earmark-play',
        'mp3','wav','ogg'         => 'bi-file-earmark-music',
        'zip','rar','7z','tar'    => 'bi-file-earmark-zip',
        default                   => 'bi-file-earmark',
    };
}
function colorByExt(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'                     => '#ef4444',
        'doc','docx'              => '#2563eb',
        'xls','xlsx','csv'        => '#16a34a',
        'ppt','pptx'              => '#ea580c',
        'jpg','jpeg','png','webp',
        'gif','bmp','svg'         => '#8b5cf6',
        'mp4','mov','avi','mkv'   => '#0ea5e9',
        default                   => '#64748b',
    };
}
?>

<!-- ── Listado de evidencias ── -->
<div class="tab-section-title">
    <span class="icon-box"><i class="bi bi-paperclip"></i></span>
    Evidencias adjuntas
    <?php if ($evidencias): ?>
        <span style="font-size:.7rem;font-weight:700;background:#eff6ff;color:#2563eb;
                     padding:.2em .7em;border-radius:20px;margin-left:.25rem;">
            <?= count($evidencias) ?>
        </span>
    <?php endif; ?>
</div>

<?php if (!$evidencias): ?>
    <div class="empty-state">
        <i class="bi bi-paperclip"></i>
        <p>Sin evidencias registradas para este caso.</p>
    </div>
<?php else: ?>
    <?php foreach ($evidencias as $ev):
        $nombre = (string)$ev['nombre_archivo'];
        $icon   = iconByExt($nombre);
        $color  = colorByExt($nombre);
    ?>
    <div class="evidencia-card">
        <div class="evidencia-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
            <i class="bi <?= $icon ?>"></i>
        </div>
        <div class="flex-grow-1">
            <div class="item-card__title"><?= e($nombre) ?></div>
            <?php if (!empty($ev['descripcion'])): ?>
                <div class="item-card__meta" style="margin-top:.2rem;">
                    <?= e((string)$ev['descripcion']) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="flex-shrink-0">
            <a href="<?= APP_URL . '/' . ltrim((string)$ev['ruta'], '/') ?>"
               target="_blank"
               class="btn btn-sm"
               style="background:#eff6ff;color:#2563eb;border:1.5px solid #bfdbfe;
                      border-radius:8px;font-size:.78rem;font-weight:600;white-space:nowrap;">
                <i class="bi bi-box-arrow-up-right me-1"></i> Abrir
            </a>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Subir evidencia ── -->
<div class="section-divider"><span>Subir nueva evidencia</span></div>

<div class="form-panel">
    <form method="POST" action="<?= APP_URL ?>/modules/denuncias/guardar_evidencia.php"
          enctype="multipart/form-data">
        <?= CSRF::field(); ?>
        <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Descripción <span style="color:#94a3b8;">(opcional)</span></label>
                <input type="text" name="descripcion" class="form-control" maxlength="255"
                       placeholder="Ej: Fotografía del incidente…">
            </div>
            <div class="col-md-6">
                <label class="form-label">Archivo</label>
                <input type="file" name="archivo" class="form-control" required>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:.35rem;">
                    Formatos aceptados: PDF, Word, Excel, imágenes, video, audio.
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary-sgce">
                <i class="bi bi-cloud-upload me-1"></i> Subir evidencia
            </button>
        </div>
    </form>
</div>
