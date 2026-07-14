<?php
/**
 * Deja los once exámenes con la MISMA configuración, en el curso real y en el de
 * pruebas.
 *
 *   php local/broncano/cli/examenes_config.php estado
 *   php local/broncano/cli/examenes_config.php aplicar --dry
 *   php local/broncano/cli/examenes_config.php aplicar
 *
 * ── Lo que se encontró ──────────────────────────────────────────────────────
 *
 * Los exámenes se fueron creando a mano, uno a uno, y cada uno acabó con ajustes
 * distintos. No es desorden estético: cambia lo que puede hacer el alumno.
 *
 *   · El MÓDULO A no tenía LÍMITE DE TIEMPO. Los otros nueve, veinte minutos.
 *   · Los módulos F, G, H, I y el EXAMEN FINAL admitían INTENTOS ILIMITADOS.
 *     Con treinta preguntas sorteadas de un banco de 360, repetir sin límite no es
 *     estudiar: es tirar los dados hasta que salgan las que uno se sabe. En el
 *     examen de suficiencia, eso vacía la evaluación de contenido.
 *   · Seis exámenes no tenían NOTA MÍNIMA, así que en Moodle no marcaban aprobado
 *     ni suspenso. (La nota que llega a NocoDB no depende de esto —`nota_teorica`
 *     se calcula allí con los porcentajes—, pero un examen sin nota de corte es
 *     difícil de defender ante una auditoría.)
 *   · Y el MÓDULO A tampoco enseñaba la nota al terminar: había que esperar dos
 *     minutos, que es cuando empieza la siguiente ventana de revisión de Moodle.
 *
 * ── El patrón ───────────────────────────────────────────────────────────────
 *
 * Módulos A–J    12 preguntas · 20 min · 2 intentos · aprueba con 9/12   (75 %)
 * Examen final   30 preguntas · 30 min · 1 intento  · aprueba con 22,5/30 (75 %)
 *
 * El 75 % es el mínimo de la RDAC 101 (MIP Cap. 6), y los 30 minutos del final son
 * los que el MIP le asigna (0:30). El resto es coherencia.
 *
 * Idempotente, y no toca nada que ya esté bien.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');

\core\session\manager::set_user(get_admin());

// Las ventanas de revisión de Moodle. La de «inmediatamente después» dura los dos
// primeros minutos tras entregar; pasados esos, manda «más tarde». Por eso el
// módulo A decía «Disponible … 17:31» cuando se había entregado a las 17:29: la
// nota sólo estaba marcada en la ventana de después.
const AL_INSTANTE = 0x01000;   // QUIZ_REVIEW_IMMEDIATELY_AFTER
const MAS_TARDE   = 0x00100;   // QUIZ_REVIEW_LATER_WHILE_OPEN

const APROBADO_PCT = 0.75;     // RDAC 101 · MIP Cap. 6

$accion = $argv[1] ?? 'estado';
$dry = in_array('--dry', $argv, true);

function say($m) {
    fwrite(STDOUT, "$m\n");
}

/** ¿Es el examen final? Se reconoce por el nombre, como hace el plugin. */
function es_final(string $nombre): bool {
    return (bool) preg_match('/final|suficiencia/i', core_text::specialtoascii($nombre));
}

/** Lo que debe tener este examen. */
function patron(string $nombre): array {
    return es_final($nombre)
        // Un solo intento: es la prueba de suficiencia, presencial y supervisada.
        ? ['timelimit' => 30 * 60, 'attempts' => 1, 'preguntas' => 30]
        : ['timelimit' => 20 * 60, 'attempts' => 2, 'preguntas' => 12];
}

global $DB;

$cursos = [];
foreach ([5, 7] as $cid) {
    $c = $DB->get_record('course', ['id' => $cid], 'id, shortname');
    if ($c) {
        $cursos[$cid] = $c;
    }
}

$cambios = 0;

