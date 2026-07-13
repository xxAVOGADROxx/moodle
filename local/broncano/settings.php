<?php
/**
 * Ajustes de local_broncano.
 *
 * El observador lee `get_config('local_broncano', 'webhook_secret')`, pero el
 * plugin no tenía página de ajustes, así que esa llamada devolvía siempre null y
 * el secreto viajaba vacío. Funcionaba de casualidad: MOODLE_WEBHOOK_SECRET
 * también estaba vacío en el academy-service, que sólo valida el secreto cuando
 * está definido. El día que alguien lo rellenara en el .env "para asegurar el
 * webhook", Moodle habría empezado a comer 401 en silencio y los alumnos habrían
 * dejado de enlazarse sin un solo error visible.
 *
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_broncano',
        get_string('pluginname', 'local_broncano')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_broncano/webhook_secret',
        get_string('webhook_secret', 'local_broncano'),
        get_string('webhook_secret_desc', 'local_broncano'),
        ''
    ));
}
