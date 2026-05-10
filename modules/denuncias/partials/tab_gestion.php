<?php
$gestionEjecutiva = $gestionEjecutiva ?? [];
$ie = $indicadoresEjecutivos ?? [];
?>

<section class="exp-card">
    <div class="exp-title">Gestión ejecutiva del caso</div>

    <?php if (true): ?>
        <div class="exp-management-kpis">
            <article>
                <span>Acciones abiertas</span>
                <strong><?= number_format((int)($ie['gestion_pendiente'] ?? 0), 0, ',', '.') ?></strong>
            </article>

            <article>
                <span>Acciones vencidas</span>
                <strong class="<?= ((int)($ie['gestion_vencida'] ?? 0) > 0) ? 'danger' : 'ok' ?>">
                    <?= number_format((int)($ie['gestion_vencida'] ?? 0), 0, ',', '.') ?>
                </strong>
            </article>

            <article>
                <span>Riesgo ejecutivo</span>
                <strong class="<?= e((string)($ie['riesgo_clase'] ?? 'soft')) ?>">
                    <?= e((string)($ie['riesgo_texto'] ?? 'Sin evaluación')) ?>
                </strong>
            </article>
        </div>

        <div class="exp-help">
            Registra compromisos internos, responsables, vencimientos y tareas de conducción directiva. Esta sección no reemplaza el seguimiento pedagógico o disciplinario; lo complementa con control ejecutivo.
        </div>

        <form method="post" class="exp-management-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="crear_accion_ejecutiva">

            <div class="exp-field full">
                <label class="exp-label">Título de la acción *</label>
                <input class="exp-control" type="text" name="titulo" maxlength="180" placeholder="Ej: Entrevistar apoderado del estudiante afectado" required>
            </div>

            <div class="exp-field full">
                <label class="exp-label">Descripción / instrucción ejecutiva</label>
                <textarea class="exp-control" name="descripcion" placeholder="Detalle de la acción, criterio de cierre o antecedente a verificar"></textarea>
            </div>

            <div>
                <label class="exp-label">Responsable</label>
                <input class="exp-control" type="text" name="responsable_nombre" maxlength="160" placeholder="Nombre del responsable">
            </div>

            <div>
                <label class="exp-label">Rol / cargo</label>
                <input class="exp-control" type="text" name="responsable_rol" maxlength="120" placeholder="Enc. convivencia, inspectoría, dirección, etc.">
            </div>

            <div>
                <label class="exp-label">Prioridad</label>
                <select class="exp-control" name="prioridad">
                    <option value="baja">Baja</option>
                    <option value="media" selected>Media</option>
                    <option value="alta">Alta</option>
                    <option value="critica">Crítica</option>
                </select>
            </div>

            <div>
                <label class="exp-label">Fecha compromiso</label>
                <input class="exp-control" type="date" name="fecha_compromiso">
            </div>

            <div class="exp-field full">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-plus-circle"></i>
                    Agregar acción ejecutiva
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="exp-card">
        <div class="exp-title">Acciones ejecutivas registradas</div>

        <?php if (!$gestionEjecutiva): ?>
            <div class="exp-empty">
                No hay acciones ejecutivas registradas para este expediente.
            </div>
        <?php else: ?>
            <?php foreach ($gestionEjecutiva as $accion): ?>
                <?php
                $estado = (string)($accion['estado'] ?? 'pendiente');
                $prioridad = (string)($accion['prioridad'] ?? 'media');
                $fechaCompromiso = (string)($accion['fecha_compromiso'] ?? '');
                $vencida = $fechaCompromiso !== '' && $fechaCompromiso < date('Y-m-d') && in_array($estado, ['pendiente', 'en_proceso'], true);
                ?>

                <article class="exp-management-item <?= $vencida ? 'overdue' : '' ?>">
                    <div class="exp-management-head">
                        <div>
                            <div class="exp-item-title">
                                <?= e((string)$accion['titulo']) ?>
                            </div>

                            <div class="exp-item-meta">
                                Creada: <?= e(caso_fecha((string)$accion['created_at'])) ?>
                                <?php if (!empty($accion['creado_por_nombre'])): ?>
                                    · <?= e((string)$accion['creado_por_nombre']) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <span class="exp-badge <?= e(caso_badge_class($estado)) ?>">
                                <?= e(caso_label($estado)) ?>
                            </span>

                            <span class="exp-badge <?= e(caso_badge_class($prioridad)) ?>">
                                <?= e(caso_label($prioridad)) ?>
                            </span>

                            <?php if ($vencida): ?>
                                <span class="exp-badge danger">Vencida</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($accion['descripcion'])): ?>
                        <div class="exp-item-text">
                            <?= nl2br(e((string)$accion['descripcion'])) ?>
                        </div>
                    <?php endif; ?>

                    <div class="exp-management-meta-grid">
                        <div>
                            <strong>Responsable</strong>
                            <span><?= e((string)($accion['responsable_nombre'] ?? 'No asignado')) ?></span>
                        </div>

                        <div>
                            <strong>Rol / cargo</strong>
                            <span><?= e((string)($accion['responsable_rol'] ?? 'No informado')) ?></span>
                        </div>

                        <div>
                            <strong>Fecha compromiso</strong>
                            <span><?= e($fechaCompromiso !== '' ? caso_fecha_corta($fechaCompromiso) : 'Sin fecha') ?></span>
                        </div>

                        <div>
                            <strong>Cierre</strong>
                            <span><?= e(!empty($accion['fecha_cumplimiento']) ? caso_fecha((string)$accion['fecha_cumplimiento']) : '-') ?></span>
                        </div>
                    </div>

                    <?php if (in_array($estado, ['pendiente', 'en_proceso'], true)): ?>
                        <form method="post" class="exp-management-update">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="_accion" value="actualizar_accion_ejecutiva">
                            <input type="hidden" name="accion_id" value="<?= (int)$accion['id'] ?>">

                            <div>
                                <label class="exp-label">Estado</label>
                                <select class="exp-control" name="estado">
                                    <option value="pendiente" <?= $estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                    <option value="en_proceso" <?= $estado === 'en_proceso' ? 'selected' : '' ?>>En proceso</option>
                                    <option value="cumplida">Cumplida</option>
                                    <option value="descartada">Descartada</option>
                                </select>
                            </div>

                            <div>
                                <label class="exp-label">Prioridad</label>
                                <select class="exp-control" name="prioridad">
                                    <option value="baja" <?= $prioridad === 'baja' ? 'selected' : '' ?>>Baja</option>
                                    <option value="media" <?= $prioridad === 'media' ? 'selected' : '' ?>>Media</option>
                                    <option value="alta" <?= $prioridad === 'alta' ? 'selected' : '' ?>>Alta</option>
                                    <option value="critica" <?= $prioridad === 'critica' ? 'selected' : '' ?>>Crítica</option>
                                </select>
                            </div>

                            <div>
                                <label class="exp-label">Fecha compromiso</label>
                                <input class="exp-control" type="date" name="fecha_compromiso" value="<?= e($fechaCompromiso) ?>">
                            </div>

                            <div>
                                <label class="exp-label">Nota de actualización</label>
                                <input class="exp-control" type="text" name="nota" placeholder="Comentario breve">
                            </div>

                            <div class="exp-field full">
                                <button class="exp-submit blue" type="submit">
                                    <i class="bi bi-save"></i>
                                    Actualizar acción
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php
// ── Marcadores normativos ─────────────────────────────────
// Catálogo desde DB (fallback hardcoded si migración no ejecutada aún)
$_mgFallback = [
    ['codigo' => 'afecta_buen_trato',            'nombre' => 'Afecta buen trato',                    'desc' => 'Trato incompatible con relaciones respetuosas y protectoras.',          'grupo' => 'ley21809'],
    ['codigo' => 'acoso_escolar',                'nombre' => 'Posible acoso escolar',                 'desc' => 'Agresión u hostigamiento reiterado, presencial o digital.',             'grupo' => 'ley21809'],
    ['codigo' => 'violencia_fisica',             'nombre' => 'Violencia física',                      'desc' => 'Golpes, empujones, lesiones u otra afectación corporal.',               'grupo' => 'ley21809'],
    ['codigo' => 'violencia_psicologica',        'nombre' => 'Violencia psicológica',                 'desc' => 'Amenazas, humillaciones, hostigamiento o aislamiento.',                 'grupo' => 'ley21809'],
    ['codigo' => 'discriminacion',               'nombre' => 'Discriminación',                        'desc' => 'Trato arbitrario por condición personal, familiar o social.',           'grupo' => 'ley21809'],
    ['codigo' => 'ciberacoso_medios_digitales',  'nombre' => 'Ciberacoso / medio digital',            'desc' => 'Celular, redes sociales, mensajería, grabación o difusión.',            'grupo' => 'ley21809'],
    ['codigo' => 'afecta_salud_mental',          'nombre' => 'Afecta bienestar o salud mental',      'desc' => 'Angustia, temor u otro riesgo psicosocial preliminar.',                  'grupo' => 'ley21809'],
    ['codigo' => 'requiere_derivacion',          'nombre' => 'Requiere derivación o apoyo externo',  'desc' => 'Posible apoyo de redes, salud, familia u otra institución.',            'grupo' => 'ley21809'],
    ['codigo' => 'requiere_medida_formativa',    'nombre' => 'Medida formativa',                     'desc' => 'Reflexión, aprendizaje, diálogo guiado o acción pedagógica.',           'grupo' => 'rex782'],
    ['codigo' => 'requiere_apoyo_psicosocial',   'nombre' => 'Apoyo psicosocial',                    'desc' => 'Acompañamiento, contención, derivación o seguimiento.',                 'grupo' => 'rex782'],
    ['codigo' => 'requiere_medida_reparatoria',  'nombre' => 'Medida reparatoria',                   'desc' => 'Reparación del daño o recomposición de vínculos.',                      'grupo' => 'rex782'],
    ['codigo' => 'posible_medida_disciplinaria', 'nombre' => 'Posible medida disciplinaria',         'desc' => 'Verificar falta del Reglamento Interno y procedimiento.',               'grupo' => 'rex782'],
    ['codigo' => 'requiere_justo_procedimiento', 'nombre' => 'Justo procedimiento',                  'desc' => 'Notificación, descargos, escucha y resolución fundada.',                'grupo' => 'rex782'],
    ['codigo' => 'requiere_proporcionalidad',    'nombre' => 'Control de proporcionalidad',          'desc' => 'Relación entre gravedad, edad, contexto y efectos.',                    'grupo' => 'rex782'],
    ['codigo' => 'gestion_colaborativa_conflicto','nombre'=> 'Gestión colaborativa',                 'desc' => 'Mediación, diálogo o gestión colaborativa si procede.',                 'grupo' => 'rex782'],
    ['codigo' => 'posible_expulsion_cancelacion','nombre' => 'Medida gravosa en evaluación',         'desc' => 'Solo si los hechos justifican garantías reforzadas.',                   'grupo' => 'rex782'],
];

