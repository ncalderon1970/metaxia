<?php
declare(strict_types=1);
/**
 * Metis · Denuncias › Cargador de queries del expediente
 *
 * Archivo deliberadamente mínimo. La lógica de datos vive en
 * modules/denuncias/queries/*.php para mantener el expediente modular.
 */

require_once __DIR__ . '/../queries/caso.php';
require_once __DIR__ . '/../queries/clasificacion.php';
require_once __DIR__ . '/../queries/aula_segura.php';
require_once __DIR__ . '/../queries/cierre.php';
require_once __DIR__ . '/../queries/participantes.php';
require_once __DIR__ . '/../queries/declaraciones.php';
require_once __DIR__ . '/../queries/evidencias.php';
require_once __DIR__ . '/../queries/indicadores.php';
require_once __DIR__ . '/../queries/seguimiento.php';
require_once __DIR__ . '/../queries/contexto.php';
