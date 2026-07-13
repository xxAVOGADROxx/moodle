<?php
/**
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback'  => 'local_broncano_observer::user_enrolled',
    ],
    [
        // Cierra el agujero de la nota: sin esto ninguna calificación salía de
        // Moodle y la nota teórica había que teclearla a mano en NocoDB.
        'eventname' => '\core\event\user_graded',
        'callback'  => 'local_broncano_observer::user_graded',
    ],
];