$_mgCatalogo = [];
try {
    $s = $pdo->query("SELECT codigo, nombre, grupo, descripcion AS desc FROM marcadores_normativos WHERE activo = 1 ORDER BY grupo, orden, id");
    $_mgCatalogo = $s->fetchAll();
} catch (Throwable $e) {}
if (!$_mgCatalogo) $_mgCatalogo = $_mgFallback;

$_mgPorGrupo = [];
foreach ($_mgCatalogo as $m) {
    $_mgPorGrupo[$m['grupo']][] = $m;
}

$_mgActivos = [];
try {
    $s = $pdo->prepare("SELECT marcador_codigo FROM caso_marcadores_normativos WHERE caso_id = ? AND colegio_id = ?");
    $s->execute([$casoId, $colegioId]);
    $_mgActivos = array_column($s->fetchAll(), 'marcador_codigo');
} catch (Throwable $e) {}
?>

<style>
.mg-markers-wrap   { margin-top: 1.5rem; }
.mg-markers-card   { background:#fff; border:1px solid #e3e8ef; border-radius:10px;
                     padding:1.25rem 1.5rem; margin-bottom:1rem; }
.mg-markers-title  { font-size:.9rem; font-weight:700; color:#1a3a5c;
                     display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }
.mg-markers-sub    { font-size:.78rem; color:#888; margin-bottom:1rem; }
.mg-check-grid     { display:grid; grid-template-columns:1fr 1fr; gap:.6rem; }
.mg-check-label    { display:flex; align-items:flex-start; gap:.55rem; cursor:pointer;
                     background:#f8fafd; border:1px solid #e3e8ef; border-radius:8px;
                     padding:.6rem .75rem; transition:all .15s; }
.mg-check-label:hover { border-color:#1a3a5c; background:#f0f5ff; }
.mg-check-label input[type=checkbox] { margin-top:.1rem; flex-shrink:0; accent-color:#1a3a5c; }
.mg-check-label.marcado { border-color:#1a3a5c; background:#e8f0fe; }
.mg-check-title    { font-size:.8rem; font-weight:600; color:#1a3a5c; display:block; }
.mg-check-desc     { font-size:.72rem; color:#888; margin-top:.1rem; display:block; }
.mg-save-btn       { background:#1a3a5c; color:#fff; border:none; border-radius:8px;
                     padding:.5rem 1.25rem; font-size:.83rem; font-weight:700;
                     cursor:pointer; margin-top:1rem; }
.mg-save-btn:hover { background:#14304f; }
@media(max-width:600px){ .mg-check-grid { grid-template-columns:1fr; } }
</style>

<div class="mg-markers-wrap">
    <form method="post">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="guardar_marcadores_normativos">
        <input type="hidden" name="_return_tab" value="gestion">

        <?php
        $mgGrupoMeta = [
            'ley21809' => ['titulo' => 'Ley 21.809 — Convivencia, buen trato y bienestar', 'icon' => 'bi-shield-check'],
            'rex782'   => ['titulo' => 'REX 782 — Medidas y procedimiento',                 'icon' => 'bi-clipboard2-pulse'],
        ];
        foreach ($mgGrupoMeta as $grupo => $meta):
        ?>
        <div class="mg-markers-card">
            <div class="mg-markers-title">
                <i class="bi <?= $meta['icon'] ?>"></i>
                <?= e($meta['titulo']) ?>
            </div>
            <div class="mg-markers-sub">
                <?= $grupo === 'ley21809'
                    ? 'Marca los elementos que caracterizan el caso. Son orientadores para la gestión y no reemplazan la investigación.'
                    : 'Sirven para anticipar medidas formativas, apoyo, reparación, procedimiento disciplinario o control de proporcionalidad.' ?>
            </div>
            <div class="mg-check-grid">
                <?php foreach ($_mgPorGrupo[$grupo] ?? [] as $m):
                    $marcado = in_array($m['codigo'], $_mgActivos, true);
                ?>
                    <label class="mg-check-label <?= $marcado ? 'marcado' : '' ?>">
                        <input type="checkbox" name="marcadores[]" value="<?= e($m['codigo']) ?>" <?= $marcado ? 'checked' : '' ?>>
                        <span>
                            <span class="mg-check-title"><?= e($m['nombre']) ?></span>
                            <?php if (!empty($m['desc'])): ?>
                            <span class="mg-check-desc"><?= e($m['desc']) ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="text-align:right;">
            <button type="submit" class="mg-save-btn">
                <i class="bi bi-check-circle-fill"></i> Guardar marcadores
            </button>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.mg-check-label input[type=checkbox]').forEach(function(cb){
    cb.addEventListener('change', function(){
        cb.closest('.mg-check-label').classList.toggle('marcado', cb.checked);
    });
});
</script>
