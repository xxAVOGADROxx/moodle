<?php
/**
 * Coloca la actividad del Simulador (LTI) dentro del Módulo K del curso de
 * Instrucción UAS, que es donde el MIP la sitúa (fase práctica presencial).
 *
 *   docker compose exec -T php php /var/www/moodle/local/broncano/cli/add_sim_to_moduloK.php
 *
 * Idempotente: si ya existe una actividad LTI en el Módulo K, no crea otra.
 *
 * Por qué importa el curso: hoy el simulador cuelga del curso 6, así que al
 * lanzarlo manda courseid=6 en el token LTI. Pero a los alumnos los matriculamos
 * en el curso 5, y el academy-service acredita las horas por (userid, courseid).
 * Con courseid=6 esas horas NO cuadran con la matrícula del 5 y se pierden. Al
 * poner la actividad en el curso 5, el token trae courseid=5 y todo encaja.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

function say($m) { fwrite(STDERR, "   $m\n"); }

global $DB, $CFG;

// add_moduleinfo comprueba permisos y escribe en el log, así que necesita un
// usuario con sesión. En un CLI no lo hay: nos identificamos como admin.
$admin = get_admin();
if (!$admin) {
    fwrite(STDERR, "ERROR: no hay cuenta de administrador\n");
    exit(1);
}
\core\session\manager::set_user($admin);

$COURSE_ID   = (int) (getenv('MOODLE_COURSE_ID') ?: 5);
$SECTION_NUM = (int) (getenv('MOODLE_MODULO_K_SECTION') ?: 11);
$TOOL_NAME   = 'Simulador de Drone'; // herramienta ya registrada en mdl_lti_types

$course = $DB->get_record('course', ['id' => $COURSE_ID], '*', MUST_EXIST);
$tool   = $DB->get_record('lti_types', ['name' => $TOOL_NAME]);
if (!$tool) {
    fwrite(STDERR, "ERROR: no existe la herramienta LTI '{$TOOL_NAME}'\n");
    exit(1);
}
say("curso {$COURSE_ID} · sección {$SECTION_NUM} · herramienta '{$TOOL_NAME}' (typeid={$tool->id})");

// ── Idempotencia: ¿ya hay una actividad LTI en la sección Módulo K? ──────────
$ltimodule = $DB->get_field('modules', 'id', ['name' => 'lti']);
$section = $DB->get_record('course_sections', ['course' => $COURSE_ID, 'section' => $SECTION_NUM], '*', MUST_EXIST);

$existing = $DB->get_records_sql(
    "SELECT cm.id FROM {course_modules} cm
      WHERE cm.course = ? AND cm.module = ? AND cm.section = ?",
    [$COURSE_ID, $ltimodule, $section->id]
);
if ($existing) {
    say('Ya existe una actividad LTI en el Módulo K — no se crea otra.');
    $cmid = array_key_first($existing);
    echo $cmid . "\n";
    exit(0);
}

// ── Crear la actividad ──────────────────────────────────────────────────────
$moduleinfo = new stdClass();
$moduleinfo->modulename   = 'lti';
$moduleinfo->module       = $ltimodule;
$moduleinfo->course       = $COURSE_ID;
$moduleinfo->section      = $SECTION_NUM;
$moduleinfo->visible      = 1;
$moduleinfo->name         = 'Simulador de Vuelo (Módulo K)';
$moduleinfo->introeditor  = ['text' => 'Práctica en el simulador de vuelo.', 'format' => FORMAT_HTML, 'itemid' => 0];
$moduleinfo->showdescription = 0;

// Usar la herramienta preconfigurada; hereda URL, clave y secreto de mdl_lti_types.
$moduleinfo->typeid              = $tool->id;
$moduleinfo->toolurl            = '';
$moduleinfo->launchcontainer    = LTI_LAUNCH_CONTAINER_WINDOW; // ventana nueva
$moduleinfo->instructorchoicesendname          = LTI_SETTING_ALWAYS;
$moduleinfo->instructorchoicesendemailaddr     = LTI_SETTING_ALWAYS;
$moduleinfo->instructorchoiceacceptgrades      = LTI_SETTING_NEVER; // el simulador no envía nota a Moodle
$moduleinfo->instructorchoiceallowroster       = LTI_SETTING_NEVER;

$result = add_moduleinfo($moduleinfo, $course);

say("Actividad creada: cmid={$result->coursemodule}");
say('Recuerda: retira la actividad del curso 6 para que nadie lance el simulador con courseid=6.');

echo $result->coursemodule . "\n";
