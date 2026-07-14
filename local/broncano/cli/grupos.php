<?php
/**
 * Da a cada GRUPO sus propias fechas de examen, sin duplicar el curso.
 *
 *   php local/broncano/cli/grupos.php estado
 *   php local/broncano/cli/grupos.php crear "Presencial 2026-07-20"
 *   php local/broncano/cli/grupos.php sincronizar --dry
 *   php local/broncano/cli/grupos.php sincronizar
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 *
 * Dos convocatorias a la vez: el grupo A empieza el lunes y el B el miércoles. El
 * examen final de cada uno cae en un día distinto. La tentación es duplicar el
 * curso — y es una trampa: serían once exámenes por copia, un banco por copia
 * (arreglar una errata habría que hacerlo N veces), notas y certificados
 * repartidos entre varios cursos, y un alumno que se cambia de grupo tendría que
 * ser desmatriculado, perdiendo lo que llevara hecho.
 *
 * Moodle ya resuelve esto: GRUPOS + EXCEPCIONES DE GRUPO. El mismo curso, el mismo
 * banco, el mismo libro de notas, y cada grupo con SUS fechas. Cada alumno ve las
 * suyas y sólo las suyas, también en su calendario.
 *
 * ── El nombre del grupo ES el calendario ────────────────────────────────────
 *
 *     "Presencial 2026-07-20"      "Telemática 2026-07-20"
 *      └ modalidad   └ primer día
 *
 * De ahí sale todo, sin que Moodle tenga que preguntarle nada a NocoDB. La
 * modalidad dice CUÁNTOS días (5 · 5 · 12) y en qué día termina cada módulo; la
 * fecha dice CUÁLES son esos días. Los días se cuentan de lunes a viernes.
 *
 * ── Cuándo se abre cada examen ──────────────────────────────────────────────
 *
 * No me lo invento: el MIP §4.4(c) lo dice —
 *
 *     «al cierre de cada módulo se aplica el test de validación en el LMS»
 *
 * Así que el examen de un módulo se abre el día en que ESE módulo termina, que es
 * distinto en cada modalidad (en presencial el módulo C acaba el día 2; en
 * telemática, el día 4). Y se queda abierto hasta el final del curso, para que
 * quien falte un día pueda recuperarlo.
 *
 * El EXAMEN FINAL es la excepción: presencial y obligatorio en las tres
 * modalidades, así que se abre y se cierra el último día. Ni antes ni después.
 *
 * Idempotente. No toca el curso, sólo añade excepciones por grupo.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');

use mod_quiz\local\override_manager;

\core\session\manager::set_user(get_admin());

/**
 * En qué día del curso TERMINA cada módulo, y por tanto se abre su examen.
 * Sale de las tablas horarias del MIP §4.4(c), hora a hora.
 */
const CALENDARIO = [
    // Presencial: 5 días de 8 h.  D1: A,B,G · D2: C,F · D3: D,E · D4: H,I · D5: J
    'Presencial' => [
        'dias' => 5,
        'examenes' => ['A' => 1, 'B' => 1, 'G' => 1, 'C' => 2, 'F' => 2,
                       'D' => 3, 'E' => 3, 'H' => 4, 'I' => 4, 'J' => 5, 'FINAL' => 5],
    ],
    // Híbrida: 5 días (6 h aula + 2 h en línea).
    // D1: A,B · D2: C,D · D3: E,F,G · D4: H,I · D5: J
    'Híbrida' => [
        'dias' => 5,
        'examenes' => ['A' => 1, 'B' => 1, 'C' => 2, 'D' => 2, 'E' => 3, 'F' => 3,
                       'G' => 3, 'H' => 4, 'I' => 4, 'J' => 5, 'FINAL' => 5],
    ],
    // Telemática: 11 días de teoría (3 h/día, L–V, tres semanas) + 1 presencial.
    // A→d1 · B→d2 · C→d4 · D→d5 · E,F→d7 · G→d8 · H→d10 · I,J→d11 · FINAL→d12
    'Telemática' => [
        'dias' => 12,
        'examenes' => ['A' => 1, 'B' => 2, 'C' => 4, 'D' => 5, 'E' => 7, 'F' => 7,
                       'G' => 8, 'H' => 10, 'I' => 11, 'J' => 11, 'FINAL' => 12],
    ],
];

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

