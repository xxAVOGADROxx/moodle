<?php
/**
 * Abre temporalmente los exámenes del curso para poder ensayar el flujo completo,
 * y luego los deja EXACTAMENTE como estaban.
 *
 *   php local/broncano/cli/exams_window.php estado      → qué hay ahora
 *   php local/broncano/cli/exams_window.php abrir       → abre todos (guarda copia)
 *   php local/broncano/cli/exams_window.php restaurar   → los devuelve a su sitio
 *
 * Los once exámenes (módulos A–J + final) abren el 17 de julio. Hasta entonces
 * están cerrados y no se pueden rendir, así que no hay forma de probar la cadena
 * nota → Progreso → nota_teorica → GRADUADO → certificado sin abrirlos.
 *
 * ── Cómo se garantiza que se restauran ──────────────────────────────────────
 *
 * `abrir` guarda las fechas originales de cada examen en la configuración del
 * plugin, en JSON, ANTES de tocarlas. `restaurar` las lee de ahí y las escribe
 * de vuelta, una a una, y sólo entonces borra la copia.
 *
 * Y lo importante: si ya existe una copia, `abrir` SE NIEGA a sobrescribirla.
 * Sin esa negativa, ejecutar `abrir` dos veces guardaría como "original" el
 * estado ya abierto (timeopen = 0), y las fechas de verdad se perderían para
 * siempre. Es el fallo clásico de los scripts de respaldo, y aquí sería
 * irreversible: nadie recuerda de memoria once fechas con sus horas.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

const CLAVE_COPIA = 'exam_window_backup';
const PLUGIN = 'local_broncano';

$curso = (int) (getenv('MOODLE_COURSE_ID') ?: 5);
$accion = $argv[1] ?? 'estado';

function say($m) {
    fwrite(STDOUT, "$m\n");
}

global $DB;

$examenes = $DB->get_records('quiz', ['course' => $curso], 'id', 'id, name, timeopen, timeclose');
if (!$examenes) {
    say("No hay exámenes en el curso {$curso}.");
    exit(1);
}

$copia = get_config(PLUGIN, CLAVE_COPIA);

// ── estado ──────────────────────────────────────────────────────────────────
if ($accion === 'estado') {
    say("Curso {$curso} — " . count($examenes) . " exámenes");
    say(str_repeat('─', 78));
    foreach ($examenes as $q) {
        $abre = $q->timeopen ? userdate($q->timeopen, '%d/%m/%Y %H:%M') : 'ABIERTO (sin fecha)';
        $cierra = $q->timeclose ? userdate($q->timeclose, '%d/%m/%Y %H:%M') : '—';
        $rendible = (!$q->timeopen || $q->timeopen <= time()) && (!$q->timeclose || $q->timeclose > time());
        say(sprintf('  %-38s abre: %-22s cierra: %-16s %s',
            core_text::substr($q->name, 0, 36), $abre, $cierra,
            $rendible ? '✅ se puede rendir' : '⛔ cerrado'));
    }
    say(str_repeat('─', 78));
    say($copia
        ? '📦 HAY una copia guardada: se abrieron con este script. Usa `restaurar`.'
        : '   Sin copia guardada: las fechas actuales son las originales.');
    exit(0);
}

// ── abrir ───────────────────────────────────────────────────────────────────
if ($accion === 'abrir') {
    if ($copia) {
        say('⛔ Ya hay una copia guardada, así que los exámenes YA están abiertos por');
        say('   este script. No la sobrescribo: si lo hiciera, guardaría el estado ya');
        say('   abierto como si fuera el original y las fechas de verdad se perderían.');
        say('   Ejecuta `restaurar` primero.');
        exit(1);
    }

    $original = [];
    foreach ($examenes as $q) {
        $original[$q->id] = ['timeopen' => (int) $q->timeopen, 'timeclose' => (int) $q->timeclose];
    }
    // La copia se guarda ANTES de tocar nada.
    set_config(CLAVE_COPIA, json_encode($original), PLUGIN);
    say('📦 Fechas originales guardadas (' . count($original) . ' exámenes).');

    foreach ($examenes as $q) {
        $DB->set_field('quiz', 'timeopen', 0, ['id' => $q->id]);
        $DB->set_field('quiz', 'timeclose', 0, ['id' => $q->id]);
        say('   🔓 ' . $q->name);
    }

    rebuild_course_cache($curso, true);
    say('');
    say('Listo: los ' . count($examenes) . ' exámenes se pueden rendir ahora.');
    say('Cuando acabes:  php local/broncano/cli/exams_window.php restaurar');
    exit(0);
}

// ── restaurar ───────────────────────────────────────────────────────────────
if ($accion === 'restaurar') {
    if (!$copia) {
        say('⛔ No hay ninguna copia guardada: no sé cuáles eran las fechas originales.');
        say('   O no se abrieron con este script, o ya se restauraron.');
        exit(1);
    }

    $original = json_decode($copia, true);
    if (!is_array($original)) {
        say('⛔ La copia guardada está corrupta. NO la borro: mírala a mano en');
        say('   mdl_config_plugins (plugin=' . PLUGIN . ', name=' . CLAVE_COPIA . ').');
        exit(1);
    }

    foreach ($original as $quizid => $fechas) {
        if (!$DB->record_exists('quiz', ['id' => $quizid])) {
            say("   ⚠️  el examen {$quizid} ya no existe — me lo salto");
            continue;
        }
        $DB->set_field('quiz', 'timeopen', (int) $fechas['timeopen'], ['id' => $quizid]);
        $DB->set_field('quiz', 'timeclose', (int) $fechas['timeclose'], ['id' => $quizid]);
        $q = $DB->get_record('quiz', ['id' => $quizid], 'name');
        $abre = $fechas['timeopen'] ? userdate((int) $fechas['timeopen'], '%d/%m/%Y %H:%M') : 'sin fecha';
        say("   🔒 {$q->name} → abre {$abre}");
    }

    // Sólo se borra la copia cuando todo se ha escrito de vuelta.
    unset_config(CLAVE_COPIA, PLUGIN);
    rebuild_course_cache($curso, true);
    say('');
    say('Restaurado. Las fechas vuelven a ser las de antes.');
    exit(0);
}

say("Uso: php exams_window.php [estado|abrir|restaurar]");
exit(1);
