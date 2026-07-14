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
const AL_CERRAR   = 0x00010;   // QUIZ_REVIEW_AFTER_CLOSE

// Que el alumno pueda revisar SIEMPRE tras entregar: al momento, más tarde, y con
// el examen ya cerrado. Sin la primera —el caso del módulo A— la revisión no
// aparece en los dos minutos siguientes a terminar, que es justo cuando se mira.
const SIEMPRE = AL_INSTANTE | MAS_TARDE | AL_CERRAR;

const APROBADO_PCT = 0.75;     // RDAC 101 · MIP Cap. 6

// El curso de pruebas, por su nombre corto (el mismo que usa sandbox.php).
const CORTO_SANDBOX = 'UAS-PRUEBAS';

$accion = $argv[1] ?? 'estado';
$dry = in_array('--dry', $argv, true);

function say($m) {
    fwrite(STDOUT, "$m\n");
}

/** ¿Es el examen final? Se reconoce por el nombre, como hace el plugin. */
function es_final(string $nombre): bool {
    return (bool) preg_match('/final|suficiencia/i', core_text::specialtoascii($nombre));
}

/**
 * Lo que debe tener este examen: tiempo, intentos y QUÉ puede revisar el alumno.
 *
 * La revisión depende de PARA QUÉ es el curso:
 *
 *   CURSO REAL (evaluación) — decidido por el instructor:
 *     Módulos A–J  el alumno ve su intento, qué acertó y su nota, pero NO la
 *                  respuesta correcta ni la retroalimentación. Con dos intentos y
 *                  preguntas al azar, enseñar la correcta convertiría el segundo
 *                  intento en copiar del primero.
 *     Examen final sólo la nota. Enseñar sus 30 preguntas desgastaría el banco de
 *                  360 examen a examen.
 *
 *   CURSO DE PRUEBAS (estudio):
 *     TODO abierto. Es un entorno de estudio, no de examen: el alumno ve el
 *     intento, qué acertó, la nota, LA RESPUESTA CORRECTA y la retroalimentación
 *     —que en este banco lleva la referencia a la fuente (RDAC / módulo)—. No hay
 *     nada que proteger: aquí nadie se certifica, y los exámenes están siempre
 *     abiertos justo para practicar.
 *
 * @param bool $estudio true en el curso de pruebas: se muestra todo.
 */