/** «Presencial 2026-07-20» → ['Presencial', '2026-07-20'], o null si no es un grupo nuestro. */
function leer_grupo(string $nombre): ?array {
    foreach (array_keys(CALENDARIO) as $m) {
        if (preg_match('/^' . preg_quote($m, '/') . '\s+(\d{4}-\d{2}-\d{2})$/u', trim($nombre), $x)) {
            return [$m, $x[1]];
        }
    }
    return null;
}

/** Los `n` días LECTIVOS desde la fecha de inicio (sin sábados ni domingos). */
function dias_lectivos(string $inicio, int $n): array {
    $d = new DateTimeImmutable($inicio);
    $fechas = [];
    while (count($fechas) < $n) {
        if ((int) $d->format('N') <= 5) {   // 1=lunes … 5=viernes
            $fechas[] = $d->format('Y-m-d');
        }
        $d = $d->modify('+1 day');
    }
    return $fechas;
}

/** De qué módulo es este examen: 'A'…'J', 'FINAL', o null. */
function modulo_de(string $nombre): ?string {
    $n = core_text::strtoupper(trim(preg_replace('/\s+/', ' ', core_text::specialtoascii($nombre))));
    if (strpos($n, 'EXAMEN FINAL') === 0) {
        return 'FINAL';
    }
    return preg_match('/^EVALUACION MODULO ([A-J])$/', $n, $m) ? $m[1] : null;
}

/** 00:00 de una fecha; o 23:59:59 si $fin. */
function momento(string $fecha, bool $fin = false): int {
    [$y, $m, $d] = array_map('intval', explode('-', $fecha));
    return $fin ? make_timestamp($y, $m, $d, 23, 59, 59) : make_timestamp($y, $m, $d, 0, 0, 0);
}

global $DB;

// ── crear ───────────────────────────────────────────────────────────────────
if ($accion === 'crear') {
    $nombre = $argv[2] ?? '';
    if (!leer_grupo($nombre)) {
        morir("«{$nombre}» no vale como nombre de grupo.\n"
            . "   Tiene que ser: <Modalidad> <AAAA-MM-DD>, p. ej. «Presencial 2026-07-20».\n"
            . '   Modalidades: ' . implode(' · ', array_keys(CALENDARIO)));
    }
    if ($DB->get_record('groups', ['courseid' => $curso, 'name' => $nombre])) {
        say("✓ El grupo «{$nombre}» ya existía.");
        exit(0);
    }
    $g = new stdClass();
    $g->courseid = $curso;
    $g->name = $nombre;
    $id = groups_create_group($g);
    say("✅ Grupo «{$nombre}» creado (id {$id}).");
    say('   Ahora:  php grupos.php sincronizar');
    exit(0);
}

// ── Los exámenes del curso, por módulo ──────────────────────────────────────
$examenes = [];
foreach ($DB->get_records('quiz', ['course' => $curso], 'id', 'id, name, timeopen, timeclose') as $q) {
    $letra = modulo_de($q->name);
    if ($letra) {
        $examenes[$letra] = $q;
    }
}

$grupos = [];
foreach ($DB->get_records('groups', ['courseid' => $curso], 'name') as $g) {
    $leido = leer_grupo($g->name);
    if ($leido) {
        $grupos[$g->id] = [$g, $leido[0], $leido[1]];
    } else {
        say("⚠️  El grupo «{$g->name}» no sigue el formato <Modalidad> <AAAA-MM-DD>; lo ignoro.");
    }
}

