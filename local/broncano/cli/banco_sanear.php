<?php
/**
 * Sanea el banco de preguntas del curso y deja cada examen de módulo preguntando
 * SU materia.
 *
 *   php local/broncano/cli/banco_sanear.php estado
 *   php local/broncano/cli/banco_sanear.php sanear --dry
 *   php local/broncano/cli/banco_sanear.php sanear
 *
 * ── Lo que se encontró ──────────────────────────────────────────────────────
 *
 * 1. El examen del MÓDULO E sacaba sus doce preguntas de `Modulo_D_Meteorologia`.
 *    Un examen de Derecho Aeronáutico que preguntaba Meteorología, y que ponía
 *    nota. Esto no es limpieza: es un fallo de evaluación.
 *
 * 2. El banco tenía importaciones repetidas: las 36 preguntas del módulo A
 *    estaban OCHO veces (288) y las del B, tres (108). Con duplicados, «12 al
 *    azar» puede darle al alumno la MISMA pregunta dos veces en el mismo examen:
 *    son filas distintas con el mismo texto, así que Moodle no las ve como
 *    repetidas.
 *
 * 3. Y había una categoría `Modulo_B` fantasma DENTRO del cuestionario del módulo
 *    B (otras 144), que no usaba ningún examen.
 *
 * 4. Los exámenes A y B no bebían del banco, sino de dos categorías aparte
 *    (`Evaluación A`, `Evaluación B`). Se comprobó pregunta a pregunta que son
 *    las mismas 36, así que apuntarlos al banco no les cambia el contenido —
 *    sólo la fuente. Es lo que hace que el examen final y el de módulo evalúen
 *    exactamente lo mismo.
 *
 * ── Qué se conserva ─────────────────────────────────────────────────────────
 *
 * NINGUNA pregunta se pierde. De cada grupo de copias idénticas se guarda la
 * primera y se borran las repeticiones. Y si una pregunta estuviera usada en un
 * intento, Moodle no la borra: la oculta (question_delete_question lo garantiza).
 *
 * Idempotente: pasarlo dos veces no hace nada la segunda.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/questionlib.php');

use core_question\local\bank\condition;
use mod_quiz\quiz_settings;
use mod_quiz\structure;

// Módulo → categoría del banco que le corresponde. Es la tabla de la verdad:
// contra esto se compara lo que cada examen está preguntando de verdad.
const BANCO = [
    'A' => 'Modulo_A_Conocimiento_General',
    'B' => 'Modulo_B_Principios_de_Vuelo',
    'C' => 'Modulo_C_Operaciones_de_Vuelo',
    'D' => 'Modulo_D_Meteorologia',
    'E' => 'Modulo_E_Derecho_Aeronautico',
    'F' => 'Modulo_F_Factores_Humanos',
    'G' => 'Modulo_G_Navegacion_Aerea',
    'H' => 'Modulo_H_Servicios_de_Transito_Aereo',
    'I' => 'Modulo_I_Radiotelefonia_Aeronautica',
    'J' => 'Modulo_J_SMS',
];

const POR_EXAMEN = 12;      // MIP Cap. 6: prueba de módulo = 12 preguntas del banco

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

/**
 * De qué módulo es este examen, mirando su nombre.
 *
 * Los nombres están escritos a mano y no son coherentes: «EVALUACIÓN MÓDULO A»,
 * «EVALUACION MODULO D», «EVALUACIÓN  MÓDULO E» (con dos espacios). Comparar
 * cadenas tal cual fallaría en la mitad, así que se normalizan: sin tildes, sin
 * espacios de más, en mayúsculas.
 */
function modulo_de(string $nombre): ?string {
    $n = core_text::specialtoascii($nombre);
    $n = core_text::strtoupper(trim(preg_replace('/\s+/', ' ', $n)));
    return preg_match('/^EVALUACION MODULO ([A-J])$/', $n, $m) ? $m[1] : null;
}

/** Las preguntas vivas de una categoría: entrada → nombre + versiones. */
function entradas_de(int $catid): array {
    global $DB;
    $filas = $DB->get_records_sql(
        "SELECT qv.id AS vid, qbe.id AS entryid, q.id AS questionid, q.name
           FROM {question_bank_entries} qbe
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
           JOIN {question} q ON q.id = qv.questionid
          WHERE qbe.questioncategoryid = ?
       ORDER BY qbe.id, qv.version",
        [$catid]
    );
    $entradas = [];
    foreach ($filas as $f) {
        $entradas[$f->entryid]['name'] = $f->name;
        $entradas[$f->entryid]['qids'][] = (int) $f->questionid;
    }
    return $entradas;
}

