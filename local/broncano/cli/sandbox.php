<?php
/**
 * Crea (o rehace) el CURSO DE PRUEBAS: una copia del curso real con los exámenes
 * siempre abiertos, a la que sólo se entra si alguien te matricula.
 *
 *   php local/broncano/cli/sandbox.php estado
 *   php local/broncano/cli/sandbox.php crear
 *   php local/broncano/cli/sandbox.php rehacer    → lo borra y lo vuelve a copiar
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 *
 * Hasta hoy, ensayar el ciclo completo (rendir un examen → nota → GRADUADO →
 * certificado) obligaba a ABRIR LOS EXÁMENES DEL CURSO REAL con exams_window.php,
 * y acordarse de cerrarlos. Eso es una red de seguridad, no un plan: el día que
 * se olvide, los alumnos se encuentran el examen final abierto antes de tiempo.
 *
 * Con una copia, el curso bueno no se toca NUNCA. Se ensaya aquí, con exámenes
 * permanentemente abiertos, y no hay nada que restaurar ni de qué acordarse.
 *
 * ── Por qué VISIBLE (y por qué antes estaba oculto, que era un error) ───────
 *
 * Lo creé oculto, razonando que así ningún alumno entraría por descuido. El
 * razonamiento era plausible y estaba mal, de dos maneras:
 *
 *   1. En un curso oculto NO SE PUEDE MATRICULAR a nadie por Web Service:
 *      `enrol_manual_enrol_users` falla con `requireloginerror`. El puente creaba
 *      la cuenta del alumno y la matrícula reventaba.
 *   2. Y aunque se matriculara, un curso oculto NO LO VEN los alumnos. Sólo lo ven
 *      profesores y administradores. En «Mis cursos» no aparece.
 *
 * O sea que ocultarlo no lo protegía: lo dejaba inservible justo para aquello que
 * lo justifica, que es probar con un alumno de verdad.
 *
 * Lo que de verdad protege un curso no es la visibilidad, sino la MATRÍCULA. Aquí
 * —igual que en el curso real— la autoinscripción y el acceso de invitados están
 * desactivados: sólo hay matrícula manual. Así que un curso visible aparece en el
 * catálogo y nada más; para entrar hay que estar matriculado, y matricular es un
 * acto deliberado. El nombre («no es el curso real») hace el resto.
 *
 * ── Y en NocoDB ─────────────────────────────────────────────────────────────
 *
 * Al terminar, añade una fila a la tabla `Cursos` (curso → moodle_course_id), que
 * es de donde el academy-service saca en qué curso matricular. Sin esa fila, el
 * puente NO matricula a nadie aquí — y hace bien: nunca adivina un curso.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// En CLI no hay sesión, y duplicar un curso exige permisos de copia de seguridad,
// restauración y creación. Sin esto, Moodle deniega sin más.
\core\session\manager::set_user(get_admin());

const NOMBRE_SANDBOX = 'RDAC 101 — PRUEBAS (no es el curso real)';
const CORTO_SANDBOX = 'UAS-PRUEBAS';

$origen = (int) (getenv('MOODLE_COURSE_ID') ?: 5);
$accion = $argv[1] ?? 'estado';

function say($m) {
    fwrite(STDOUT, "$m\n");
}
function morir($m) {
    fwrite(STDERR, "\n⛔ $m\n");
    exit(1);
}

global $DB;

$curso = $DB->get_record('course', ['id' => $origen], 'id, fullname, category');
if (!$curso) {
    morir("No existe el curso {$origen}.");
}
$sandbox = $DB->get_record('course', ['shortname' => CORTO_SANDBOX]);

// ── estado ──────────────────────────────────────────────────────────────────
if ($accion === 'estado') {
    say("Curso real:     {$curso->id} · {$curso->fullname}");
    if (!$sandbox) {
        say('Curso pruebas:  — no existe.  Créalo con:  php sandbox.php crear');
        exit(0);
    }
    $quizzes = $DB->get_records('quiz', ['course' => $sandbox->id], 'id', 'id, name, timeopen, timeclose');
    $cerrados = 0;
    foreach ($quizzes as $q) {
        if ($q->timeopen || $q->timeclose) {
            $cerrados++;
        }
    }
    $abierto = $DB->record_exists_select('enrol',
        "courseid = ? AND enrol IN ('self','guest') AND status = 0", [$sandbox->id]);
    say("Curso pruebas:  {$sandbox->id} · {$sandbox->fullname}");
    say('                visible: ' . ($sandbox->visible
        ? '✅ sí (hace falta: oculto no se puede matricular ni ver)'
        : '⚠️  NO — así no se puede matricular a nadie ni lo ve el alumno'));
    say('                entrada: ' . ($abierto
        ? '⚠️  AUTOINSCRIPCIÓN ABIERTA — cualquiera puede entrar'
        : '✅ sólo matrícula manual'));
    say('                exámenes: ' . count($quizzes) . ' · ' .
        ($cerrados ? "⚠️  {$cerrados} con fecha (deberían estar todos abiertos)" : '✅ todos abiertos'));
    say('');
    say('En NocoDB, tabla Cursos, debe existir:  ' . CORTO_SANDBOX . " → {$sandbox->id}");
    exit(0);
}

if ($accion === 'rehacer' && $sandbox) {
    say("Borro el curso de pruebas {$sandbox->id}…");
    delete_course($sandbox->id, false);
    say('   ✅ borrado');
    $sandbox = false;
} else if ($accion !== 'crear' && $accion !== 'rehacer') {
    say('Uso: php sandbox.php [estado|crear|rehacer]');
    exit(1);
}

if ($sandbox) {
    morir("Ya existe el curso de pruebas ({$sandbox->id}). Usa `rehacer` si quieres una copia limpia.");
}

// ── Copiar ──────────────────────────────────────────────────────────────────
//
// Sin usuarios ni notas: se copia el CONTENIDO (exámenes, banco de preguntas,
// materiales), no la gente ni sus intentos. Un sandbox con las notas reales
// dentro sería una filtración, no una prueba.
say("Copiando «{$curso->fullname}» → «" . NOMBRE_SANDBOX . '»…');
say('   (sin alumnos, sin notas, sin intentos)');

$res = \core_course_external::duplicate_course(
    $curso->id,
    NOMBRE_SANDBOX,
    CORTO_SANDBOX,
    $curso->category,
    1,                          // visible: si se oculta, ni se matricula ni se ve
    [
        ['name' => 'activities', 'value' => 1],
        ['name' => 'blocks', 'value' => 1],
        ['name' => 'filters', 'value' => 1],
        ['name' => 'users', 'value' => 0],
        ['name' => 'role_assignments', 'value' => 0],
        ['name' => 'comments', 'value' => 0],
        ['name' => 'userscompletion', 'value' => 0],
        ['name' => 'logs', 'value' => 0],
        ['name' => 'grade_histories', 'value' => 0],
    ]
);
$nuevoid = (int) $res['id'];
say("   ✅ curso {$nuevoid} creado");

// Lo que protege este curso no es estar oculto —eso sólo lo rompía—, sino que a
// él no se pueda entrar sin que alguien te matricule a mano. Se comprueba, en vez
// de darlo por hecho: si el curso original tuviera la autoinscripción abierta, la
// copia la heredaría y cualquiera podría entrar a un curso con los exámenes
// permanentemente abiertos.
$DB->set_field('course', 'visible', 1, ['id' => $nuevoid]);
$DB->set_field('course', 'visibleold', 1, ['id' => $nuevoid]);
foreach ($DB->get_records('enrol', ['courseid' => $nuevoid], 'id', 'id, enrol, status') as $e) {
    if (in_array($e->enrol, ['self', 'guest'], true) && (int) $e->status === 0) {
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $e->id]);
        say("   🔒 desactivo la inscripción «{$e->enrol}»: aquí no entra nadie por su cuenta");
    }
}

// ── Exámenes siempre abiertos ───────────────────────────────────────────────
$quizzes = $DB->get_records('quiz', ['course' => $nuevoid], 'id', 'id, name');
say('');
say('Abriendo los exámenes de la copia (para siempre):');
foreach ($quizzes as $q) {
    $DB->set_field('quiz', 'timeopen', 0, ['id' => $q->id]);
    $DB->set_field('quiz', 'timeclose', 0, ['id' => $q->id]);
    say("   🔓 {$q->name}");
}
rebuild_course_cache($nuevoid, true);

say('');
say('✅ Curso de pruebas listo.');
say("   id {$nuevoid} · oculto · " . count($quizzes) . ' exámenes siempre abiertos');
say('');
say('Falta UNA cosa, y es a mano — en NocoDB, tabla Cursos, añade la fila:');
say('');
say('     curso              moodle_course_id     activo');
say('     ' . CORTO_SANDBOX . "        {$nuevoid}                    ✔");
say('');
say('Sin esa fila el academy-service NO matricula aquí a nadie, y hace bien:');
say('nunca adivina un curso. Con ella, un alumno con curso = ' . CORTO_SANDBOX);
say('entra al sandbox y NO al curso real.');
exit(0);
