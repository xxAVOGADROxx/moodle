<?php
/**
 * Añade a los cursos los enlaces a los formularios institucionales (NocoDB):
 * el Protocolo de Seguridad Operacional y la Encuesta a Estudiante.
 *
 *   php local/broncano/cli/agregar_enlaces.php estado
 *   php local/broncano/cli/agregar_enlaces.php agregar --dry
 *   php local/broncano/cli/agregar_enlaces.php agregar
 *
 * Se añaden como recursos de tipo URL, en la sección superior de cada curso, y se
 * abren en una pestaña nueva (son sitios externos). Se aplican al curso real (5) y
 * a la copia de pruebas (7).
 *
 * Idempotente: se reconoce cada enlace por su URL, así que ejecutarlo dos veces no
 * duplica el recurso.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/lib/resourcelib.php');

// add_moduleinfo comprueba permisos de gestión del curso; en CLI no hay sesión.
\core\session\manager::set_user(get_admin());

// Los cursos a los que se añaden: real y copia de pruebas.
const CURSOS = [5, 7];

// Los enlaces. La clave es la URL, que es lo que hace idempotente el script.
const ENLACES = [
    [
        'nombre' => 'Protocolo de Seguridad Operacional',
        'url'    => 'https://broncano.io/bitacora/dashboard/#/nc/form/9fda6b16-1401-45eb-8b42-84d7d527ccb1',
        'intro'  => 'Formulario para el reporte de sucesos de seguridad operacional (SMS). '
                  . 'Ábrelo para registrar cualquier incidente, accidente o condición insegura.',
    ],
    [
        'nombre' => 'Encuesta a Estudiante',
        'url'    => 'https://broncano.io/bitacora/dashboard/#/nc/form/6bfa9fc6-263b-4f15-ab42-3a8f187c26ff',
        'intro'  => 'Encuesta de satisfacción del curso. Tu opinión nos ayuda a mejorar la instrucción.',
    ],
];

const SECCION = 0;   // sección superior (General), para que se vean de inmediato

$accion = $argv[1] ?? 'estado';
$dry = in_array('--dry', $argv, true);

function say($m) {
    fwrite(STDOUT, "$m\n");
}
function morir($m) {
    fwrite(STDERR, "\n⛔ $m\n");
    exit(1);
}

global $DB;

$moduloUrl = $DB->get_field('modules', 'id', ['name' => 'url']);
if (!$moduloUrl) {
    morir('El módulo "url" no está instalado en esta Moodle.');
}

/** ¿Ya existe en el curso un recurso URL que apunte a esta dirección? */
function ya_existe(int $courseid, string $url): bool {
    global $DB;
    $sql = "SELECT u.id
              FROM {url} u
              JOIN {course_modules} cm ON cm.instance = u.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'url'
             WHERE u.course = :course AND u.externalurl = :url";
    return $DB->record_exists_sql($sql, ['course' => $courseid, 'url' => $url]);
}

// ── estado ──────────────────────────────────────────────────────────────────
if ($accion === 'estado') {
    foreach (CURSOS as $cid) {
        $curso = $DB->get_record('course', ['id' => $cid], 'id, shortname');
        if (!$curso) {
            say("curso {$cid}: no existe");
            continue;
        }
        say("── curso {$cid} · {$curso->shortname}");
        foreach (ENLACES as $e) {
            say(sprintf('   %s %s', ya_existe($cid, $e['url']) ? '✅ ya está:' : '— falta:  ', $e['nombre']));
        }
    }
    exit(0);
}

if ($accion !== 'agregar') {
    say('Uso: php agregar_enlaces.php [estado|agregar] [--dry]');
    exit(1);
}

// ── agregar ─────────────────────────────────────────────────────────────────
$creados = 0;
foreach (CURSOS as $cid) {
    $curso = $DB->get_record('course', ['id' => $cid]);
    if (!$curso) {
        say("⚠️  curso {$cid}: no existe, lo salto");
        continue;
    }
    say("── curso {$cid} · {$curso->shortname}" . ($dry ? '   [SIMULACRO]' : ''));

    foreach (ENLACES as $e) {
        if (ya_existe($cid, $e['url'])) {
            say("   ✓ ya está: {$e['nombre']}");
            continue;
        }
        say("   → añado: {$e['nombre']}");
        if ($dry) {
            $creados++;
            continue;
        }

        $mi = new stdClass();
        $mi->modulename          = 'url';
        $mi->module              = $moduloUrl;
        $mi->course              = $cid;
        $mi->section             = SECCION;
        $mi->name                = $e['nombre'];
        $mi->externalurl         = $e['url'];
        $mi->intro               = $e['intro'];
        $mi->introformat         = FORMAT_HTML;
        $mi->display             = RESOURCELIB_DISPLAY_NEW;  // pestaña nueva (sitio externo)
        $mi->printintro          = 1;
        $mi->visible             = 1;
        $mi->visibleoncoursepage = 1;
        $mi->cmidnumber          = '';
        // Sin seguimiento de finalización: es un enlace, no una actividad evaluable.
        $mi->completion          = COMPLETION_TRACKING_NONE;
        $mi->completionexpected  = 0;

        try {
            add_moduleinfo($mi, $curso);
            $creados++;
            say('     ✅ añadido (abre en pestaña nueva)');
        } catch (Exception $ex) {
            say('     ⛔ ' . $ex->getMessage());
        }
    }
    if (!$dry) {
        rebuild_course_cache($cid, true);
    }
}

say('');
if ($dry) {
    say("[SIMULACRO] No he escrito nada. Se añadirían {$creados} recurso(s).");
} else {
    say("✅ Listo. {$creados} enlace(s) añadido(s). Aparecen arriba en cada curso.");
}
exit(0);
