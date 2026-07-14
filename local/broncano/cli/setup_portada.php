<?php
/**
 * Portada e imagen del curso: quita el lorem ipsum del tema Academi y pone
 * contenido real de Broncano, con fotos de los drones propios.
 *
 *   docker compose exec -T php php /var/www/moodle/local/broncano/cli/setup_portada.php
 *
 * Las imágenes se esperan en /tmp/ dentro del contenedor (se copian antes).
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 * El texto es deliberadamente conservador: describe el programa (40 h, RDAC 101,
 * simulador, certificado firmado) y NO afirma que la organización esté aprobada
 * por la DGAC, porque eso depende del estado del UOC y no es cosa de un script
 * inventárselo.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

function say($m) { fwrite(STDERR, "   $m\n"); }

global $DB, $CFG;

$fs = get_file_storage();
$syscontext = context_system::instance();

// ── 1. Imagen del curso ─────────────────────────────────────────────────────
// Sin ella, Moodle pinta un placeholder genérico en "Cursos disponibles".
$COURSE_ID = (int) (getenv('MOODLE_COURSE_ID') ?: 5);
$imagen_curso = '/tmp/matrice_4e_dron_compress.jpg';

if (!file_exists($imagen_curso)) {
    say("AVISO: no encuentro {$imagen_curso} — me salto la imagen del curso");
} else {
    $ctx = context_course::instance($COURSE_ID);
    // Borrar la anterior para no acumular.
    $fs->delete_area_files($ctx->id, 'course', 'overviewfiles', 0);
    $fs->create_file_from_pathname([
        'contextid' => $ctx->id,
        'component' => 'course',
        'filearea'  => 'overviewfiles',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => 'curso_uas.jpg',
    ], $imagen_curso);
    say("imagen del curso {$COURSE_ID} puesta (Matrice 4E)");
}

// ── 2. Slider de la portada ─────────────────────────────────────────────────
// Los tres slides venían con el lorem ipsum por defecto del tema.
// Ilustraciones de los tres tipos de aeronave (Módulo A), en PNG transparente.
$slides = [
    1 => [
        'title' => 'Piloto a Distancia de UAS',
        'desc'  => '<p>Programa completo de instrucción conforme a la RDAC 101: '
                 . '40 horas entre teoría, simulador y vuelo real.</p>',
        'image' => '/tmp/ala_rotatoria_opt.png',
    ],
    2 => [
        'title' => 'Entrena antes de volar',
        'desc'  => '<p>Practica maniobras, emergencias y procedimientos en nuestro simulador. '
                 . 'Tus horas de entrenamiento quedan registradas automáticamente.</p>',
        'image' => '/tmp/vtol_opt.png',
    ],
    3 => [
        'title' => 'Certificación con trazabilidad',
        'desc'  => '<p>Al superar el programa recibes tu certificado con firma electrónica '
                 . 'y un código único de verificación.</p>',
        'image' => '/tmp/ala_fija_opt.png',
    ],
];

foreach ($slides as $i => $s) {
    // OJO: el ajuste se llama `slideXcaption`, no `slideXtitle`. Con el nombre
    // equivocado el tema lo ignora y sigue pintando su texto por defecto
    // ("Carrusel basado en Bootstrap - 02"), que es lo que se veía.
    set_config("slide{$i}caption", $s['title'], 'theme_academi');
    set_config("slide{$i}desc", $s['desc'], 'theme_academi');
    set_config("slide{$i}status", 1, 'theme_academi');

    if (file_exists($s['image'])) {
        $filearea = "slide{$i}image";
        // Respetar la extensión real: los banners son PNG transparentes.
        $ext = strtolower(pathinfo($s['image'], PATHINFO_EXTENSION)) ?: 'jpg';
        $filename = "slide{$i}.{$ext}";
        $fs->delete_area_files($syscontext->id, 'theme_academi', $filearea, 0);
        $fs->create_file_from_pathname([
            'contextid' => $syscontext->id,
            'component' => 'theme_academi',
            'filearea'  => $filearea,
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $s['image']);
        set_config($filearea, '/' . $filename, 'theme_academi');
        say("slide {$i}: \"{$s['title']}\" + imagen");
    } else {
        say("slide {$i}: \"{$s['title']}\" (sin imagen: no encontré {$s['image']})");
    }
}

set_config('numberofslides', count($slides), 'theme_academi');
set_config('toggleslideshow', 1, 'theme_academi');

// Forzar a Moodle a regenerar el CSS/caché del tema, o no se ve el cambio.
theme_reset_all_caches();
purge_all_caches();
say('cachés del tema purgadas');

say('Listo. Recarga la portada con Ctrl+Shift+R.');
