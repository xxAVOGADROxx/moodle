<?php
/**
 * Devuelve al Área personal el bloque que lista los cursos.
 *
 *   docker compose exec -T php php /var/www/moodle/local/broncano/cli/fix_dashboard.php
 *   (añade --dry para ver el plan sin escribir nada)
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 *
 * El escritorio por defecto del sitio —el que se copia a CADA alumno nuevo— tenía
 * sólo tres bloques: Línea de tiempo, Calendario y Elementos vistos
 * recientemente. Le faltaba `myoverview`, la "Vista general de curso", que es el
 * único sitio del Área personal donde el alumno ve sus cursos y puede entrar en
 * ellos.
 *
 * Resultado: un alumno matriculado entraba en /my/, veía un calendario, un
 * "No hay cursos actuales" y nada más. Literalmente no había nada que pulsar para
 * llegar a su curso. Parecía que la página estuviera rota o congelada; en realidad
 * estaba vacía de lo único que importaba.
 *
 * ── El arreglo ──────────────────────────────────────────────────────────────
 *
 * Se añade el bloque en dos sitios, porque son independientes:
 *
 *   1. El escritorio POR DEFECTO (contexto del sistema): lo heredan los alumnos
 *      que se creen a partir de ahora.
 *   2. El escritorio YA CREADO de cada alumno existente (su contexto de usuario):
 *      esos ya tienen su copia y no volverían a heredar nada.
 *
 * Se usa la API de bloques, no un INSERT a pelo: así Moodle crea el contexto del
 * bloque y las cachés se enteran. Y NO se resetean los escritorios de los alumnos
 * (`my_reset_page_for_all_users`), que es la otra forma de arreglarlo: eso borra
 * lo que cada uno haya podido colocar. Añadir es reversible; resetear, no.
 *
 * Idempotente: si el bloque ya está, no hace nada.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/blocklib.php');
require_once($CFG->dirroot . '/my/lib.php');

$dry = in_array('--dry', $argv, true);

function say($m) {
    fwrite(STDERR, "   $m\n");
}

global $DB;

const BLOQUE = 'myoverview';   // "Vista general de curso"
const REGION = 'content';
const PATRON = 'my-index';

/** ¿Ya tiene ese escritorio el bloque? */
function tiene_bloque($pageid) {
    global $DB;
    return $DB->record_exists('block_instances', [
        'blockname' => BLOQUE,
        'pagetypepattern' => PATRON,
        'subpagepattern' => (string) $pageid,
    ]);
}

/**
 * Añade el bloque a un escritorio. `$context` es el del sistema para la página
 * por defecto, y el del usuario para la de cada alumno: de ahí cuelga el bloque.
 */
function anadir_bloque($pageid, context $context, $dry) {
    if (tiene_bloque($pageid)) {
        return false;
    }
    if ($dry) {
        return true;
    }

    $page = new moodle_page();
    $page->set_context($context);
    $page->set_pagetype(PATRON);
    $page->set_subpage((string) $pageid);
    $page->blocks->add_region(REGION, false);
    // Peso -1: por encima de la línea de tiempo y el calendario. Lo primero que
    // ve el alumno al entrar debería ser su curso.
    $page->blocks->add_block(BLOQUE, REGION, -1, false, PATRON, (string) $pageid);
    return true;
}

// ── 1. El escritorio por defecto del sitio ──────────────────────────────────
$defecto = $DB->get_record('my_pages', ['userid' => null, 'private' => MY_PAGE_PRIVATE]);
if (!$defecto) {
    say('ERROR: no encuentro el escritorio por defecto (my_pages con userid NULL)');
    exit(1);
}

$sistema = context_system::instance();
if (anadir_bloque($defecto->id, $sistema, $dry)) {
    say(($dry ? '[simulacro] ' : '') . "escritorio POR DEFECTO (página {$defecto->id}): + Vista general de curso");
} else {
    say("escritorio POR DEFECTO (página {$defecto->id}): ya lo tenía");
}

// ── 2. Los escritorios ya creados de cada alumno ────────────────────────────
$paginas = $DB->get_records_select('my_pages', 'userid IS NOT NULL AND private = ?', [MY_PAGE_PRIVATE]);
$anadidos = 0;
$tenian = 0;

foreach ($paginas as $p) {
    $user = $DB->get_record('user', ['id' => $p->userid, 'deleted' => 0], 'id, username');
    if (!$user) {
        continue;   // usuario borrado: su escritorio ya no le sirve a nadie
    }

    if (anadir_bloque($p->id, context_user::instance($user->id), $dry)) {
        say(($dry ? '[simulacro] ' : '') . "  + {$user->username} (id={$user->id})");
        $anadidos++;
    } else {
        $tenian++;
    }
}

say('');
say("alumnos con el bloque añadido: {$anadidos}   ·   ya lo tenían: {$tenian}");

if (!$dry) {
    purge_all_caches();
    say('cachés purgadas');
    say('Listo. Recarga /learn/my/ con Ctrl+Shift+R.');
}
