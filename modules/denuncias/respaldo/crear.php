<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();

$estadosCaso = [];

try {
    $estadosCaso = $pdo->query("
        SELECT id, codigo, nombre
        FROM estado_caso
        WHERE activo = 1
        ORDER BY orden_visual ASC, id ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $estadosCaso = [];
}

$pageTitle = 'Nueva denuncia · Metis';
$pageSubtitle = 'Registro inicial de denuncia, relato, denunciante e interviniente principal';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.den-create-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.den-create-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.8rem;
    font-weight: 900;
}

.den-create-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 820px;
    line-height: 1.55;
}

.den-create-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.den-create-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.den-create-btn:hover {
    color: #fff;
}

.den-form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(360px, .8fr);
    gap: 1.2rem;
    align-items: start;
}

.den-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1.3rem;
    margin-bottom: 1.2rem;
}

.den-title {
    font-size: .78rem;
    color: #2563eb;
    font-weight: 900;
    letter-spacing: .11em;
    text-transform: uppercase;
    padding-bottom: .65rem;
    margin-bottom: 1.15rem;
    border-bottom: 1px solid #dbeafe;
}

.den-label {
    display: block;
    font-size: .78rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.den-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

textarea.den-control {
    min-height: 190px;
    resize: vertical;
}

.den-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,.12);
}

.den-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.den-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

.den-check {
    display: flex;
    align-items: flex-start;
    gap: .55rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: .8rem;
    border-radius: 14px;
    color: #334155;
    font-weight: 800;
    font-size: .86rem;
}

.den-check small {
    display: block;
    color: #64748b;
    font-weight: 500;
    margin-top: .2rem;
    line-height: 1.35;
}

.den-submit {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 999px;
    background: #059669;
    color: #fff;
    padding: .72rem 1.15rem;
    font-size: .88rem;
    font-weight: 900;
    cursor: pointer;
}

.den-submit:hover {
    background: #047857;
}

.den-help {
    color: #475569;
    font-size: .88rem;
    line-height: 1.55;
}

.den-help ul {
    margin-bottom: 0;
}

.den-required {
    color: #dc2626;
}