// ── estado ──────────────────────────────────────────────────────────────────
if ($accion === 'estado') {
    say("Curso {$curso} — " . count($examenes) . ' exámenes · ' . count($grupos) . " grupo(s)\n");
    if (!$grupos) {
        say('No hay grupos todavía. Crea uno:');
        say('   php grupos.php crear "Presencial 2026-07-20"');
        exit(0);
    }
    foreach ($grupos as $gid => [$g, $mod, $inicio]) {
        $dias = dias_lectivos($inicio, CALENDARIO[$mod]['dias']);
        $n = $DB->count_records('groups_members', ['groupid' => $gid]);
        say("── {$g->name}   ({$n} alumno(s))");
        say('   días: ' . $dias[0] . ' … ' . end($dias) . '   (' . count($dias) . ' lectivos)');
        foreach (CALENDARIO[$mod]['examenes'] as $letra => $dia) {
            if (!isset($examenes[$letra])) {
                say("   ⚠️  falta el examen del módulo {$letra} en el curso");
                continue;
            }
            $o = $DB->get_record('quiz_overrides', ['quiz' => $examenes[$letra]->id, 'groupid' => $gid]);
            $quiere = $dias[$dia - 1];
            $tiene = $o && $o->timeopen ? userdate($o->timeopen, '%Y-%m-%d') : '—';
            say(sprintf('   %-6s día %-2d  abre %s   %s',
                $letra === 'FINAL' ? 'FINAL' : "mód {$letra}", $dia, $quiere,
                $tiene === $quiere ? '✅' : ($tiene === '—' ? '⚠️  sin excepción' : "⚠️  ahora {$tiene}")));
        }
        say('');
    }
    exit(0);
}

if ($accion !== 'sincronizar') {
    say('Uso: php grupos.php [estado|crear <nombre>|sincronizar] [--dry]');
    exit(1);
}

// ── sincronizar ─────────────────────────────────────────────────────────────
if (!$grupos) {
    morir("No hay ningún grupo con el formato <Modalidad> <AAAA-MM-DD>.\n"
        . '   Crea uno:  php grupos.php crear "Presencial 2026-07-20"');
}

$escritas = 0;
foreach ($grupos as $gid => [$g, $mod, $inicio]) {
    $dias = dias_lectivos($inicio, CALENDARIO[$mod]['dias']);
    $ultimo = end($dias);
    say("── {$g->name}" . ($dry ? '   [SIMULACRO]' : ''));
    say("   {$dias[0]} … {$ultimo}   (" . count($dias) . ' días lectivos)');

    foreach (CALENDARIO[$mod]['examenes'] as $letra => $dia) {
        if (!isset($examenes[$letra])) {
            say("   ⚠️  no encuentro el examen del módulo {$letra}; me lo salto");
            continue;
        }
        $quiz = $examenes[$letra];
        $abre = momento($dias[$dia - 1]);
        // El final es presencial y se rinde ese día: se abre y se cierra ahí.
        // Los de módulo aguantan hasta el final del curso, para poder recuperarlos.
        $cierra = momento($letra === 'FINAL' ? $dias[$dia - 1] : $ultimo, true);

        $existente = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'groupid' => $gid]);
        if ($existente && (int) $existente->timeopen === $abre && (int) $existente->timeclose === $cierra) {
            say(sprintf('   ✓ %-6s %s', $letra === 'FINAL' ? 'FINAL' : "mód {$letra}", $dias[$dia - 1]));
            continue;
        }

        say(sprintf('   → %-6s abre %s   cierra %s', $letra === 'FINAL' ? 'FINAL' : "mód {$letra}",
            $dias[$dia - 1], $letra === 'FINAL' ? $dias[$dia - 1] : $ultimo));
        if ($dry) {
            continue;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $curso, false, MUST_EXIST);
        $ctx = context_module::instance($cm->id);
        $quizobj = $DB->get_record('quiz', ['id' => $quiz->id]);
        $quizobj->cmid = $cm->id;   // el override_manager lo exige

        $datos = ['groupid' => $gid, 'timeopen' => $abre, 'timeclose' => $cierra];
        if ($existente) {
            $datos['id'] = $existente->id;
        }
        try {
            (new override_manager($quizobj, $ctx))->save_override($datos);
            $escritas++;
        } catch (Exception $e) {
            say('      ⛔ ' . $e->getMessage());
        }
    }
    say('');
}

if ($dry) {
    say('[SIMULACRO] No he escrito nada.');
    exit(0);
}

rebuild_course_cache($curso, true);
say("✅ {$escritas} excepción(es) escritas. Cada grupo ve SUS fechas y sólo las suyas.");
exit(0);
