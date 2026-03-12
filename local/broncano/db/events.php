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
    ]
];
