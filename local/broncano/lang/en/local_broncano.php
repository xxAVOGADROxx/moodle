<?php
/**
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Broncano — puente con el academy-service';

$string['webhook_secret'] = 'Secreto del webhook';
$string['webhook_secret_desc'] =
    'Debe coincidir exactamente con <code>MOODLE_WEBHOOK_SECRET</code> en el academy-service. ' .
    'Si ambos se dejan vacíos, el servicio no valida el secreto. ' .
    'Si sólo se rellena uno de los dos, el webhook empezará a ser rechazado con 401 ' .
    'y las notas y matriculaciones dejarán de llegar a NocoDB.';

$string['privacy:metadata'] = 'El plugin no almacena datos personales; los envía al academy-service.';