@media (max-width: 1050px) {
    .den-form-grid {
        grid-template-columns: 1fr;
    }

    .den-grid-2,
    .den-grid-3 {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="den-create-hero">
    <h2>Nueva denuncia</h2>
    <p>
        Registra el relato inicial, datos del denunciante, contexto de los hechos
        y un primer interviniente. Luego podrás completar declaraciones, evidencias,
        clasificación y seguimiento desde el expediente.
    </p>

    <div class="den-create-actions">
        <a class="den-create-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-arrow-left"></i>
            Volver a denuncias
        </a>

        <a class="den-create-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>
    </div>
</section>

<form method="post" action="<?= APP_URL ?>/modules/denuncias/guardar.php" autocomplete="off">
    <?= CSRF::field() ?>

    <div class="den-form-grid">
        <section>
            <div class="den-card">
                <div class="den-title">
                    <i class="bi bi-megaphone"></i>
                    Relato principal
                </div>

                <div>
                    <label class="den-label">
                        Relato de los hechos <span class="den-required">*</span>
                    </label>
                    <textarea
                        class="den-control"
                        name="relato"
                        required
                        placeholder="Describe los hechos conocidos, circunstancias, personas involucradas, fecha aproximada y cualquier antecedente relevante."
                    ></textarea>
                </div>

                <div style="height:1rem;"></div>

                <div class="den-grid-3">
                    <div>
                        <label class="den-label">Contexto</label>
                        <input
                            class="den-control"
                            type="text"
                            name="contexto"
                            placeholder="Ej: sala, patio, redes sociales"
                        >
                    </div>

                    <div>
                        <label class="den-label">Lugar de los hechos</label>
                        <input
                            class="den-control"
                            type="text"
                            name="lugar_hechos"
                            placeholder="Ej: patio central"
                        >
                    </div>

                    <div>
                        <label class="den-label">Fecha de los hechos</label>
                        <input
                            class="den-control"
                            type="datetime-local"
                            name="fecha_hechos"
                        >
                    </div>
                </div>

                <div style="height:1rem;"></div>

                <label class="den-check">
                    <input type="checkbox" name="involucra_moviles" value="1">
                    <span>
                        Involucra dispositivos móviles, redes sociales o medios digitales
                        <small>Marca esta opción si existen mensajes, fotografías, audios, videos o publicaciones digitales.</small>
                    </span>
                </label>
            </div>

            <div class="den-card">
                <div class="den-title">
                    <i class="bi bi-person-lines-fill"></i>
                    Denunciante
                </div>

                <div class="den-grid-2">
                    <div>
                        <label class="den-label">Nombre denunciante</label>
                        <input
                            class="den-control"
                            type="text"
                            name="denunciante_nombre"
                            placeholder="Nombre completo"
                        >
                    </div>

                    <div>
                        <label class="den-label">RUN denunciante</label>
                        <input
                            class="den-control"
                            type="text"
                            name="denunciante_run"
                            placeholder="0-0"
                        >
                    </div>
                </div>

                <div style="height:1rem;"></div>

                <label class="den-check">
                    <input type="checkbox" name="es_anonimo" value="1">
                    <span>
                        Solicita reserva de identidad
                        <small>El expediente mostrará el caso como identidad reservada y evitará exponer públicamente el nombre del denunciante.</small>
                    </span>
                </label>
            </div>

            <div class="den-card">
                <div class="den-title">
                    <i class="bi bi-person-plus"></i>
                    Primer interviniente
                </div>

                <div class="den-grid-3">
                    <div>
                        <label class="den-label">Tipo persona</label>
                        <select class="den-control" name="participante_tipo_persona">
                            <option value="alumno">Alumno</option>
                            <option value="apoderado">Apoderado</option>
                            <option value="docente">Docente</option>
                            <option value="asistente">Asistente</option>
                            <option value="externo" selected>Externo / no vinculado</option>
                        </select>
                    </div>

                    <div>
                        <label class="den-label">Rol en el caso</label>
                        <select class="den-control" name="participante_rol_en_caso">
                            <option value="victima">Víctima / afectado</option>
                            <option value="denunciante">Denunciante</option>
                            <option value="denunciado">Denunciado</option>
                            <option value="testigo">Testigo</option>
                            <option value="involucrado" selected>Involucrado</option>
                        </select>
                    </div>

                    <div>
                        <label class="den-label">RUN</label>
                        <input
                            class="den-control"
                            type="text"
                            name="participante_run"
                            placeholder="0-0"
                        >
                    </div>
                </div>

                <div style="height:1rem;"></div>

                <div>
                    <label class="den-label">Nombre del primer interviniente</label>
                    <input
                        class="den-control"
                        type="text"
                        name="participante_nombre"
                        placeholder="Puedes dejarlo vacío si aún no está identificado"
                    >
                </div>

                <div style="height:1rem;"></div>

                <div>
                    <label class="den-label">Observación del interviniente</label>
                    <input
                        class="den-control"
                        type="text"
                        name="participante_observacion"
                        placeholder="Ej: estudiante mencionado en el relato, testigo inicial, etc."
                    >
                </div>

                <div style="height:1rem;"></div>

                <label class="den-check">
                    <input type="checkbox" name="participante_reserva" value="1">
                    <span>
                        Este interviniente solicita reserva de identidad
                        <small>Útil para testigos o denunciantes que requieren protección de identidad.</small>
                    </span>
                </label>
            </div>
        </section>

        <aside>
            <div class="den-card">
                <div class="den-title">
                    <i class="bi bi-sliders"></i>
                    Control inicial
                </div>

                <div>
                    <label class="den-label">Estado formal</label>
                    <select class="den-control" name="estado_caso_id">
                        <option value="">Recepción / por defecto</option>

                        <?php foreach ($estadosCaso as $estado): ?>
                            <option
                                value="<?= (int)$estado['id'] ?>"
                                <?= (string)$estado['codigo'] === 'recepcion' ? 'selected' : '' ?>
                            >
                                <?= e($estado['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="height:1rem;"></div>

                <div>
                    <label class="den-label">Semáforo inicial</label>
                    <select class="den-control" name="semaforo">
                        <option value="verde" selected>Verde</option>
                        <option value="amarillo">Amarillo</option>
                        <option value="rojo">Rojo</option>
                    </select>
                </div>

                <div style="height:1rem;"></div>

                <div>
                    <label class="den-label">Prioridad inicial</label>
                    <select class="den-control" name="prioridad">
                        <option value="baja">Baja</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                    </select>
                </div>

                <div style="height:1rem;"></div>

                <label class="den-check">
                    <input type="checkbox" name="requiere_reanalisis_ia" value="1">
                    <span>
                        Requiere análisis especializado
                        <small>Marca esta opción si el caso requiere revisión posterior, clasificación normativa o análisis IA.</small>
                    </span>
                </label>
            </div>

            <div class="den-card">
                <div class="den-title">
                    <i class="bi bi-info-circle"></i>
                    Criterio de registro
                </div>

                <div class="den-help">
                    <p>
                        Al guardar, Metis generará automáticamente un número de caso,
                        creará el expediente, registrará historial inicial y abrirá el caso
                        en estado operativo <strong>abierto</strong>.
                    </p>

                    <p>
                        Luego podrás agregar:
                    </p>

                    <ul>
                        <li>Participantes adicionales.</li>
                        <li>Declaraciones.</li>
                        <li>Evidencias.</li>
                        <li>Alertas.</li>
                        <li>Clasificación / Aula Segura.</li>
                        <li>Historial y seguimiento.</li>
                    </ul>
                </div>
            </div>

            <div class="den-card">
                <button class="den-submit" type="submit">
                    <i class="bi bi-save"></i>
                    Guardar denuncia
                </button>
            </div>
        </aside>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>