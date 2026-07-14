<?php
/**
 * Arma el EXAMEN FINAL DE SUFICIENCIA como manda el MIP: 30 preguntas, tres al
 * azar de cada uno de los diez módulos teóricos (A–J).
 *
 *   php local/broncano/cli/examen_final.php estado          → qué hay ahora
 *   php local/broncano/cli/examen_final.php armar --dry     → qué haría
 *   php local/broncano/cli/examen_final.php armar           → lo hace
 *
 * ── Por qué preguntas ALEATORIAS y no una lista fija ────────────────────────
 *
 * El banco tiene 36 preguntas por módulo — tres veces las 12 que se extraen en
 * la prueba de módulo. Eso es deliberado: un banco del tamaño del examen no es un
 * banco, porque permite memorizar el examen en vez de estudiar la materia.
 *
 * El final hereda esa misma idea. No se escriben 30 preguntas nuevas: se le pide
 * a Moodle que saque TRES AL AZAR de cada categoría en cada intento. Dos alumnos
 * sentados uno al lado del otro no ven el mismo examen, y el examen de junio no es
 * el de julio. Con 360 preguntas en el banco, las combinaciones no se agotan.
 *
 * MIP, Cap. 6:  Examen de Suficiencia Final = 30 % de la nota teórica,
 *               30 preguntas integradoras sobre los módulos A–J, presencial.
 *
 * ── Lo que este script NO hace ─────────────────────────────────────────────
 *
 * NO borra intentos. Moodle prohíbe cambiar la estructura de un cuestionario que
 * ya tiene intentos —y con razón: reescribirías el examen bajo los pies de quien
 * ya lo rindió, y su nota pasaría a referirse a preguntas que nunca vio. Si hay
 * intentos, este script se detiene y te los enseña para que decidas tú.
 *
 * Idempotente: si el examen ya está armado con estas 30, no toca nada.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use core_question\local\bank\condition;
use mod_quiz\quiz_settings;
use mod_quiz\structure;

// Los diez módulos teóricos, en el orden del sílabo (MIP Cap. 5). El nombre es el
// de la categoría del banco, tal y como la crean los ficheros GIFT.
const MODULOS = [
    'Modulo_A_Conocimiento_General',
    'Modulo_B_Principios_de_Vuelo',
    'Modulo_C_Operaciones_de_Vuelo',
    'Modulo_D_Meteorologia',
    'Modulo_E_Derecho_Aeronautico',
    'Modulo_F_Factores_Humanos',
    'Modulo_G_Navegacion_Aerea',
    'Modulo_H_Servicios_de_Transito_Aereo',
    'Modulo_I_Radiotelefonia_Aeronautica',
    'Modulo_J_SMS',
];

const POR_MODULO = 3;                       // 3 × 10 módulos = 30 preguntas
const TOTAL = POR_MODULO * 10;
const NOMBRE_EXAMEN = 'EXAMEN FINAL';       // se busca por prefijo, sin depender de tildes

$curso = (int) (getenv('MOODLE_COURSE_ID') ?: 5);
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

// ── El examen final ─────────────────────────────────────────────────────────
$examen = null;
foreach ($DB->get_records('quiz', ['course' => $curso], 'id', 'id, name, grade, sumgrades') as $q) {
    if (stripos($q->name, NOMBRE_EXAMEN) === 0) {
        $examen = $q;
        break;
    }
}
if (!$examen) {
    morir("No encuentro ningún cuestionario que empiece por \"" . NOMBRE_EXAMEN . "\" en el curso {$curso}.");
}

// ── Las categorías del banco, y si el examen puede ALCANZARLAS ──────────────
//
// Esto es lo que de verdad hay que comprobar. Un cuestionario sólo ve las
// categorías de su propio contexto y de los que lo contienen (curso, categoría de
// curso, sistema). Si las preguntas se importaron DENTRO de cada cuestionario de
// módulo —su contexto de módulo—, el examen final no las ve, y armarlo fallaría
// o, peor, saldría vacío sin decir nada.
$cmexamen = get_coursemodule_from_instance('quiz', $examen->id, $curso, false, MUST_EXIST);
$ctxexamen = context_module::instance($cmexamen->id);
$alcanzables = [];                          // contextid => nombre legible
foreach ($ctxexamen->get_parent_context_ids(true) as $ctxid) {
    $alcanzables[$ctxid] = true;
}

$categorias = [];                           // nombre => registro + nº de preguntas + ¿alcanzable?
foreach ($DB->get_records('question_categories', null, '', 'id, name, contextid') as $c) {
    if (!in_array($c->name, MODULOS, true)) {
        continue;
    }
    $c->preguntas = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {question_bank_entries} qbe
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
          WHERE qbe.questioncategoryid = ? AND qv.status <> 'draft'",
        [$c->id]
    );
    $c->alcanzable = isset($alcanzables[$c->contextid]);
    $c->contexto = context::instance_by_id($c->contextid, IGNORE_MISSING);
    $categorias[$c->name] = $c;
}

// ── estado ──────────────────────────────────────────────────────────────────
if ($accion === 'estado') {
    $slots = $DB->count_records('quiz_slots', ['quizid' => $examen->id]);
    say("Curso {$curso} — \"{$examen->name}\"  (quiz {$examen->id})");
    say("  preguntas ahora: {$slots}   ·   nota máxima: {$examen->grade}   ·   suma: {$examen->sumgrades}");
    say('  el MIP exige: ' . TOTAL . ' preguntas (3 por módulo)');
    say('');
    say('Banco de preguntas:');
    say(str_repeat('─', 78));
    say(sprintf('  %-38s %-9s %-8s %s', 'CATEGORÍA', 'PREGUNTAS', '¿ALCANZA?', 'CONTEXTO'));
    $listas = 0;
    foreach (MODULOS as $nombre) {
        $c = $categorias[$nombre] ?? null;
        if (!$c) {
            say(sprintf('  %-38s %-9s %-8s %s', substr($nombre, 0, 36), '—', '❌', 'NO EXISTE — importa el .gift'));
            continue;
        }
        $ok = $c->alcanzable && $c->preguntas >= POR_MODULO;
        $listas += $ok ? 1 : 0;
        say(sprintf('  %-38s %-9d %-8s %s',
            substr($nombre, 0, 36),
            $c->preguntas,
            $c->alcanzable ? '✅' : '⛔ NO',
            $c->contexto ? $c->contexto->get_context_name(false) : "contexto {$c->contextid}"));
    }
    say(str_repeat('─', 78));
    say("  {$listas} de 10 categorías listas para el examen final.");

    if ($listas < 10) {
        say('');
        say('⛔ Las categorías marcadas con "NO" están en un contexto que el examen final');
        say('   NO puede ver (probablemente dentro del cuestionario de su módulo). Hay que');
        say('   moverlas al banco del CURSO antes de armar el final:');
        say('   Banco de preguntas → Categorías → mover a "' . $ctxexamen->get_course_context()->get_context_name(false) . '"');
    }

    if (quiz_has_attempts($examen->id)) {
        $n = $DB->count_records_select('quiz_attempts', 'quiz = ? AND preview = 0', [$examen->id]);
        say('');
        say("⚠️  El examen ya tiene {$n} intento(s) reales. Moodle no deja cambiar la");
        say('   estructura de un examen con intentos. Hay que borrarlos (son de prueba)');
        say('   o armar el final en el curso sandbox.');
    }
    exit(0);
}

if ($accion !== 'armar') {
    say('Uso: php examen_final.php [estado|armar] [--dry]');
    exit(1);
}

// ── armar ───────────────────────────────────────────────────────────────────

// 1. Nada de reescribir un examen bajo los pies de quien ya lo rindió.
if (quiz_has_attempts($examen->id)) {
    $n = $DB->count_records_select('quiz_attempts', 'quiz = ? AND preview = 0', [$examen->id]);
    morir("El examen tiene {$n} intento(s). Moodle no permite cambiar su estructura, y con\n"
        . "   razón: las notas ya dadas se referirían a preguntas que nadie vio.\n"
        . "   Bórralos a mano si son de prueba, o arma el final en el curso sandbox.");
}

// 2. Las diez categorías tienen que existir, tener preguntas de sobra y ser
//    ALCANZABLES. Si no, se para aquí y no a medio armar.
$problemas = [];
foreach (MODULOS as $nombre) {
    $c = $categorias[$nombre] ?? null;
    if (!$c) {
        $problemas[] = "«{$nombre}» no existe — falta importar Modulo_X_banco.gift";
    } else if (!$c->alcanzable) {
        $nom = $c->contexto ? $c->contexto->get_context_name(false) : "contexto {$c->contextid}";
        $problemas[] = "«{$nombre}» está en «{$nom}», que el examen final NO puede ver";
    } else if ($c->preguntas < POR_MODULO) {
        $problemas[] = "«{$nombre}» sólo tiene {$c->preguntas} pregunta(s); hacen falta " . POR_MODULO;
    }
}
if ($problemas) {
    say('No puedo armar el examen final:');
    foreach ($problemas as $p) {
        say("   · {$p}");
    }
    morir('Arregla el banco y vuelve a intentarlo. No he tocado nada.');
}

$settings = quiz_settings::create($examen->id);
$estructura = structure::create_for_quiz($settings);

// 3. ¿Ya está armado? Entonces no hay nada que hacer.
$slots = $estructura->get_slots();
if (count($slots) === TOTAL) {
    $refs = $DB->count_records('question_set_references',
        ['component' => 'mod_quiz', 'questionarea' => 'slot', 'usingcontextid' => $ctxexamen->id]);
    if ($refs === TOTAL) {
        say('✅ El examen final ya está armado con sus ' . TOTAL . ' preguntas aleatorias. No toco nada.');
        exit(0);
    }
}

say("\"{$examen->name}\" (quiz {$examen->id})" . ($dry ? '   [SIMULACRO]' : ''));
say('  ahora: ' . count($slots) . ' pregunta(s)   →   objetivo: ' . TOTAL . ' (3 por módulo)');
say('');

// 4. Fuera lo que hubiera. En orden INVERSO: al quitar un slot, Moodle renumera
//    los siguientes, así que borrar de arriba a abajo iría dejando huecos y
//    quitando los que no son.
if ($slots) {
    say('Quito las ' . count($slots) . ' preguntas actuales:');
    if (!$dry) {
        for ($i = count($slots); $i >= 1; $i--) {
            $estructura->remove_slot($i);
        }
        $estructura = structure::create_for_quiz(quiz_settings::create($examen->id));
    }
    say('   ✅ examen vacío');
    say('');
}

// 5. Tres al azar de cada módulo.
say('Añado ' . POR_MODULO . ' preguntas aleatorias de cada módulo:');
foreach (MODULOS as $nombre) {
    $c = $categorias[$nombre];
    say(sprintf('   + %-38s (%d en el banco)', substr($nombre, 0, 36), $c->preguntas));
    if ($dry) {
        continue;
    }
    $estructura->add_random_questions(0, POR_MODULO, [
        'filter' => [
            'category' => [
                'jointype' => condition::JOINTYPE_DEFAULT,
                'values' => [$c->id],
                'filteroptions' => ['includesubcategories' => false],
            ],
        ],
    ]);
}

if ($dry) {
    say('');
    say('[SIMULACRO] No he escrito nada. Quita --dry para hacerlo de verdad.');
    exit(0);
}

// 6. La nota. Una pregunta, un punto: 30 preguntas → 30 puntos. Sin esto, el
//    examen conservaría la nota máxima vieja (22.5) y 30 preguntas se escalarían
//    a ella — el alumno vería una nota que no cuadra con lo que respondió.
//
//    OJO: `grade` NO se escribe a mano. update_quiz_maximum_grade() empieza por
//    comprobar si el valor ya es el pedido y, si lo es, se va sin hacer nada —
//    así que adelantarnos a ponerlo le haría creer que no hay trabajo y el LIBRO
//    DE CALIFICACIONES se quedaría con la nota vieja. Que lo escriba ella.
$settings = quiz_settings::create($examen->id);
$settings->get_grade_calculator()->recompute_quiz_sumgrades();
$settings->get_grade_calculator()->update_quiz_maximum_grade(TOTAL);

$final = $DB->get_record('quiz', ['id' => $examen->id], 'grade, sumgrades');
rebuild_course_cache($curso, true);

say('');
say('✅ Examen final armado.');
say("   {$estructura->get_question_count()} preguntas   ·   suma {$final->sumgrades}   ·   nota máxima {$final->grade}");
say('   En cada intento, Moodle saca 3 preguntas distintas de cada módulo.');
exit(0);