/** Las categorías a las que apunta este cuestionario, y cuántas veces. */
function categorias_de_quiz(int $ctxid): array {
    global $DB;
    $cats = [];
    $refs = $DB->get_records('question_set_references',
        ['usingcontextid' => $ctxid, 'component' => 'mod_quiz', 'questionarea' => 'slot']);
    foreach ($refs as $r) {
        $f = json_decode($r->filtercondition, true);
        $vals = $f['filter']['category']['values']
            ?? (isset($f['questioncategoryid']) ? [$f['questioncategoryid']] : []);
        foreach ($vals as $cid) {
            $cats[(int) $cid] = ($cats[(int) $cid] ?? 0) + 1;
        }
    }
    return $cats;
}

global $DB;

$ctxcurso = context_course::instance($curso);

// ── Las categorías del banco: la buena de cada módulo, y las fantasmas ───────
//
// «La buena» es la que vive en el contexto del CURSO. Una categoría con el mismo
// nombre metida dentro de un cuestionario no la ve nadie más que ese cuestionario,
// así que no puede ser la fuente del examen final.
$canon = [];      // letra => categoría del curso
$fantasmas = [];  // categorías Modulo_* fuera del contexto del curso
foreach ($DB->get_records('question_categories', null, 'id') as $c) {
    $letra = array_search($c->name, BANCO, true);
    if ($letra === false) {
        continue;
    }
    if ((int) $c->contextid === (int) $ctxcurso->id) {
        if (isset($canon[$letra])) {
            morir("Hay DOS categorías «{$c->name}» en el curso (ids {$canon[$letra]->id} y {$c->id}).\n"
                . '   No adivino cuál es la buena. Míralas y borra una a mano.');
        }
        $canon[$letra] = $c;
    } else {
        $fantasmas[] = $c;
    }
}

// ── estado ──────────────────────────────────────────────────────────────────
// Se imprime SIEMPRE, también antes de sanear: nada se toca sin enseñar primero
// de qué se parte.
{
    say("Curso {$curso}" . ($dry ? '   [SIMULACRO]' : ''));
    say('');
    say('BANCO');
    say(str_repeat('─', 74));
    say(sprintf('  %-38s %-8s %-9s %s', 'CATEGORÍA', 'AHORA', 'DISTINTAS', 'SOBRAN'));
    foreach (BANCO as $letra => $nombre) {
        if (!isset($canon[$letra])) {
            say(sprintf('  %-38s %s', substr($nombre, 0, 36), '❌ NO EXISTE en el banco del curso'));
            continue;
        }
        $e = entradas_de($canon[$letra]->id);
        $unicas = count(array_unique(array_column($e, 'name')));
        $sobran = count($e) - $unicas;
        say(sprintf('  %-38s %-8d %-9d %s',
            substr($nombre, 0, 36), count($e), $unicas,
            $sobran ? "⚠️  {$sobran} repetidas" : '✅'));
    }
    foreach ($fantasmas as $f) {
        $ctx = context::instance_by_id($f->contextid, IGNORE_MISSING);
        $n = count(entradas_de($f->id));
        say(sprintf('  %-38s %-8d %-9s 👻 dentro de «%s»',
            substr($f->name, 0, 36), $n, '', $ctx ? $ctx->get_context_name(false) : '?'));
    }

    say('');
    say('EXÁMENES DE MÓDULO');
    say(str_repeat('─', 74));
    foreach ($DB->get_records('quiz', ['course' => $curso], 'id', 'id, name') as $q) {
        $letra = modulo_de($q->name);
        if (!$letra) {
            continue;
        }
        $cm = get_coursemodule_from_instance('quiz', $q->id, $curso);
        $cats = categorias_de_quiz(context_module::instance($cm->id)->id);
        $debe = $canon[$letra]->id ?? 0;
        $ok = count($cats) === 1 && array_key_first($cats) === $debe && reset($cats) === POR_EXAMEN;
        $desc = [];
        foreach ($cats as $cid => $n) {
            $c = $DB->get_record('question_categories', ['id' => $cid], 'name');
            $desc[] = ($c ? $c->name : "cat{$cid} BORRADA") . " ×{$n}";
        }
        say(sprintf('  %-30s %s %s', substr($q->name, 0, 28), $ok ? '✅' : '⚠️ ',
            $desc ? implode(', ', $desc) : 'sin preguntas'));
        if (!$ok) {
            say(sprintf('  %-30s    debería ser: %s ×%d', '', BANCO[$letra], POR_EXAMEN));
        }
    }
    say('');
}

if ($accion === 'estado') {
    exit(0);
}
if ($accion !== 'sanear') {
    say('Uso: php banco_sanear.php [estado|sanear] [--dry]');
    exit(1);
}

// ── sanear ──────────────────────────────────────────────────────────────────
foreach (BANCO as $letra => $nombre) {
    if (!isset($canon[$letra])) {
        morir("Falta «{$nombre}» en el banco del curso. Importa Modulo_{$letra}_banco.gift antes.");
    }
}

$borradas = 0;

