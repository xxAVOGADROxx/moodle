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
// La descripción (intro) se REESCRIBE en cada ejecución, así que este mismo script
// sirve para mejorar los textos sin duplicar recursos.
const ENLACES = [
    [
        'nombre' => 'Protocolo de Seguridad Operacional',
        'url'    => 'https://broncano.io/bitacora/dashboard/#/nc/form/9fda6b16-1401-45eb-8b42-84d7d527ccb1',
        'intro'  =>
            '<p><strong>¿Qué es?</strong> El formulario oficial para reportar cualquier suceso de '
            . 'seguridad operacional (SMS): un incidente, un accidente, una casi-colisión o una '
            . 'condición insegura que observes durante la operación.</p>'
            . '<p><strong>¿Cuándo lo uso?</strong> Siempre que ocurra —o esté a punto de ocurrir— '
            . 'algo que afecte la seguridad. Reportar no es delatar: es <em>Cultura Justa</em>. '
            . 'El reporte sirve para que todos aprendamos, nunca para castigar.</p>'
            . '<p>💡 Los eventos con daño, colisión o pérdida deben notificarse además a la DGAC '
            . '(NSSP) dentro de las <strong>24 horas</strong>.</p>',
    ],
    [
        'nombre' => 'Encuesta a Estudiante',
        'url'    => 'https://broncano.io/bitacora/dashboard/#/nc/form/6bfa9fc6-263b-4f15-ab42-3a8f187c26ff',
        'intro'  =>
            '<p><strong>¿Qué es?</strong> Una breve encuesta de satisfacción sobre el curso. '
            . 'Te toma menos de dos minutos.</p>'
            . '<p><strong>¿Para qué?</strong> Tu opinión —qué te sirvió, qué mejorarías— nos ayuda '
            . 'a mejorar la instrucción para las próximas promociones. Complétala al finalizar '
            . 'el curso. ¡Gracias por volar con Broncano UAS!</p>',
    ],
    [
        'nombre' => 'Cita para el Examen Presencial (DGAC)',
        'url'    => 'https://www.sipa.aviacioncivil.gob.ec/',
        'intro'  =>
            '<p><strong>¿Qué es?</strong> El portal oficial de la Dirección General de Aviación '
            . 'Civil (DGAC). Desde aquí agendas tu <strong>cita para rendir el examen presencial</strong> '
            . 'ante la autoridad aeronáutica.</p>'
            . '<p><strong>¿Cuándo?</strong> Una vez completada la formación del curso, reserva tu '
            . 'cita en el sistema de la DGAC para presentar el examen oficial.</p>'
            . '<p>⚠️ Es un sitio <strong>externo de la DGAC</strong>, ajeno a Broncano. Ten a mano '
            . 'tus datos personales al momento de agendar.</p>',
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

/** El recurso URL del curso que apunta a esta dirección, o null si no existe. */
function buscar_url(int $courseid, string $url) {
    global $DB;
    $sql = "SELECT u.id, u.name, u.intro
              FROM {url} u
              JOIN {course_modules} cm ON cm.instance = u.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'url'
             WHERE u.course = :course AND u.externalurl = :url";
    return $DB->get_record_sql($sql, ['course' => $courseid, 'url' => $url]) ?: null;
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
            $r = buscar_url($cid, $e['url']);
            $dice = $r ? ($r->intro === $e['intro'] ? '✅ está, descripción al día' : '✏️ está, actualizaré la descripción') : '— falta';
            say(sprintf('   %-38s %s', $e['nombre'], $dice));
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
$actualizados = 0;
foreach (CURSOS as $cid) {
    $curso = $DB->get_record('course', ['id' => $cid]);
    if (!$curso) {
        say("⚠️  curso {$cid}: no existe, lo salto");
        continue;
    }
    say("── curso {$cid} · {$curso->shortname}" . ($dry ? '   [SIMULACRO]' : ''));

    foreach (ENLACES as $e) {
        // Ya existe → sólo se refresca la descripción si cambió. No se duplica.
        $r = buscar_url($cid, $e['url']);
        if ($r) {
            if ($r->intro === $e['intro'] && $r->name === $e['nombre']) {
                say("   ✓ ya está y al día: {$e['nombre']}");
                continue;
            }
            say("   ✏️  actualizo la descripción: {$e['nombre']}");
            if (!$dry) {
                $DB->update_record('url', (object) [
                    'id' => $r->id,
                    'name' => $e['nombre'],
                    'intro' => $e['intro'],
                    'introformat' => FORMAT_HTML,
                    'timemodified' => time(),
                ]);
            }
            $actualizados++;
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
    say("[SIMULACRO] No he escrito nada. Se añadirían {$creados} y se actualizarían {$actualizados}.");
} else {
    say("✅ Listo. {$creados} enlace(s) añadido(s) y {$actualizados} descripción(es) actualizada(s).");
}
exit(0);
