<?php
/**
 * Puente Moodle → academy-service.
 *
 * Dos eventos:
 *
 *   user_enrolment_created → el academy-service enlaza la cuenta de Moodle con
 *       la ficha del alumno en NocoDB (estampa moodle_user_id / moodle_course_id).
 *
 *   user_graded → la nota de cada test modular viaja a NocoDB. Sin esto la nota
 *       teórica había que teclearla a mano, y como graduarse exige ≥75%, nadie
 *       se graduaba solo. Este observador es el que cierra ese agujero.
 *
 * El módulo (A…J) se deduce de la SECCIÓN del curso donde vive el quiz, no de su
 * nombre: así los quizzes se pueden titular como se quiera, y un quiz suelto en
 * una sección que no sea un módulo (p. ej. el de pruebas `s343`) simplemente se
 * ignora. El examen final de suficiencia sí se reconoce por el nombre, porque es
 * el único que no pertenece a un módulo.
 *
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 */

defined('MOODLE_INTERNAL') || die();

class local_broncano_observer {

    /** URL interna del academy-service dentro de la red de Docker. */
    const WEBHOOK_URL = 'http://academy-service:3002/moodle/webhook';

    /**
     * Alumno matriculado → avisar para que se enlace con su ficha de NocoDB.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolled(\core\event\user_enrolment_created $event) {
        global $DB, $CFG;

        $userid = $event->relateduserid;
        $courseid = $event->courseid;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_load_custom_fields($user);

        self::post([
            'event' => 'user_enrolled',
            'userid' => $userid,
            'courseid' => $courseid,
            'username' => fullname($user),
            'useremail' => $user->email,
            'coursename' => $course->fullname,
            'profile_field_cedula_pdf' => self::get_file_url($user, 'cedula_pdf'),
            'profile_field_foto_carnet' => self::get_file_url($user, 'foto_carnet'),
        ]);
    }

    /**
     * Nota puesta a un alumno → mandarla a NocoDB.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event) {
        $grade = $event->get_grade();
        $item = $grade->load_grade_item();

        // Sólo actividades. El total del curso y los ítems manuales se ignoran:
        // la nota teórica la calcula NocoDB (70% módulos + 30% examen final),
        // no la reconstruimos aquí.
        if ($item->itemtype !== 'mod') {
            return;
        }

        // Sin nota todavía (el intento existe pero no está calificado).
        if ($grade->finalgrade === null || $item->grademax <= 0) {
            return;
        }

        $modulo = self::resolve_modulo($item);
        if ($modulo === null) {
            return; // No pertenece a ningún módulo evaluable: no es asunto nuestro.
        }

        $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);

        self::post([
            'event' => 'grade_updated',
            'userid' => $event->relateduserid,
            'courseid' => $item->courseid,
            'cmid' => $cm ? $cm->id : 0,
            'modulo' => $modulo,                       // "A"…"J" o "FINAL"
            'grade' => (float) $grade->finalgrade,
            'grademax' => (float) $item->grademax,
        ]);
    }

    /**
     * ¿A qué módulo pertenece esta calificación? Devuelve "A".."J", "FINAL", o
     * null si no es una evaluación que nos interese.
     */
    private static function resolve_modulo($item) {
        global $DB;

        // El examen final de suficiencia (30% de la nota) no vive en un módulo:
        // se identifica por su nombre.
        if (preg_match('/final|suficiencia/i', (string) $item->itemname)) {
            return 'FINAL';
        }

        $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
        if (!$cm) {
            return null;
        }

        $section = $DB->get_record('course_sections', ['id' => $cm->section]);
        if (!$section) {
            return null;
        }

        // "Módulo A" … "Módulo J". La K es el simulador y no lleva test.
        if (preg_match('/m[oó]dulo\s+([A-J])/iu', (string) $section->name, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * POST al academy-service. Un fallo aquí no debe romper la calificación ni
     * la matriculación del alumno: se registra y se sigue.
     */
    private static function post(array $payload) {
        $payload['secret'] = get_config('local_broncano', 'webhook_secret') ?: '';

        $ch = curl_init(self::WEBHOOK_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            debugging(
                "local_broncano: webhook '{$payload['event']}' falló. HTTP {$code}. Resp: {$response}",
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * URL pública de un fichero subido a un campo de perfil.
     */
    private static function get_file_url($user, $shortname) {
        global $CFG, $DB;

        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) {
            return '';
        }

        $data = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field->id]);
        if (!$data || empty($data->data)) {
            return '';
        }

        $usercontext = context_user::instance($user->id);

        return $CFG->wwwroot . "/pluginfile.php/" . $usercontext->id .
               "/profilefield_file/content/0/" . $data->data;
    }
}
