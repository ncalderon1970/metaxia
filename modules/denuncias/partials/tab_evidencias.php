    <section class="exp-card">
        <div class="exp-title">Subir evidencia</div>

        <form method="post" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="subir_evidencia">

            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Tipo</label>
                    <select class="exp-control" name="tipo">
                        <option value="archivo">Archivo</option>
                        <option value="imagen">Imagen</option>
                        <option value="documento">Documento</option>
                        <option value="audio">Audio</option>
                        <option value="video">Video</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Archivo</label>
                    <input class="exp-control" type="file" name="archivo" required>
                </div>

                <div>
                    <label class="exp-label">Descripción</label>
                    <input class="exp-control" type="text" name="descripcion">
                </div>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-upload"></i>
                    Subir evidencia
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Evidencias registradas</div>

        <?php if (!$evidencias): ?>
            <div class="exp-empty">No hay evidencias registradas.</div>
        <?php else: ?>
            <?php foreach ($evidencias as $ev): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($ev['nombre_archivo']) ?></div>
                    <div class="exp-item-meta">
                        <?= e(caso_label($ev['tipo'])) ?> ·
                        <?= e(caso_fecha((string)$ev['created_at'])) ?> ·
                        <?= e($ev['mime_type'] ?? 'sin tipo') ?>
                    </div>

                    <?php if (!empty($ev['descripcion'])): ?>
                        <div class="exp-item-text"><?= e($ev['descripcion']) ?></div>
                    <?php endif; ?>

                    <div style="margin-top:.8rem;">
                        <a
                            class="exp-submit blue"
                            style="text-decoration:none;"
                            href="<?= APP_URL ?>/modules/evidencias/descargar.php?id=<?= (int)$ev['id'] ?>&modo=inline"
                            target="_blank"
                        >
                            <i class="bi bi-box-arrow-up-right"></i>
                            Abrir archivo
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