foreach ($cursos as $cid => $curso) {
    say('');
    say("── Curso {$cid} · {$curso->shortname}");
    say(sprintf('   %-32s %-9s %-10s %-11s %s', 'EXAMEN', 'TIEMPO', 'INTENTOS', 'APRUEBA', 'NOTA AL ACABAR'));

    foreach ($DB->get_records('quiz', ['course' => $cid], 'id', 'id, name, grade, timelimit, attempts, reviewmarks') as $q) {
        $p = patron($q->name);
        $gi = grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $q->id, 'courseid' => $cid,
        ]);

        // La nota de corte se calcula sobre la nota máxima REAL del examen, no
        // sobre un número escrito a mano: si mañana el final pasa de 30 a 40
        // preguntas, el 75 % sigue siendo el 75 %.
        $maxima = (float) $q->grade;
        $corte = round($maxima * APROBADO_PCT, 2);

        $quiere = [
            'timelimit' => (int) $p['timelimit'],
            'attempts' => (int) $p['attempts'],
            // Que la nota se vea nada más entregar, y siga viéndose después.
            'reviewmarks' => ((int) $q->reviewmarks) | AL_INSTANTE | MAS_TARDE,
        ];

        $difQuiz = [];
        foreach ($quiere as $campo => $valor) {
            if ((int) $q->$campo !== $valor) {
                $difQuiz[$campo] = $valor;
            }
        }
        $difCorte = $gi && abs((float) $gi->gradepass - $corte) > 0.001;

        $estado = ($difQuiz || $difCorte) ? '→' : '✓';
        say(sprintf('   %s %-30s %-9s %-10s %-11s %s',
            $estado,
            core_text::substr($q->name, 0, 28),
            ($q->timelimit ? ($q->timelimit / 60) . ' min' : 'SIN LÍMITE'),
            ($q->attempts ? $q->attempts : 'ILIMITADOS'),
            ($gi && $gi->gradepass > 0 ? rtrim(rtrim(number_format((float) $gi->gradepass, 2, '.', ''), '0'), '.') . '/' . rtrim(rtrim(number_format($maxima, 2, '.', ''), '0'), '.') : 'SIN MÍNIMO'),
            (((int) $q->reviewmarks & AL_INSTANTE) ? 'sí' : 'NO (a los 2 min)')
        ));

        if (!$difQuiz && !$difCorte) {
            continue;
        }

        foreach ($difQuiz as $campo => $valor) {
            $antes = $campo === 'timelimit'
                ? ($q->timelimit ? ($q->timelimit / 60) . ' min' : 'sin límite')
                : ($campo === 'attempts' ? ($q->attempts ?: 'ilimitados') : $q->reviewmarks);
            $ahora = $campo === 'timelimit' ? ($valor / 60) . ' min' : $valor;
            say(sprintf('        %-14s %s → %s', $campo, $antes, $ahora));
        }
        if ($difCorte) {
            say(sprintf('        %-14s %s → %s  (75 %% de %s)', 'nota mínima',
                ($gi && $gi->gradepass > 0 ? $gi->gradepass : 'ninguna'), $corte, $maxima));
        }

        $cambios++;
        if ($dry) {
            continue;
        }

        foreach ($difQuiz as $campo => $valor) {
            $DB->set_field('quiz', $campo, $valor, ['id' => $q->id]);
        }
        if ($difCorte) {
            $gi->gradepass = $corte;
            $gi->update();
        }
    }

    if (!$dry) {
        rebuild_course_cache($cid, true);
    }
}

say('');
if ($accion !== 'aplicar') {
    say($cambios
        ? "{$cambios} examen(es) no siguen el patrón. Para arreglarlos:  php examenes_config.php aplicar"
        : '✅ Los once exámenes siguen el mismo patrón en los dos cursos.');
    exit(0);
}
if ($dry) {
    say("[SIMULACRO] No he escrito nada. Se cambiarían {$cambios} examen(es).");
    exit(0);
}
say($cambios
    ? "✅ {$cambios} examen(es) corregidos. Los once siguen ya el mismo patrón."
    : '✅ No había nada que cambiar.');
say('');
say('   Módulos A–J    12 preguntas · 20 min · 2 intentos · aprueba con el 75 %');
say('   Examen final   30 preguntas · 30 min · 1 intento  · aprueba con el 75 %');
exit(0);
