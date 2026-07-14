<?php
/**
 * Deja listos los Web Services que el academy-service necesita para crear y
 * matricular alumnos cuando marcas `dar_acceso` en NocoDB.
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 *   docker compose exec -T php php /var/www/moodle/local/broncano/cli/setup_ws.php
 *
 * Imprime SÓLO el token por stdout, para poder capturarlo sin que aparezca en
 * ningún log. Los mensajes de progreso van por stderr.
 *
 * No usa el token del administrador. Crea una cuenta de servicio con
 * autenticación `webservice` (no puede iniciar sesión en la web) y un rol con
 * exactamente los permisos que hacen falta: crear usuarios, verlos y
 * matricularlos. Si el academy-service se viera comprometido, el atacante no
 * tendría Moodle entero, sólo eso.
 *
 * @package    local_broncano
 * @copyright  2026 Broncano Project
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');
require_once($CFG->libdir . '/externallib.php');

/** Progreso por stderr: stdout queda limpio para el token. */
function say($msg) {
    fwrite(STDERR, "   $msg\n");
}

global $DB, $CFG;

$SHORTNAME = 'broncano_academy';
$USERNAME  = 'academyservice';
$ROLESHORT = 'broncanows';

// Sólo lo que el servicio necesita de verdad.
$FUNCTIONS = [
    'core_user_create_users',
    'core_user_get_users_by_field',
    'enrol_manual_enrol_users',
    'core_course_get_courses_by_field',
];
// Lo mínimo para crear un alumno y matricularlo. Nada más: si el academy-service
// cayera en malas manos, con esto se pueden crear alumnos — molesto, pero acotado.
// Con un token de administrador se tendría el Moodle entero.
$CAPS = [
    'webservice/rest:use',        // poder llamar a la API
    'moodle/user:create',         // crear la cuenta del alumno
    'moodle/user:viewdetails',    // ¿ya existe?
    'moodle/user:viewalldetails',
    // Sin esto, core_user_get_users_by_field con field=email devuelve VACÍO
    // aunque el usuario exista: Moodle exige esta capacidad para buscar por
    // campos de identidad. El servicio entonces intentaba crear un duplicado y
    // Moodle lo rechazaba, así que un alumno con cuenta previa (el caso del
    // segundo curso) no llegaba a matricularse nunca. Es de sólo lectura.
    'moodle/site:viewuseridentity',
    'enrol/manual:enrol',         // matricularlo
    'moodle/role:assign',         // darle el rol de estudiante al matricular
    'moodle/course:view',         // resolver el curso
];

// ── 1. Encender los Web Services ────────────────────────────────────────────
say('1. Activando Web Services + REST');
set_config('enablewebservices', 1);

$protocols = get_config('core', 'webserviceprotocols');
$enabled = $protocols ? explode(',', $protocols) : [];
if (!in_array('rest', $enabled)) {
    $enabled[] = 'rest';
    set_config('webserviceprotocols', implode(',', array_filter($enabled)));
    say('   protocolo REST activado');
} else {
    say('   REST ya estaba activo');
}

// ── 2. Cuenta de servicio ───────────────────────────────────────────────────
say('2. Cuenta de servicio');
$user = $DB->get_record('user', ['username' => $USERNAME, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user) {
    $new = new stdClass();
    $new->username    = $USERNAME;
    // `webservice` impide iniciar sesión por la web: esta cuenta sólo sirve para
    // el token.
    $new->auth        = 'webservice';
    $new->firstname   = 'Academy';
    $new->lastname    = 'Service';
    $new->email       = 'academy-service@broncano.invalid';
    // Sin país (y sin lang/timezone) Moodle marca la cuenta como "no configurada"
    // y rechaza sus llamadas a la API con `usernotfullysetup`.
    $new->country     = 'EC';
    $new->lang        = 'es';
    $new->password    = complex_random_string(32);
    $new->confirmed   = 1;
    $new->policyagreed = 1;
    $new->mnethostid  = $CFG->mnet_localhost_id;
    $id = user_create_user($new, true, false);
    $user = $DB->get_record('user', ['id' => $id]);
    say("   creada (id={$user->id})");
} else {
    // Reparar una cuenta anterior a la que le faltaba país/ciudad.
    $patch = [];
    if (empty($user->country)) $patch['country'] = 'EC';
    if (empty($user->city)) $patch['city'] = 'Quito';
    foreach ($patch as $f => $v) {
        $DB->set_field('user', $f, $v, ['id' => $user->id]);
    }
    if ($patch) say('   completado: ' . implode(', ', array_keys($patch)));
    say("   ya existía (id={$user->id})");
}

// Los campos de perfil personalizados obligatorios (cedula_pdf, foto_carnet)
// hacen que Moodle marque a CUALQUIER usuario sin ellos como "no configurado", y
// entonces rechaza sus llamadas a la API con `usernotfullysetup`. La cuenta de
// servicio no tiene cédula ni foto: se les da un valor inocuo. (Esto sólo afecta
// a esta cuenta; los alumnos siguen con sus campos obligatorios como estaban.)
foreach (['cedula_pdf', 'foto_carnet'] as $shortname) {
    $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
    if (!$field) {
        continue;
    }
    $existing = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field->id]);
    if ($existing) {
        if (empty($existing->data)) {
            $existing->data = 'n/a';
            $DB->update_record('user_info_data', $existing);
        }
    } else {
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id, 'fieldid' => $field->id, 'data' => 'n/a', 'dataformat' => 0,
        ]);
    }
}
say('   perfil de servicio completado (evita usernotfullysetup en los Web Services)');

