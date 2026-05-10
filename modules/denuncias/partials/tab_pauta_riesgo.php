<?php
/**
 * tab_pauta_riesgo.php — v2
 * Pauta de Evaluación de Riesgo — 4 dimensiones, 70 pts, semáforo 4 niveles.
 */

// Verificar que la tabla existe
$_pr_tabla_ok = false;
try {
    $pdo->query("SELECT 1 FROM caso_pauta_riesgo LIMIT 1");
    $_pr_tabla_ok = true;
} catch (Throwable $e) {
    $_pr_tabla_ok = false;
}

if (!$_pr_tabla_ok): ?>
<section class="exp-card">
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;
        padding:1.25rem 1.5rem;display:flex;align-items:flex-start;gap:.85rem;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;font-size:1.4rem;flex-shrink:0;"></i>
        <div>
            <div style="font-size:.92rem;font-weight:700;color:#b91c1c;margin-bottom:.4rem;">
                Tabla no encontrada: <code>caso_pauta_riesgo</code>
            </div>
            <div style="font-size:.82rem;color:#991b1b;line-height:1.6;">
                Debes ejecutar el SQL de instalación en phpMyAdmin antes de usar este módulo.<br>
                El archivo se llama <strong>caso_pauta_riesgo_v2.sql</strong> y está incluido en el ZIP de la pauta de riesgo.
            </div>
        </div>
    </div>
</section>
<?php return; endif;