function patron(string $nombre, bool $estudio): array {
    $final = es_final($nombre);
    $base = $final
        ? ['timelimit' => 30 * 60, 'attempts' => 1]   // suficiencia: un intento
        : ['timelimit' => 20 * 60, 'attempts' => 2];

    if ($estudio) {
        // Estudio: se ve todo, también el examen final.
        return $base + [
            'reviewattempt' => SIEMPRE,
            'reviewcorrectness' => SIEMPRE,
            'reviewmarks' => SIEMPRE,
            'reviewspecificfeedback' => SIEMPRE,
            'reviewgeneralfeedback' => SIEMPRE,
            'reviewrightanswer' => SIEMPRE,
            'reviewoverallfeedback' => SIEMPRE,
        ];
    }

    if ($final) {
        // Evaluación real: del final, sólo la nota.
        return $base + [
            'reviewattempt' => 0,
            'reviewcorrectness' => 0,
            'reviewmarks' => SIEMPRE,
            'reviewspecificfeedback' => 0,
            'reviewgeneralfeedback' => 0,
            'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
        ];
    }

    // Evaluación real, módulo: intento y aciertos sí; correcta y feedback no.
    return $base + [
        'reviewattempt' => SIEMPRE,
        'reviewcorrectness' => SIEMPRE,
        'reviewmarks' => SIEMPRE,
        'reviewspecificfeedback' => 0,
        'reviewgeneralfeedback' => 0,
        'reviewrightanswer' => 0,
        'reviewoverallfeedback' => 0,
    ];
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
    // El curso de pruebas es de ESTUDIO: se ve todo, la correcta incluida. Se
    // reconoce por su nombre corto, no por el id, para que siga valiendo si algún
    // día se rehace la copia con otro id.
    $estudio = ($curso->shortname === CORTO_SANDBOX);

    say('');
    say("── Curso {$cid} · {$curso->shortname}" . ($estudio ? '   (estudio: se ve todo)' : ''));
    say(sprintf('   %-32s %-9s %-10s %-11s %s', 'EXAMEN', 'TIEMPO', 'INTENTOS', 'APRUEBA', 'NOTA AL ACABAR'));

    foreach ($DB->get_records('quiz', ['course' => $cid], 'id', '*') as $q) {
        $p = patron($q->name, $estudio);
        $gi = grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $q->id, 'courseid' => $cid,
        ]);

        // La nota de corte se calcula sobre la nota máxima REAL del examen, no
        // sobre un número escrito a mano: si mañana el final pasa de 30 a 40
        // preguntas, el 75 % sigue siendo el 75 %.
        $maxima = (float) $q->grade;
        $corte = round($maxima * APROBADO_PCT, 2);

        // Todo lo del patrón salvo `preguntas`, que aquí no se toca (lo arma
        // examen_final.php / banco_sanear.php). El resto son columnas de `quiz`.
        $quiere = $p;
        unset($quiere['preguntas']);

        $difQuiz = [];
        foreach ($quiere as $campo => $valor) {
            if ((int) $q->$campo !== (int) $valor) {
                $difQuiz[$campo] = (int) $valor;
            }
        }
        $difCorte = $gi && abs((float) $gi->gradepass - $corte) > 0.001;

        // El botón «Marcar como hecho» (finalización MANUAL de la actividad) no
        // aporta nada aquí: la graduación no sale de que el alumno se marque el
        // examen, sino de su nota y de las reglas de NocoDB. Sólo despista. Ningún
        // examen debe tenerlo — y uno lo tenía suelto (el módulo A, cómo no).
        $cm = get_coursemodule_from_instance('quiz', $q->id, $cid, false, MUST_EXIST);
        $difFinaliz = (int) $cm->completion !== COMPLETION_TRACKING_NONE;

        // Resumen legible de qué revisa el alumno tras entregar (estado ACTUAL).
        $rev = ((int) $q->reviewattempt & AL_INSTANTE)
            ? (((int) $q->reviewrightanswer & AL_INSTANTE) ? 'intento + correcta' : 'intento, sin correcta')
            : (((int) $q->reviewmarks & AL_INSTANTE) ? 'sólo nota' : '⛔ nada al entregar');

        $estado = ($difQuiz || $difCorte) ? '→' : '✓';
        say(sprintf('   %s %-30s %-9s %-10s %-11s %s',
            $estado,
            core_text::substr($q->name, 0, 28),
            ($q->timelimit ? ($q->timelimit / 60) . ' min' : 'SIN LÍMITE'),
            ($q->attempts ? $q->attempts : 'ILIMITADOS'),
            ($gi && $gi->gradepass > 0 ? rtrim(rtrim(number_format((float) $gi->gradepass, 2, '.', ''), '0'), '.') . '/' . rtrim(rtrim(number_format($maxima, 2, '.', ''), '0'), '.') : 'SIN MÍNIMO'),
            $rev
        ));

        if (!$difQuiz && !$difCorte && !$difFinaliz) {
            continue;
        }

        foreach ($difQuiz as $campo => $valor) {
            $antes = $campo === 'timelimit'
                ? ($q->timelimit ? ($q->timelimit / 60) . ' min' : 'sin límite')
                : ($campo === 'attempts' ? ($q->attempts ?: 'ilimitados') : (int) $q->$campo);
            $ahora = $campo === 'timelimit' ? ($valor / 60) . ' min' : $valor;
            say(sprintf('        %-22s %s → %s', $campo, $antes, $ahora));
        }
        if ($difCorte) {
            say(sprintf('        %-22s %s → %s  (75 %% de %s)', 'nota mínima',
                ($gi && $gi->gradepass > 0 ? $gi->gradepass : 'ninguna'), $corte, $maxima));
        }
        if ($difFinaliz) {
            say('        finalización         botón «Marcar como hecho» → fuera');
        }

        $cambios++;
        if ($dry) {
            continue;
        }

        foreach ($difQuiz as $campo => $valor) {
            $DB->set_field('quiz', $campo, $valor, ['id' => $q->id]);
        }
        if ($difFinaliz) {
            // Quitar el seguimiento y borrar cualquier marca ya puesta, para que no
            // quede un estado fantasma de «completado» de cuando existía el botón.
            $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $cm->id]);
            $DB->set_field('course_modules', 'completionview', 0, ['id' => $cm->id]);
            $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $cm->id]);
            $DB->delete_records('course_modules_completion', ['coursemoduleid' => $cm->id]);
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