// ── 3. Rol acotado ──────────────────────────────────────────────────────────
say('3. Rol acotado');
$syscontext = context_system::instance();
$roleid = $DB->get_field('role', 'id', ['shortname' => $ROLESHORT]);
if (!$roleid) {
    $roleid = create_role(
        'Broncano — academy-service',
        $ROLESHORT,
        'Crear y matricular alumnos desde NocoDB. Nada más.'
    );
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    say("   rol creado (id={$roleid})");
} else {
    say("   rol ya existía (id={$roleid})");
}

foreach ($CAPS as $cap) {
    assign_capability($cap, CAP_ALLOW, $roleid, $syscontext->id, true);
}
role_assign($roleid, $user->id, $syscontext->id);
say('   permisos asignados: ' . count($CAPS));

// Tener la capacidad moodle/role:assign no basta: Moodle exige además declarar
// QUÉ roles puede otorgar este rol (tabla role_allow_assign). Sin esto,
// enrol_manual_enrol_users falla con `wsusercannotassign` al intentar poner el
// rol de estudiante. Se permite que broncanows asigne el rol student.
$studentroleid = (int) (getenv('MOODLE_STUDENT_ROLE_ID') ?: 5);
core_role_set_assign_allowed($roleid, $studentroleid);
say("   broncanows habilitado para asignar el rol student ({$studentroleid})");

// ── 4. Servicio externo ─────────────────────────────────────────────────────
say('4. Servicio externo');
$manager = new webservice();
$service = $DB->get_record('external_services', ['shortname' => $SHORTNAME]);
if (!$service) {
    $s = new stdClass();
    $s->name            = 'Broncano academy-service';
    $s->shortname       = $SHORTNAME;
    $s->enabled         = 1;
    // Sólo la cuenta de servicio puede usarlo.
    $s->restrictedusers = 1;
    $s->downloadfiles   = 0;
    $s->uploadfiles     = 0;
    $s->timecreated     = time();
    $s->id = $DB->insert_record('external_services', $s);
    $service = $DB->get_record('external_services', ['id' => $s->id]);
    say("   servicio creado (id={$service->id})");
} else {
    say("   servicio ya existía (id={$service->id})");
}

foreach ($FUNCTIONS as $fn) {
    $exists = $DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname'      => $fn,
    ]);
    if (!$exists) {
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname'      => $fn,
        ]);
    }
}
say('   funciones expuestas: ' . implode(', ', $FUNCTIONS));

$authorised = $DB->record_exists('external_services_users', [
    'externalserviceid' => $service->id,
    'userid'            => $user->id,
]);
if (!$authorised) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid'            => $user->id,
        'timecreated'       => time(),
    ]);
    say('   cuenta autorizada en el servicio');
}

// ── 5. Token ────────────────────────────────────────────────────────────────
say('5. Token');
$existing = $DB->get_record('external_tokens', [
    'userid'            => $user->id,
    'externalserviceid' => $service->id,
    'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
]);

if ($existing) {
    $token = $existing->token;
    say('   reutilizando el token existente');
} else {
    // El nombre de la función cambió en Moodle 4.x; se admiten ambos.
    if (class_exists('\core_external\util') && method_exists('\core_external\util', 'generate_token')) {
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $syscontext
        );
    } else {
        $token = external_generate_token(
            EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $syscontext
        );
    }
    say('   token nuevo generado');
}

say('Listo. El token sale por stdout (no se registra en ningún sitio).');

// Única salida por stdout.
echo $token . "\n";