// 1. Quitar las copias repetidas de cada categoría del banco.
say('QUITAR REPETIDAS');
foreach (BANCO as $letra => $nombre) {
    $e = entradas_de($canon[$letra]->id);
    $vistas = [];
    $sobrantes = [];
    foreach ($e as $entryid => $datos) {
        // La primera copia de cada texto se queda; las demás sobran.
        if (isset($vistas[$datos['name']])) {
            $sobrantes = array_merge($sobrantes, $datos['qids']);
        } else {
            $vistas[$datos['name']] = $entryid;
        }
    }
    if (!$sobrantes) {
        say(sprintf('  ✓ %-38s ya estaba limpia (%d)', substr($nombre, 0, 36), count($e)));
        continue;
    }
    say(sprintf('  → %-38s %d → %d   (borro %d repetidas)',
        substr($nombre, 0, 36), count($e), count($vistas), count($sobrantes)));
    if (!$dry) {
        foreach ($sobrantes as $qid) {
            question_delete_question($qid);
        }
    }
    $borradas += count($sobrantes);
}

// 2. Las categorías fantasma. Sólo si NADIE las usa — comprobado, no supuesto.
say('');
say('CATEGORÍAS FANTASMA');
if (!$fantasmas) {
    say('  ✓ ninguna');
}
foreach ($fantasmas as $f) {
    $usada = 0;
    foreach ($DB->get_records('question_set_references', ['component' => 'mod_quiz']) as $r) {
        $fc = json_decode($r->filtercondition, true);
        $vals = $fc['filter']['category']['values']
            ?? (isset($fc['questioncategoryid']) ? [$fc['questioncategoryid']] : []);
        if (in_array((string) $f->id, array_map('strval', $vals), true)) {
            $usada++;
        }
    }
    if ($usada) {
        say("  ⛔ «{$f->name}» (cat {$f->id}) la usan {$usada} examen(es). NO la borro.");
        continue;
    }
    $e = entradas_de($f->id);
    say("  → «{$f->name}» (cat {$f->id}): sin usar, borro sus " . count($e) . ' preguntas y la categoría');
    if (!$dry) {
        foreach ($e as $datos) {
            foreach ($datos['qids'] as $qid) {
                question_delete_question($qid);
            }
        }
        $DB->delete_records('question_categories', ['id' => $f->id]);
    }
    $borradas += count($e);
}

// 3. Cada examen, a su materia.
say('');
say('EXÁMENES → SU MATERIA');
foreach ($DB->get_records('quiz', ['course' => $curso], 'id', 'id, name') as $q) {
    $letra = modulo_de($q->name);
    if (!$letra) {
        continue;
    }
    $cm = get_coursemodule_from_instance('quiz', $q->id, $curso);
    $ctx = context_module::instance($cm->id);
    $cats = categorias_de_quiz($ctx->id);
    $debe = (int) $canon[$letra]->id;

    if (count($cats) === 1 && array_key_first($cats) === $debe && reset($cats) === POR_EXAMEN) {
        say(sprintf('  ✓ %-30s ya pregunta %s', substr($q->name, 0, 28), BANCO[$letra]));
        continue;
    }

    if (quiz_has_attempts($q->id)) {
        say(sprintf('  ⛔ %-30s TIENE INTENTOS REALES — no lo toco', substr($q->name, 0, 28)));
        continue;
    }

    say(sprintf('  → %-30s ahora saca de [%s] → pasa a %s ×%d',
        substr($q->name, 0, 28),
        implode(', ', array_keys($cats)) ?: '—',
        BANCO[$letra], POR_EXAMEN));

    if ($dry) {
        continue;
    }

    $estructura = structure::create_for_quiz(quiz_settings::create($q->id));
    // En orden INVERSO: al quitar un slot Moodle renumera los siguientes, así que
    // borrar de arriba abajo iría saltándose la mitad.
    for ($i = count($estructura->get_slots()); $i >= 1; $i--) {
        $estructura->remove_slot($i);
    }
    $estructura = structure::create_for_quiz(quiz_settings::create($q->id));
    $estructura->add_random_questions(0, POR_EXAMEN, [
        'filter' => [
            'category' => [
                'jointype' => condition::JOINTYPE_DEFAULT,
                'values' => [$debe],
                'filteroptions' => ['includesubcategories' => false],
            ],
        ],
    ]);
    $settings = quiz_settings::create($q->id);
    $settings->get_grade_calculator()->recompute_quiz_sumgrades();
    $settings->get_grade_calculator()->update_quiz_maximum_grade(POR_EXAMEN);
}

if ($dry) {
    say('');
    say("[SIMULACRO] No he escrito nada. Se borrarían {$borradas} preguntas repetidas.");
    exit(0);
}

rebuild_course_cache($curso, true);
say('');
say("✅ Banco saneado. {$borradas} preguntas repetidas fuera; cada examen pregunta su materia.");
say('   Ahora:  php local/broncano/cli/examen_final.php armar');
exit(0);
