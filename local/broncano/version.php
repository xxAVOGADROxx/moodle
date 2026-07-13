<?php
/**
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_broncano';
// Subir la versión es lo que hace que Moodle vuelva a leer db/events.php y
// registre el observador nuevo (user_graded). Sin esto, el observador existe en
// el código pero Moodle nunca lo llama.
$plugin->version   = 2026071300;
$plugin->requires  = 2024042200; // Moodle 4.4
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.0';