// Cargar víctimas y testigos
$stmtVT = $pdo->prepare("
    SELECT *, nombre_referencial AS nombre
    FROM caso_participantes
    WHERE caso_id = ? AND rol_en_caso IN ('victima','testigo')
    ORDER BY FIELD(rol_en_caso,'victima','testigo'), id
");
$stmtVT->execute([$casoId]);
$intervinientes_vt = $stmtVT->fetchAll();

// Cargar historial completo de pautas (todas las aplicaciones)
$stmtPautas = $pdo->prepare("
    SELECT pr.*, u.nombre AS completada_por_nombre
    FROM caso_pauta_riesgo pr
    LEFT JOIN usuarios u ON u.id = pr.completada_por
    WHERE pr.caso_id = ?
    ORDER BY pr.alumno_id ASC, pr.rol_en_caso ASC, pr.id DESC
");
$stmtPautas->execute([$casoId]);
$pautasPorKey    = [];  // solo la más reciente por participante
$historialPorKey = [];  // todo el historial por participante
foreach ($stmtPautas->fetchAll() as $p) {
    $key = (int)($p['alumno_id'] ?? 0) . '_' . $p['rol_en_caso'];
    $historialPorKey[$key][] = $p;
    if (!isset($pautasPorKey[$key])) {
        $pautasPorKey[$key] = $p; // primera = más reciente (ORDER BY id DESC)
    }
}

// Definición de la pauta
$DIMENSIONES = [
    [
        'codigo' => 'd1', 'titulo' => 'Características del hecho', 'max' => 20,
        'items' => [
            ['campo'=>'d1_frecuencia', 'label'=>'Frecuencia', 'sub'=>'¿Con qué frecuencia ocurrió el hecho?',
             'opts'=>[['val'=>1,'label'=>'Hecho aislado'],['val'=>3,'label'=>'Más de una vez / patrón'],['val'=>5,'label'=>'Sistemático / crónico']]],
            ['campo'=>'d1_tipo_violencia', 'label'=>'Tipo de violencia', 'sub'=>'Clasificación de la conducta denunciada',
             'opts'=>[['val'=>2,'label'=>'Verbal / psicológica'],['val'=>3,'label'=>'Física sin lesiones'],['val'=>5,'label'=>'Física con lesiones / sexual / digital grave']]],
            ['campo'=>'d1_lugar', 'label'=>'Lugar del hecho', 'sub'=>'Dónde ocurrió la situación denunciada',
             'opts'=>[['val'=>1,'label'=>'Fuera del establecimiento'],['val'=>3,'label'=>'Dentro del establecimiento'],['val'=>5,'label'=>'Espacio íntimo / sin testigos']]],
            ['campo'=>'d1_medios', 'label'=>'Medios utilizados', 'sub'=>'Herramientas o medios empleados por el agresor',
             'opts'=>[['val'=>0,'label'=>'Sin medios adicionales'],['val'=>2,'label'=>'Redes sociales / mensajería'],['val'=>5,'label'=>'Armas, objetos o sustancias']]],
        ]
    ],
    [
        'codigo' => 'd2', 'titulo' => 'Vulnerabilidad de la víctima', 'max' => 20,
        'items' => [
            ['campo'=>'d2_edad', 'label'=>'Edad de la víctima', 'sub'=>'Rango etario del o la estudiante',
             'opts'=>[['val'=>1,'label'=>'15 a 18 años'],['val'=>3,'label'=>'10 a 14 años'],['val'=>5,'label'=>'Menor de 10 años']]],
            ['campo'=>'d2_condicion', 'label'=>'Condición especial', 'sub'=>'Diagnóstico o condición de vulnerabilidad adicional',
             'opts'=>[['val'=>0,'label'=>'Sin condición especial'],['val'=>2,'label'=>'NEE / PIE'],['val'=>4,'label'=>'TEA / Discapacidad intelectual'],['val'=>5,'label'=>'Condición de salud mental activa']]],
            ['campo'=>'d2_red_familiar', 'label'=>'Red de apoyo familiar', 'sub'=>'Respuesta y compromiso de la familia ante el hecho',
             'opts'=>[['val'=>0,'label'=>'Familia informada y colabora'],['val'=>2,'label'=>'Familia informada pero pasiva'],['val'=>4,'label'=>'Familia desinformada o en conflicto'],['val'=>5,'label'=>'Sin red familiar / situación de riesgo familiar']]],
            ['campo'=>'d2_victimizacion', 'label'=>'Historia previa de victimización', 'sub'=>'Antecedentes de victimización del alumno/a',
             'opts'=>[['val'=>0,'label'=>'Sin antecedentes'],['val'=>2,'label'=>'Un episodio previo'],['val'=>5,'label'=>'Patrón / víctima recurrente']]],
        ]
    ],
    [
        'codigo' => 'd3', 'titulo' => 'Características del agresor', 'max' => 15,
        'items' => [
            ['campo'=>'d3_quien_agresor', 'label'=>'¿Quién es el agresor?', 'sub'=>'Relación del agresor con el establecimiento',
             'opts'=>[['val'=>1,'label'=>'Estudiante sin antecedentes'],['val'=>3,'label'=>'Estudiante con antecedentes previos'],['val'=>5,'label'=>'Adulto del establecimiento'],['val'=>5,'label'=>'Adulto externo']]],
            ['campo'=>'d3_actitud', 'label'=>'Actitud del agresor', 'sub'=>'Respuesta del agresor frente al hecho',
             'opts'=>[['val'=>0,'label'=>'Reconoce y se arrepiente'],['val'=>2,'label'=>'Niega o minimiza'],['val'=>5,'label'=>'Amenazas o intimidación activa']]],
            ['campo'=>'d3_antecedentes', 'label'=>'Antecedentes del agresor', 'sub'=>'Historial de expedientes de convivencia',
             'opts'=>[['val'=>0,'label'=>'Sin expedientes previos'],['val'=>2,'label'=>'Un expediente previo'],['val'=>5,'label'=>'Dos o más expedientes / reincidente']]],
        ]
    ],
    [
        'codigo' => 'd4', 'titulo' => 'Contexto institucional y familiar', 'max' => 15,
        'items' => [
            ['campo'=>'d4_visibilidad', 'label'=>'Visibilidad del hecho', 'sub'=>'Cuántas personas conocen el hecho dentro de la comunidad',
             'opts'=>[['val'=>1,'label'=>'Solo lo sabe el encargado'],['val'=>2,'label'=>'Lo saben algunos actores'],['val'=>4,'label'=>'Hay testigos / es de conocimiento masivo']]],
            ['campo'=>'d4_riesgo_repeticion', 'label'=>'Riesgo de repetición', 'sub'=>'Posibilidad de que el hecho vuelva a ocurrir',
             'opts'=>[['val'=>0,'label'=>'Agresor separado del contexto'],['val'=>3,'label'=>'Sin medidas de separación aún'],['val'=>5,'label'=>'Contacto inevitable (misma aula)']]],
            ['campo'=>'d4_familia_agresor', 'label'=>'Compromiso familia del agresor', 'sub'=>'Disposición de la familia del agresor ante el caso',
             'opts'=>[['val'=>0,'label'=>'Colabora y acepta medidas'],['val'=>2,'label'=>'Pasiva o difícil de contactar'],['val'=>4,'label'=>'Hostil / niega el hecho'],['val'=>5,'label'=>'No localizable']]],
            ['campo'=>'d4_derivacion', 'label'=>'Necesidad de derivación externa', 'sub'=>'¿Requiere intervención de organismos externos?',
             'opts'=>[['val'=>0,'label'=>'No requiere'],['val'=>1,'label'=>'Derivación sugerida (salud / JUNAEB)'],['val'=>5,'label'=>'Derivación urgente (OLN / Juzgados de Familia)']]],
        ]
    ],
];

$ESCALAS = [
    ['campo'=>'esc_menor_8',           'label'=>'Víctima menor de 8 años',                  'escala_a'=>'alto',    'color'=>'#ef4444'],
    ['campo'=>'esc_agresor_funcionario','label'=>'Agresor es funcionario adulto del establecimiento', 'escala_a'=>'alto',  'color'=>'#ef4444'],
    ['campo'=>'esc_violencia_sexual',  'label'=>'Violencia sexual de cualquier tipo',         'escala_a'=>'critico', 'color'=>'#1f2937'],
    ['campo'=>'esc_amenazas_armas',    'label'=>'Amenazas con armas o instrumentos',          'escala_a'=>'alto',    'color'=>'#ef4444'],
    ['campo'=>'esc_tea_sin_red',       'label'=>'Víctima con TEA y sin red familiar',          'escala_a'=>'medio',   'color'=>'#f59e0b'],
    ['campo'=>'esc_reincidencia',      'label'=>'Reincidencia del agresor con misma víctima', 'escala_a'=>'alto',    'color'=>'#ef4444'],
];

$NIVELES = [
    'bajo'    => ['min'=>0,  'max'=>15, 'label'=>'Bajo',    'emoji'=>'🟢', 'color'=>'#16a34a', 'bg'=>'#ecfdf5', 'border'=>'#bbf7d0', 'accion'=>'Seguimiento regular. Próxima revisión en 15 días.'],
    'medio'   => ['min'=>16, 'max'=>30, 'label'=>'Medio',   'emoji'=>'🟡', 'color'=>'#92400e', 'bg'=>'#fffbeb', 'border'=>'#fde68a', 'accion'=>'Intervención planificada dentro de 48 horas. Informar a dirección.'],
    'alto'    => ['min'=>31, 'max'=>45, 'label'=>'Alto',    'emoji'=>'🔴', 'color'=>'#b91c1c', 'bg'=>'#fef2f2', 'border'=>'#fecaca', 'accion'=>'Intervención inmediata. Medidas de resguardo obligatorias. Notificar apoderados hoy.'],
    'critico' => ['min'=>46, 'max'=>70, 'label'=>'Crítico', 'emoji'=>'⚫', 'color'=>'#0f172a', 'bg'=>'#f1f5f9', 'border'=>'#334155', 'accion'=>'Derivación urgente. Coordinación OLN / Juzgados de Familia. Dirección debe actuar en el día.'],
];

// Función para calcular nivel según puntaje
if (!function_exists('pr_nivel_por_puntaje')) {
function pr_nivel_por_puntaje(int $pts): string {
    if ($pts <= 15) return 'bajo';
    if ($pts <= 30) return 'medio';
    if ($pts <= 45) return 'alto';
    return 'critico';
}
}

// Función para aplicar factores de escala
if (!function_exists('pr_aplicar_escala')) {
function pr_aplicar_escala(string $nivel, array $escalas, array $valores): string {
    $orden = ['bajo'=>0,'medio'=>1,'alto'=>2,'critico'=>3];
    $actual = $orden[$nivel] ?? 0;
    foreach ($escalas as $e) {
        if (!empty($valores[$e['campo']])) {
            $minimo = $orden[$e['escala_a']] ?? 0;
            if ($minimo > $actual) $actual = $minimo;
        }
    }
    return array_flip($orden)[$actual] ?? 'bajo';
}
}

// ── Manejar POST ──────────────────────────────────────────────
// ── Guardar derivación sobre pauta existente ──────────────────
if (($_POST['_pauta_action'] ?? '') === 'guardar_derivacion') {
    $pautaId  = (int)($_POST['_pauta_id'] ?? 0);
    $derivado = (int)($_POST['der_derivado'] ?? 0);
    $fecha    = clean((string)($_POST['der_fecha'] ?? '')) ?: null;
    $entidad  = clean((string)($_POST['der_entidad'] ?? '')) ?: null;

    if ($pautaId > 0) {
        try {
            $pdo->prepare("
                UPDATE caso_pauta_riesgo
                SET derivado=?, fecha_derivacion=?, entidad_derivacion=?
                WHERE id=? AND caso_id=?
            ")->execute([$derivado, $fecha, $entidad, $pautaId, $casoId]);

            // Historial
            $userId2 = (int)($currentUser['id'] ?? 0);
            $pdo->prepare("INSERT INTO caso_historial (caso_id,accion,detalle,usuario_id,created_at) VALUES (?,'derivacion_pauta',?,?,NOW())")
                ->execute([$casoId,
                    'Derivación ' . ($derivado ? 'registrada' : 'actualizada') .
                    ($entidad ? ' — ' . $entidad : '') .
                    ($fecha   ? ' · ' . $fecha : ''),
                    $userId2 > 0 ? $userId2 : null]);
        } catch (Throwable $e) {}
    }
    $redir = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=pauta_riesgo&msg_ok=Derivaci%C3%B3n+registrada.';
    echo '<script>window.location.href=' . json_encode($redir) . ';</script>';
    exit;

    fin_pauta_post:;
}

if (($_POST['_pauta_action'] ?? '') === 'guardar_v2') {
    $rolP    = (string)($_POST['pauta_rol'] ?? 'victima');
    $alumId  = (int)($_POST['pauta_alumno_id'] ?? 0);
    $nombre  = clean((string)($_POST['pauta_nombre'] ?? ''));
    $userId  = (int)($currentUser['id'] ?? 0);

    $campos = [
        'd1_frecuencia','d1_tipo_violencia','d1_lugar','d1_medios',
        'd2_edad','d2_condicion','d2_red_familiar','d2_victimizacion',
        'd3_quien_agresor','d3_actitud','d3_antecedentes',
        'd4_visibilidad','d4_riesgo_repeticion','d4_familia_agresor','d4_derivacion',
    ];
    $vals = [];
    foreach ($campos as $c) {
        $vals[$c] = isset($_POST[$c]) ? (int)$_POST[$c] : null;
    }

    $pd1 = array_sum(array_filter([$vals['d1_frecuencia'],$vals['d1_tipo_violencia'],$vals['d1_lugar'],$vals['d1_medios']], 'is_int'));
    $pd2 = array_sum(array_filter([$vals['d2_edad'],$vals['d2_condicion'],$vals['d2_red_familiar'],$vals['d2_victimizacion']], 'is_int'));
    $pd3 = array_sum(array_filter([$vals['d3_quien_agresor'],$vals['d3_actitud'],$vals['d3_antecedentes']], 'is_int'));
    $pd4 = array_sum(array_filter([$vals['d4_visibilidad'],$vals['d4_riesgo_repeticion'],$vals['d4_familia_agresor'],$vals['d4_derivacion']], 'is_int'));
    $total = $pd1 + $pd2 + $pd3 + $pd4;

    $escalaCampos = ['esc_menor_8','esc_agresor_funcionario','esc_violencia_sexual','esc_amenazas_armas','esc_tea_sin_red','esc_reincidencia'];
    $escalaVals = [];
    foreach ($escalaCampos as $ec) { $escalaVals[$ec] = !empty($_POST[$ec]) ? 1 : 0; }

    $nivelCalc  = pr_nivel_por_puntaje($total);
    $nivelFinal = pr_aplicar_escala($nivelCalc, $ESCALAS, $escalaVals);

    $ajusteProf = (int)($_POST['ajuste_profesional'] ?? 0);
    $nivelAjuste = clean((string)($_POST['nivel_ajuste'] ?? ''));
    if ($ajusteProf && in_array($nivelAjuste, ['bajo','medio','alto','critico'], true)) {
        $nivelFinal = $nivelAjuste;
    }
    $justif = clean((string)($_POST['justificacion_ajuste'] ?? '')) ?: null;
    $obs    = clean((string)($_POST['pauta_observacion'] ?? '')) ?: null;
    $deriv  = (int)($_POST['pauta_derivado'] ?? 0);
    $fechaDeriv   = clean((string)($_POST['pauta_fecha_derivacion'] ?? '')) ?: null;
    $entidadDeriv = clean((string)($_POST['pauta_entidad_derivacion'] ?? '')) ?: null;

    $key = $alumId . '_' . $rolP;
    // Calcular número de aplicación
    $numAplic = 1;
    try {
        $stmtN = $pdo->prepare("SELECT COUNT(*) FROM caso_pauta_riesgo WHERE caso_id=? AND alumno_id=? AND rol_en_caso=?");
        $stmtN->execute([$casoId, $alumId>0?$alumId:null, $rolP]);
        $numAplic = (int)$stmtN->fetchColumn() + 1;
    } catch (Throwable $e) {}

    $motivoReap = clean((string)($_POST['motivo_reaplicacion'] ?? '')) ?: null;

    // ── Verificar firma electrónica (contraseña) ───────────────
    $contrasena = (string)($_POST['firma_contrasena'] ?? '');
    if ($contrasena === '') {
        $firmaError = 'Debes ingresar tu contraseña para firmar electrónicamente la pauta.';
    } else {
        // Cargar hash del usuario actual
        try {
            $stmtFirma = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id=? LIMIT 1");
            $stmtFirma->execute([$userId > 0 ? $userId : 0]);
            $hashBD = (string)($stmtFirma->fetchColumn() ?: '');
            if (!$hashBD || !password_verify($contrasena, $hashBD)) {
                $firmaError = 'Contraseña incorrecta. La pauta no fue guardada.';
            }
        } catch (Throwable $e) {
            $firmaError = 'No se pudo verificar la contraseña. Intenta nuevamente.';
        }
    }
    if (!empty($firmaError)) {
        // Mostrar error — no procesar el guardado
        echo '<div style="background:#fef2f2;border:1.5px solid #ef4444;border-radius:10px;
            padding:1rem 1.25rem;margin:1rem 0;display:flex;align-items:center;gap:.65rem;">
            <i class=\'bi bi-shield-exclamation\' style=\'color:#dc2626;font-size:1.2rem;\'></i>
            <span style=\'font-size:.88rem;font-weight:600;color:#b91c1c;\'>' . htmlspecialchars($firmaError) . '</span>
        </div>';
        // Continuar renderizando el partial normalmente (no hacer exit)
    } else {
        // Generar hash de firma
        $firmaTimestamp = date('Y-m-d H:i:s');
        $firmaData      = implode('|', [$userId, $firmaTimestamp, $casoId, $total, $nivelFinal, $nombre, $rolP]);
        $firmaHash      = hash('sha256', $firmaData . (defined('APP_KEY') ? APP_KEY : 'metis_sgce_2026'));
        $firmaIp        = $_SERVER['REMOTE_ADDR'] ?? null;
        $firmaNombre    = (string)($currentUser['nombre'] ?? '');
    }

    if (!empty($firmaError)) goto fin_pauta_post;

    // Siempre INSERT — nunca sobrescribe historial
    $data = array_merge(
        ['caso_id'=>$casoId,'alumno_id'=>$alumId>0?$alumId:null,'nombre_alumno'=>$nombre,
         'rol_en_caso'=>$rolP,'numero_aplicacion'=>$numAplic,'motivo_reaplicacion'=>$motivoReap],
        $vals, $escalaVals,
        ['puntaje_d1'=>$pd1,'puntaje_d2'=>$pd2,'puntaje_d3'=>$pd3,'puntaje_d4'=>$pd4,
         'puntaje_total'=>$total,'nivel_calculado'=>$nivelCalc,'nivel_final'=>$nivelFinal,
         'ajuste_profesional'=>$ajusteProf,'justificacion_ajuste'=>$justif,
         'derivado'=>$deriv,'fecha_derivacion'=>$fechaDeriv,'entidad_derivacion'=>$entidadDeriv,
         'observacion'=>$obs,'completada_por'=>$userId > 0 ? $userId : null,
         'firmado'=>1,'firma_hash'=>$firmaHash,'firma_timestamp'=>$firmaTimestamp,
         'firmado_por_id'=>$userId>0?$userId:null,'firmado_por_nombre'=>$firmaNombre,
         'firma_ip'=>$firmaIp,
        ]
    );
    $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($data)));
    $phs  = implode(',', array_fill(0, count($data), '?'));
    $pdo->prepare("INSERT INTO caso_pauta_riesgo ($cols) VALUES ($phs)")->execute(array_values($data));

    // Alerta automática si riesgo alto o crítico
    if (in_array($nivelFinal, ['alto','critico'], true)) {
        try {
            $pdo->prepare("INSERT INTO caso_alertas (caso_id,colegio_id,tipo_alerta,titulo,descripcion,estado,created_at) VALUES (?,?,'riesgo_victima',?,?,'pendiente',NOW())")
                ->execute([$casoId,$colegioId,'Riesgo '.strtoupper($nivelFinal).' — '.$nombre,'Pauta de valoración arroja nivel '.$nivelFinal.' ('.$total.'/70). '.$NIVELES[$nivelFinal]['accion']]);
        } catch (Throwable $e) {}
    }

    try {
        $pdo->prepare("INSERT INTO caso_historial (caso_id,accion,detalle,usuario_id,created_at) VALUES (?,'pauta_riesgo',?,?,NOW())")
            ->execute([$casoId,'Pauta de riesgo #'.$numAplic.' — '.$nombre.' ('.ucfirst($rolP).') Nivel '.strtoupper($nivelFinal).' ('.$total.'/70).', $userId>0?$userId:null]);
    } catch (Throwable $e) {}

    registrar_hito($pdo, $casoId, $colegioId, 102, $userId);

    // Redirect con JS porque el partial se incluye después de que layout_header ya envió HTML
    $redir = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=pauta_riesgo&msg_ok=Pauta+guardada.';
    echo '<script>window.location.href=' . json_encode($redir) . ';</script>';
    exit;
}

$msgOkPauta = clean((string)($_GET['msg_ok'] ?? ''));

// Participante seleccionado en el selector
$selectedKey = clean((string)($_GET['sel'] ?? ''));
// Si no hay selección y hay intervinientes, pre-seleccionar el primero sin pauta
if ($selectedKey === '' && !empty($intervinientes_vt)) {
    foreach ($intervinientes_vt as $_vt) {
        $_k = (int)($_vt['persona_id']??0) . '_' . $_vt['rol_en_caso'];
        if (!isset($pautasPorKey[$_k])) { $selectedKey = $_k; break; }
    }
    // Si todos tienen pauta, seleccionar el primero
    if ($selectedKey === '') {
        $_vt0 = $intervinientes_vt[0];
        $selectedKey = (int)($_vt0['persona_id']??0) . '_' . $_vt0['rol_en_caso'];
    }
}
?>
<style>
.pr2-card{background:#fff;border:1px solid var(--c-neutral-border);border-radius:var(--radius-md);padding:1.1rem 1.25rem;
    box-shadow:0 1px 3px rgba(15,23,42,.05);margin-bottom:1rem;}
.pr2-card.nivel-bajo{border-left:4px solid #22c55e;}
.pr2-card.nivel-medio{border-left:4px solid #f59e0b;}
.pr2-card.nivel-alto{border-left:4px solid #ef4444;}
.pr2-card.nivel-critico{border-left:4px solid #1f2937;}
.pr2-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;
    color:#2563eb;display:flex;align-items:center;gap:.4rem;margin:0 0 1rem;}
.pr2-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.9rem;}
.pr2-name{font-size:.92rem;font-weight:700;color:#0f172a;}
.pr2-role{font-size:.72rem;font-weight:600;padding:.18rem .55rem;border-radius:999px;border:1px solid;}
.pr2-role.victima{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
.pr2-role.testigo{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
.pr2-badge{display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;
    padding:.22rem .65rem;font-size:.74rem;font-weight:700;border:1px solid;}
.pr2-badge.bajo{background:#ecfdf5;border-color:#bbf7d0;color:#047857;}
.pr2-badge.medio{background:#fffbeb;border-color:#fde68a;color:#92400e;}
.pr2-badge.alto{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
.pr2-badge.critico{background:#f1f5f9;border-color:#334155;color:#0f172a;}
.pr2-dim{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;
    padding:.85rem 1rem;margin-bottom:.75rem;}
.pr2-dim-title{font-size:.78rem;font-weight:700;color:#1e3a8a;margin:0 0 .5rem;
    display:flex;align-items:center;justify-content:space-between;}
.pr2-dim-max{font-size:.7rem;color:#94a3b8;font-weight:400;}
.pr2-item{margin-bottom:.7rem;}
.pr2-item:last-child{margin-bottom:0;}
.pr2-item-label{font-size:.82rem;font-weight:600;color:#0f172a;margin-bottom:.15rem;}
.pr2-item-sub{font-size:.74rem;color:#64748b;margin-bottom:.45rem;}
.pr2-options{display:flex;flex-wrap:wrap;gap:.4rem;}
.pr2-opt{display:flex;align-items:center;gap:.35rem;cursor:pointer;
    padding:.4rem .65rem;border:1.5px solid #e2e8f0;border-radius:7px;
    font-size:.78rem;font-weight:500;color:#334155;transition:all .12s;white-space:nowrap;}
.pr2-opt:has(input:checked){border-color:#2563eb;background:#eff6ff;color:#1e40af;font-weight:600;}
.pr2-opt input{margin:0;width:13px;height:13px;accent-color:#2563eb;}
.pr2-escala-grid{display:grid;grid-template-columns:1fr 1fr;gap:.45rem;margin-bottom:.75rem;}
.pr2-esc-item{display:flex;align-items:center;gap:.45rem;padding:.5rem .65rem;
    border:1.5px solid #e2e8f0;border-radius:8px;font-size:.78rem;cursor:pointer;transition:all .12s;}
.pr2-esc-item:has(input:checked){border-color:#ef4444;background:#fef2f2;}
.pr2-esc-item.esc-negro:has(input:checked){border-color:#1f2937;background:#f1f5f9;}
.pr2-esc-item input{accent-color:#ef4444;}
.pr2-result{border-radius:12px;padding:1rem 1.25rem;border:2px solid;margin:.75rem 0;}
.pr2-result-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
.pr2-result-nivel{font-size:1.2rem;font-weight:700;}
.pr2-result-accion{font-size:.8rem;margin-top:.35rem;line-height:1.5;}
.pr2-score-big{font-size:1.5rem;font-weight:700;text-align:right;}
.pr2-score-sub{font-size:.7rem;font-weight:400;color:#64748b;}
.pr2-dim-scores{display:grid;grid-template-columns:repeat(4,1fr);gap:.4rem;margin:.6rem 0 0;}
.pr2-dim-score{text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.4rem;}
.pr2-dim-score span{display:block;font-size:1rem;font-weight:700;color:#0f172a;}
.pr2-dim-score small{font-size:.68rem;color:#94a3b8;}
.pr2-btn{display:inline-flex;align-items:center;gap:.4rem;border:none;border-radius:8px;
    padding:.65rem 1.25rem;font-size:.86rem;font-weight:600;cursor:pointer;font-family:inherit;
    transition:background .15s;margin-top:.75rem;}
.pr2-btn.primary{background:#1e3a8a;color:#fff;}
.pr2-btn.primary:hover{background:#1e40af;}

.pr2-nota{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
    padding:.75rem 1rem;font-size:.78rem;color:#1e40af;line-height:1.6;margin-top:.75rem;}
.pr2-empty{text-align:center;color:#94a3b8;padding:2rem 1rem;font-size:.88rem;}
@media(max-width:700px){.pr2-escala-grid{grid-template-columns:1fr;}.pr2-dim-scores{grid-template-columns:repeat(2,1fr);}}
</style>

<?php if ($msgOkPauta): ?>
<div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;padding:.7rem 1rem;
    margin-bottom:1rem;font-size:.84rem;color:#047857;display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-check-circle-fill"></i> <?= e($msgOkPauta) ?>
</div>
<?php endif; ?>

<section class="exp-card">
    <p class="pr2-title"><i class="bi bi-clipboard2-pulse-fill"></i> Pauta de Evaluación de Riesgo</p>
    <div class="pr2-nota" style="margin-top:0;margin-bottom:1rem;">
        <i class="bi bi-info-circle-fill"></i>
        <strong>Nota:</strong> La pauta no reemplaza el juicio profesional del encargado de convivencia.
        Es un instrumento orientador que sistematiza la observación y garantiza que ningún factor de riesgo quede sin evaluar.
        El encargado puede ajustar el nivel hacia arriba si su criterio profesional lo indica — esa decisión queda registrada en el historial.
    </div>

    <?php
    // Alertas de riesgo pendientes generadas por pautas anteriores
    $_alertasRiesgo = array_values(array_filter($alertas ?? [], fn($a) =>
        ($a['tipo_alerta'] ?? '') === 'riesgo_victima' && ($a['estado'] ?? '') === 'pendiente'
    ));
    if (!empty($_alertasRiesgo)):
    ?>
    <div style="display:grid;gap:.45rem;margin-bottom:1rem;">
        <?php foreach ($_alertasRiesgo as $_ar): ?>
        <div style="display:flex;align-items:flex-start;gap:.7rem;padding:.7rem 1rem;
                    background:#fef2f2;border:1px solid #fecaca;border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;font-size:1rem;flex-shrink:0;margin-top:.1rem;"></i>
            <div style="min-width:0;">
                <div style="font-size:.82rem;font-weight:700;color:#b91c1c;margin-bottom:.15rem;">
                    <?= e((string)($_ar['titulo'] ?? '')) ?>
                </div>
                <div style="font-size:.76rem;color:#991b1b;line-height:1.5;">
                    <?= e((string)($_ar['descripcion'] ?? '')) ?>
                </div>
                <div style="font-size:.7rem;color:#ef4444;margin-top:.25rem;">
                    <?= $_ar['created_at'] ? date('d/m/Y H:i', strtotime((string)$_ar['created_at'])) : '' ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($intervinientes_vt)): ?>
        <div class="pr2-empty">
            <i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
            No hay víctimas ni testigos en este caso.<br>
            <small>Registra intervinientes con rol Víctima o Testigo para aplicar la pauta.</small>
        </div>
    <?php else: ?>

    <!-- ── Selector de participante ──────────────────────────── -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;
        padding:.85rem 1rem;margin-bottom:1rem;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;
            letter-spacing:.08em;color:#64748b;margin-bottom:.6rem;">
            Seleccionar interviniente
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <?php foreach ($intervinientes_vt as $vtSel):
                $kSel    = (int)($vtSel['persona_id']??0) . '_' . $vtSel['rol_en_caso'];
                $pSel    = $pautasPorKey[$kSel] ?? null;
                $nSel    = $pSel ? (string)$pSel['nivel_final'] : '';
                $iSel    = $NIVELES[$nSel] ?? null;
                $isActive= $kSel === $selectedKey;
            ?>
            <a href="?id=<?= $casoId ?>&tab=pauta_riesgo&sel=<?= urlencode($kSel) ?>"
               style="display:inline-flex;align-items:center;gap:.45rem;padding:.52rem .9rem;
                   border-radius:9px;font-size:.82rem;font-weight:600;text-decoration:none;
                   border:1.5px solid <?= $isActive ? '#2563eb' : '#e2e8f0' ?>;
                   background:<?= $isActive ? '#eff6ff' : '#fff' ?>;
                   color:<?= $isActive ? '#1e40af' : '#334155' ?>;">
                <?php if ($iSel): ?>
                    <span><?= $iSel['emoji'] ?></span>
                <?php else: ?>
                    <i class="bi bi-clipboard2" style="color:#94a3b8;font-size:.8rem;"></i>
                <?php endif; ?>
                <?= e((string)($vtSel['nombre'] ?? 'Sin nombre')) ?>
                <span style="font-size:.7rem;font-weight:500;opacity:.7;">
                    (<?= $vtSel['rol_en_caso']==='victima'?'Víctima':'Testigo' ?>)
                </span>
                <?php if ($pSel && !$pSel['derivado'] && in_array($nSel,['alto','critico'])): ?>
                    <span style="width:7px;height:7px;border-radius:50%;background:#ef4444;flex-shrink:0;"></span>
                <?php elseif (!$pSel): ?>
                    <span style="width:7px;height:7px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // Mostrar solo el participante seleccionado
    foreach ($intervinientes_vt as $vt):
        $alumId  = (int)($vt['persona_id'] ?? 0);
        $nombre  = (string)($vt['nombre'] ?? 'Sin nombre');
        $rol     = (string)($vt['rol_en_caso'] ?? 'victima');
        $key     = $alumId . '_' . $rol;
        if ($key !== $selectedKey) continue;  // ← solo el seleccionado
        $pauta   = $pautasPorKey[$key] ?? null;
        $editMode    = (($_GET['nueva'] ?? '') === $key);
        $esNuevaAplic = $editMode && $pauta;
        $nivelF  = $pauta ? (string)$pauta['nivel_final'] : '';
        $nivelInfo = $NIVELES[$nivelF] ?? $NIVELES['bajo'];
    ?>
    <div class="pr2-card <?= $pauta ? 'nivel-'.$nivelF : '' ?>">
        <div class="pr2-head">
            <div>
                <div class="pr2-name"><?= e($nombre) ?></div>
                <span class="pr2-role <?= e($rol) ?>"><?= $rol==='victima'?'Víctima':'Testigo' ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;">
                <?php if ($pauta): ?>
                    <span class="pr2-badge <?= e($nivelF) ?>">
                        <?= $nivelInfo['emoji'] ?> <?= $nivelInfo['label'] ?>
                        · <?= (int)$pauta['puntaje_total'] ?>/70 pts
                    </span>

                <?php else: ?>
                    <span style="font-size:.78rem;color:#94a3b8;"><i class="bi bi-clipboard2"></i> Sin pauta aplicada</span>
                <?php endif; ?>
            </div>
        </div>

        <?php
            $historialKey = $historialPorKey[$key] ?? [];
            $totalAplic   = count($historialKey);
        ?>
        <?php if ($pauta && !$editMode): ?>  <?php /* Pauta bloqueada — solo lectura */ ?>
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                letter-spacing:.07em;color:#64748b;margin-bottom:.45rem;">
                Evaluación más reciente<?= $totalAplic > 1 ? ' (#'.$totalAplic.')' : ' #1' ?>
            </div>
            <div class="pr2-result" style="background:<?= e($nivelInfo['bg']) ?>;border-color:<?= e($nivelInfo['border']) ?>;">
                <div class="pr2-result-row">
                    <div>
                        <div class="pr2-result-nivel" style="color:<?= e($nivelInfo['color']) ?>;">
                            <?= $nivelInfo['emoji'] ?> Riesgo <?= $nivelInfo['label'] ?>
                        </div>
                        <div class="pr2-result-accion" style="color:<?= e($nivelInfo['color']) ?>;">
                            <?= e($nivelInfo['accion']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="pr2-score-big" style="color:<?= e($nivelInfo['color']) ?>;">
                            <?= (int)$pauta['puntaje_total'] ?><span class="pr2-score-sub">/70</span>
                        </div>
                    </div>
                </div>
                <div class="pr2-dim-scores">
                    <?php foreach([['D1','puntaje_d1',20],['D2','puntaje_d2',20],['D3','puntaje_d3',15],['D4','puntaje_d4',15]] as [$d,$c,$m]): ?>
                    <div class="pr2-dim-score">
                        <span><?= (int)$pauta[$c] ?>/<?= $m ?></span>
                        <small><?= $d ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($pauta['ajuste_profesional']): ?>
                <div style="font-size:.78rem;color:#7c3aed;margin-top:.4rem;">
                    <i class="bi bi-person-check-fill"></i>
                    Nivel ajustado: <?= e((string)($pauta['justificacion_ajuste'] ?? '')) ?>
                </div>
            <?php endif; ?>
            <div style="font-size:.78rem;color:#64748b;margin-top:.45rem;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;">
                <span><?= e((string)($pauta['completada_por_nombre'] ?? 'N/D')) ?> · <?= date('d/m/Y H:i', strtotime((string)$pauta['created_at'])) ?></span>
                <?php if (!empty($pauta['firma_hash'])): ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;background:#ecfdf5;
                    border:1px solid #bbf7d0;border-radius:999px;padding:.15rem .6rem;
                    font-size:.72rem;color:#047857;font-weight:600;"
                    title="Firma electrónica verificada — Ley 19.799&#10;Hash: <?= e((string)$pauta['firma_hash']) ?>&#10;IP: <?= e((string)($pauta['firma_ip']??'')) ?>">
                    <i class="bi bi-shield-fill-check"></i>
                    Firma electrónica · <?= strtoupper(substr((string)$pauta['firma_hash'], 0, 12)) ?>
                </span>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;background:#fff7ed;
                    border:1px solid #fed7aa;border-radius:999px;padding:.15rem .6rem;
                    font-size:.72rem;color:#c2410c;font-weight:600;">
                    <i class="bi bi-shield-exclamation"></i> Sin firma
                </span>
                <?php endif; ?>
                <?php if ($pauta['derivado']): ?>
                    <span style="color:#047857;font-weight:600;">✅ Derivado — <?= e((string)($pauta['entidad_derivacion']??'')) ?></span>
                <?php elseif (in_array($nivelF,['alto','critico'])): ?>
                    <span style="color:#b91c1c;font-weight:600;">⚠ Derivación pendiente</span>
                <?php endif; ?>
            </div>

            <?php if (in_array($nivelF, ['alto','critico']) || !$pauta['derivado']): ?>
            <!-- Formulario de derivación (independiente de la evaluación) -->
            <div style="margin-top:.85rem;background:#fffbeb;border:1px solid #fde68a;
                border-radius:10px;padding:.85rem 1rem;">
                <p style="font-size:.78rem;font-weight:700;color:#92400e;margin:0 0 .65rem;
                    display:flex;align-items:center;gap:.4rem;">
                    <i class="bi bi-send-fill"></i>
                    <?= $pauta['derivado'] ? 'Actualizar derivación' : 'Registrar derivación' ?>
                </p>
                <form method="post">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="_pauta_action" value="guardar_derivacion">
                    <input type="hidden" name="_pauta_id" value="<?= (int)$pauta['id'] ?>">
                    <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:.65rem;align-items:end;">
                        <div>
                            <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Estado</label>
                            <select name="der_derivado"
                                style="border:1px solid #cbd5e1;border-radius:7px;padding:.48rem .7rem;
                                       font-size:.84rem;font-family:inherit;background:#fff;">
                                <option value="0" <?= !$pauta['derivado']?'selected':'' ?>>Pendiente</option>
                                <option value="1" <?= $pauta['derivado']?'selected':'' ?>>Derivado ✅</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Entidad / profesional</label>
                            <input type="text" name="der_entidad"
                                value="<?= e((string)($pauta['entidad_derivacion'] ?? '')) ?>"
                                placeholder="CESFAM, OPD, OLN, Juzgados de Familia..."
                                style="width:100%;border:1px solid #cbd5e1;border-radius:7px;
                                       padding:.48rem .7rem;font-size:.84rem;font-family:inherit;">
                        </div>
                        <div>
                            <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Fecha de derivación</label>
                            <input type="date" name="der_fecha"
                                value="<?= e((string)($pauta['fecha_derivacion'] ?? '')) ?>"
                                style="width:100%;border:1px solid #cbd5e1;border-radius:7px;
                                       padding:.48rem .7rem;font-size:.84rem;font-family:inherit;">
                        </div>
                    </div>
                    <button type="submit"
                        style="display:inline-flex;align-items:center;gap:.35rem;margin-top:.65rem;
                            border:none;background:#92400e;color:#fff;border-radius:7px;
                            padding:.5rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;">
                        <i class="bi bi-check-lg"></i> Guardar derivación
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Botón nueva evaluación -->
            <a href="?id=<?= $casoId ?>&tab=pauta_riesgo&sel=<?= urlencode($key) ?>&nueva=<?= urlencode($key) ?>"
               style="display:inline-flex;align-items:center;gap:.4rem;margin-top:.65rem;
                   background:#f8fafc;color:#334155;border:1px solid #e2e8f0;
                   border-radius:8px;padding:.5rem .9rem;font-size:.8rem;
                   font-weight:600;text-decoration:none;">
                <i class="bi bi-clipboard2-plus-fill" style="color:#2563eb;"></i>
                Aplicar nueva evaluación de riesgo
            </a>

            <!-- Historial colapsable -->
            <?php if ($totalAplic > 1): ?>
            <div style="margin-top:.8rem;">
                <button type="button" onclick="
                    var h=document.getElementById('hist_<?= e($key) ?>');
                    h.style.display=h.style.display==='none'?'block':'none';
                    this.innerHTML=(h.style.display==='none'
                        ?'<i class=\'bi bi-chevron-down\'></i> Ver historial (<?= $totalAplic-1 ?> anterior<?= $totalAplic>2?'es':'' ?>)'
                        :'<i class=\'bi bi-chevron-up\'></i> Ocultar historial');"
                    style="background:none;border:none;cursor:pointer;font-size:.78rem;
                        color:#64748b;font-family:inherit;display:flex;align-items:center;gap:.35rem;padding:0;">
                    <i class="bi bi-chevron-down"></i>
                    Ver historial (<?= $totalAplic - 1 ?> evaluación<?= $totalAplic>2?'es':'' ?> anterior<?= $totalAplic>2?'es':'' ?>)
                </button>
                <div id="hist_<?= e($key) ?>" style="display:none;margin-top:.55rem;display:grid;gap:.35rem;">
                    <?php foreach (array_slice($historialKey, 1) as $hIdx => $h):
                        $hNivel = (string)($h['nivel_final'] ?? 'bajo');
                        $hInfo  = $NIVELES[$hNivel] ?? $NIVELES['bajo'];
                        $hNum   = $totalAplic - 1 - $hIdx;
                    ?>
                    <div style="padding:.6rem .85rem;background:<?= $hInfo['bg'] ?>;
                        border:1px solid <?= $hInfo['border'] ?>;border-radius:8px;font-size:.79rem;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                            <span style="font-weight:600;color:<?= $hInfo['color'] ?>;">
                                <?= $hInfo['emoji'] ?> Evaluación #<?= $hNum ?> — <?= $hInfo['label'] ?> (<?= (int)$h['puntaje_total'] ?>/70)
                            </span>
                            <span style="color:#94a3b8;font-size:.73rem;"><?= date('d/m/Y', strtotime((string)$h['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($h['motivo_reaplicacion'])): ?>
                            <div style="color:#64748b;margin-top:.2rem;">
                                <i class="bi bi-chat-square-text"></i> <?= e((string)$h['motivo_reaplicacion']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($h['derivado']): ?>
                            <div style="color:#047857;margin-top:.15rem;font-size:.76rem;">✅ Derivado — <?= e((string)($h['entidad_derivacion']??'')) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
        <!-- Formulario -->
        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_pauta_action" value="guardar_v2">
            <input type="hidden" name="pauta_rol" value="<?= e($rol) ?>">
            <input type="hidden" name="pauta_alumno_id" value="<?= $alumId ?>">
            <input type="hidden" name="pauta_nombre" value="<?= e($nombre) ?>">

            <?php foreach ($DIMENSIONES as $dim): ?>
            <div class="pr2-dim">
                <div class="pr2-dim-title">
                    <span><i class="bi bi-grid-fill" style="font-size:.72rem;"></i>
                    <?= e($dim['titulo']) ?></span>
                    <span class="pr2-dim-max">Máx <?= $dim['max'] ?> pts</span>
                </div>
                <?php foreach ($dim['items'] as $item): ?>
                <div class="pr2-item">
                    <div class="pr2-item-label"><?= e($item['label']) ?></div>
                    <div class="pr2-item-sub"><?= e($item['sub']) ?></div>
                    <div class="pr2-options">
                        <?php foreach ($item['opts'] as $opt): ?>
                        <label class="pr2-opt">
                            <input type="radio" name="<?= e($item['campo']) ?>"
                                   value="<?= (int)$opt['val'] ?>"
                                   <?= ($pauta && (int)$pauta[$item['campo']]===(int)$opt['val']) ? 'checked' : '' ?>
                                   onchange="prCalc('<?= e($key) ?>')">
                            <?= e($opt['label']) ?>
                            <span style="font-size:.7rem;color:#94a3b8;font-weight:400;">(<?= (int)$opt['val'] ?> pts)</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <!-- Factores de escala automática -->
            <div class="pr2-dim">
                <div class="pr2-dim-title">
                    <span><i class="bi bi-exclamation-triangle-fill" style="color:#ef4444;font-size:.72rem;"></i>
                    Factores de escala automática</span>
                </div>
                <div class="pr2-escala-grid">
                    <?php foreach ($ESCALAS as $esc): ?>
                    <label class="pr2-esc-item <?= $esc['escala_a']==='critico' ? 'esc-negro' : '' ?>">
                        <input type="checkbox" name="<?= e($esc['campo']) ?>" value="1"
                               <?= ($pauta && !empty($pauta[$esc['campo']])) ? 'checked' : '' ?>
                               onchange="prCalc('<?= e($key) ?>')">
                        <span style="font-size:.78rem;font-weight:500;color:#334155;"><?= e($esc['label']) ?></span>
                        <span style="margin-left:auto;font-size:.68rem;font-weight:600;
                            color:<?= $esc['escala_a']==='critico' ? '#0f172a' : ($esc['escala_a']==='alto' ? '#b91c1c' : '#92400e') ?>;">
                            → <?= strtoupper($esc['escala_a']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Resultado en tiempo real -->
            <div id="prResult_<?= e($key) ?>" class="pr2-result"
                 style="background:#ecfdf5;border-color:#bbf7d0;">
                <div class="pr2-result-row">
                    <div>
                        <div class="pr2-result-nivel" id="prNivel_<?= e($key) ?>" style="color:#16a34a;">
                            🟢 Riesgo Bajo
                        </div>
                        <div class="pr2-result-accion" id="prAccion_<?= e($key) ?>" style="color:#16a34a;">
                            Completa los ítems para calcular el nivel
                        </div>
                    </div>
                    <div>
                        <div class="pr2-score-big" id="prScore_<?= e($key) ?>" style="color:#16a34a;">
                            0<span class="pr2-score-sub">/70</span>
                        </div>
                        <div class="pr2-dim-scores" style="margin-top:.3rem;">
                            <div class="pr2-dim-score"><span id="prD1_<?= e($key) ?>">0</span><small>D1/20</small></div>
                            <div class="pr2-dim-score"><span id="prD2_<?= e($key) ?>">0</span><small>D2/20</small></div>
                            <div class="pr2-dim-score"><span id="prD3_<?= e($key) ?>">0</span><small>D3/15</small></div>
                            <div class="pr2-dim-score"><span id="prD4_<?= e($key) ?>">0</span><small>D4/15</small></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ajuste profesional -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem 1rem;margin:.6rem 0;">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem;font-weight:600;color:#334155;">
                    <input type="checkbox" name="ajuste_profesional" value="1" id="adjChk_<?= e($key) ?>"
                           <?= ($pauta && $pauta['ajuste_profesional']) ? 'checked' : '' ?>
                           onchange="document.getElementById('adjBox_<?= e($key) ?>').style.display=this.checked?'block':'none'">
                    <i class="bi bi-person-check-fill" style="color:#7c3aed;"></i>
                    Ajustar nivel por criterio profesional
                </label>
                <div id="adjBox_<?= e($key) ?>" style="display:<?= ($pauta && $pauta['ajuste_profesional']) ? 'block' : 'none' ?>;margin-top:.65rem;">
                    <div style="display:flex;gap:.55rem;margin-bottom:.55rem;flex-wrap:wrap;">
                        <?php foreach ($NIVELES as $nk => $nv): ?>
                        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.8rem;">
                            <input type="radio" name="nivel_ajuste" value="<?= $nk ?>"
                                   <?= ($pauta && $pauta['nivel_final']===$nk && $pauta['ajuste_profesional']) ? 'checked' : '' ?>>
                            <?= $nv['emoji'] ?> <?= $nv['label'] ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <textarea name="justificacion_ajuste" rows="2"
                              style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.5rem .75rem;
                                     font-size:.82rem;font-family:inherit;resize:vertical;"
                              placeholder="Justificación del ajuste profesional..."><?= e((string)($pauta['justificacion_ajuste'] ?? '')) ?></textarea>
                </div>
            </div>

            <!-- Derivación -->
            <div id="pr2DerBox_<?= e($key) ?>" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.85rem 1rem;margin:.5rem 0;">
                <p style="font-size:.82rem;font-weight:700;color:#92400e;margin:0 0 .6rem;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Se requiere derivación
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem;">
                    <div>
                        <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.25rem;">¿Derivado?</label>
                        <select name="pauta_derivado" style="width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:.48rem .7rem;font-size:.84rem;font-family:inherit;">
                            <option value="0" <?= ($pauta['derivado']??0)==0?'selected':'' ?>>No aún</option>
                            <option value="1" <?= ($pauta['derivado']??0)==1?'selected':'' ?>>Sí, derivado</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.25rem;">Fecha</label>
                        <input type="date" name="pauta_fecha_derivacion" style="width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:.48rem .7rem;font-size:.84rem;font-family:inherit;"
                               value="<?= e((string)($pauta['fecha_derivacion'] ?? '')) ?>">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.25rem;">Entidad / profesional</label>
                        <input type="text" name="pauta_entidad_derivacion" placeholder="CESFAM, OPD, OLN, Juzgados de Familia..."
                               style="width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:.48rem .7rem;font-size:.84rem;font-family:inherit;"
                               value="<?= e((string)($pauta['entidad_derivacion'] ?? '')) ?>">
                    </div>
                </div>
            </div>

            <div style="margin-top:.75rem;">
                <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">
                    Observación del encargado de convivencia
                </label>
                <textarea name="pauta_observacion" rows="3"
                          style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .85rem;
                                 font-size:.84rem;font-family:inherit;resize:vertical;"
                          placeholder="Contexto adicional, impresiones del encargado, medidas tomadas..."><?= e((string)($pauta['observacion'] ?? '')) ?></textarea>
            </div>

            <?php if ($esNuevaAplic ?? false): ?>
            <div style="margin-top:.75rem;">
                <label style="font-size:.74rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">
                    <i class="bi bi-chat-square-text" style="color:#2563eb;"></i>
                    Motivo de nueva evaluación <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" name="motivo_reaplicacion"
                       style="width:100%;border:1px solid #cbd5e1;border-radius:8px;
                              padding:.55rem .85rem;font-size:.84rem;font-family:inherit;"
                       placeholder="Ej: Cambio de contexto familiar, retorno del agresor, nueva situación..."
                       required>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem;">
                    Indica qué cambió desde la última evaluación.
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Firma electrónica ──────────────────────────── -->
            <div style="margin-top:1rem;background:#f0fdf4;border:1.5px solid #86efac;
                border-radius:12px;padding:1rem 1.25rem;">
                <p style="font-size:.78rem;font-weight:700;color:#14532d;margin:0 0 .65rem;
                    display:flex;align-items:center;gap:.4rem;">
                    <i class="bi bi-shield-lock-fill" style="color:#16a34a;"></i>
                    Firma electrónica — Ley 19.799
                </p>
                <div style="font-size:.76rem;color:#166534;margin-bottom:.65rem;line-height:1.5;">
                    Al guardar con tu contraseña, estás firmando electrónicamente esta evaluación.
                    Queda registrado tu nombre, la fecha/hora exacta y un código de verificación
                    SHA-256 vinculado a los datos de la pauta.
                </div>
                <label style="font-size:.78rem;font-weight:600;color:#14532d;display:block;margin-bottom:.3rem;">
                    Contraseña de acceso <span style="color:#dc2626;">*</span>
                </label>
                <div style="display:flex;gap:.6rem;align-items:center;">
                    <input type="password" name="firma_contrasena" required
                           autocomplete="current-password"
                           placeholder="Ingresa tu contraseña para firmar"
                           style="flex:1;border:1.5px solid #86efac;border-radius:8px;
                                  padding:.55rem .85rem;font-size:.88rem;font-family:inherit;
                                  outline:none;background:#fff;">
                    <button type="submit" class="pr2-btn primary"
                            style="background:linear-gradient(135deg,#14532d,#16a34a);
                                   white-space:nowrap;margin:0;flex-shrink:0;">
                        <i class="bi bi-shield-check"></i>
                        <?= ($esNuevaAplic ?? false) ? 'Firmar nueva evaluación' : 'Firmar y guardar pauta' ?>
                    </button>
                </div>
            </div>
        </form>

        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>

<script>
(function () {
    var NIVELES = {
        bajo:    { emoji:'🟢', label:'Riesgo Bajo',    color:'#16a34a', bg:'#ecfdf5', border:'#bbf7d0', accion:'Seguimiento regular. Próxima revisión en 15 días.' },
        medio:   { emoji:'🟡', label:'Riesgo Medio',   color:'#92400e', bg:'#fffbeb', border:'#fde68a', accion:'Intervención planificada dentro de 48 horas. Informar a dirección.' },
        alto:    { emoji:'🔴', label:'Riesgo Alto',    color:'#b91c1c', bg:'#fef2f2', border:'#fecaca', accion:'Intervención inmediata. Medidas de resguardo obligatorias. Notificar apoderados hoy.' },
        critico: { emoji:'⚫', label:'Riesgo Crítico',  color:'#0f172a', bg:'#f1f5f9', border:'#334155', accion:'Derivación urgente. Coordinación OLN / Juzgados de Familia. Dirección debe actuar en el día.' }
    };
    var DIMS = {
        d1: ['d1_frecuencia','d1_tipo_violencia','d1_lugar','d1_medios'],
        d2: ['d2_edad','d2_condicion','d2_red_familiar','d2_victimizacion'],
        d3: ['d3_quien_agresor','d3_actitud','d3_antecedentes'],
        d4: ['d4_visibilidad','d4_riesgo_repeticion','d4_familia_agresor','d4_derivacion']
    };
    var ESCALA_ORDEN = {bajo:0,medio:1,alto:2,critico:3};
    var ESCALAS = [
        {campo:'esc_menor_8',a:'alto'},{campo:'esc_agresor_funcionario',a:'alto'},
        {campo:'esc_violencia_sexual',a:'critico'},{campo:'esc_amenazas_armas',a:'alto'},
        {campo:'esc_tea_sin_red',a:'medio'},{campo:'esc_reincidencia',a:'alto'}
    ];

    window.prCalc = function(key) {
        var form = document.querySelector('input[name="pauta_alumno_id"][value="'+key.split('_')[0]+'"]');
        if (!form) return;
        form = form.closest('form');
        if (!form) return;

        var sumD = {};
        Object.keys(DIMS).forEach(function(d) {
            sumD[d] = 0;
            DIMS[d].forEach(function(f) {
                var r = form.querySelector('input[name="'+f+'"]:checked');
                if (r) sumD[d] += parseInt(r.value, 10);
            });
        });
        var total = sumD.d1 + sumD.d2 + sumD.d3 + sumD.d4;

        var nv = total <= 15 ? 'bajo' : total <= 30 ? 'medio' : total <= 45 ? 'alto' : 'critico';
        var actual = ESCALA_ORDEN[nv];
        ESCALAS.forEach(function(e) {
            var cb = form.querySelector('input[name="'+e.campo+'"]');
            if (cb && cb.checked) {
                var min = ESCALA_ORDEN[e.a] || 0;
                if (min > actual) actual = min;
            }
        });
        var nvFinal = Object.keys(ESCALA_ORDEN).find(function(k){ return ESCALA_ORDEN[k]===actual; });
        var info = NIVELES[nvFinal];

        var box   = document.getElementById('prResult_'+key);
        var nivel = document.getElementById('prNivel_'+key);
        var accion= document.getElementById('prAccion_'+key);
        var score = document.getElementById('prScore_'+key);
        var deriv = document.getElementById('pr2DerBox_'+key);
        if (!box) return;

        box.style.background = info.bg;
        box.style.borderColor = info.border;
        nivel.textContent = info.emoji + ' ' + info.label;
        nivel.style.color = info.color;
        accion.textContent = info.accion;
        accion.style.color = info.color;
        score.childNodes[0].textContent = total;
        score.style.color = info.color;

        ['d1','d2','d3','d4'].forEach(function(d) {
            var el = document.getElementById('pr'+d.toUpperCase()+'_'+key);
            if (el) el.textContent = sumD[d];
        });
        if (deriv) deriv.style.display = (nvFinal !== 'bajo') ? 'block' : 'none';
    };

    document.addEventListener('change', function(e) {
        if (e.target.matches('input[type="radio"], input[type="checkbox"]')) {
            var form = e.target.closest('form');
            if (!form) return;
            var hidId  = form.querySelector('input[name="pauta_alumno_id"]');
            var hidRol = form.querySelector('input[name="pauta_rol"]');
            if (hidId && hidRol) prCalc(hidId.value + '_' + hidRol.value);
        }
    });
})();
</script>
