<?php
/**
 * @package    local_broncano
 * @copyright  2024 Broncano Project
 */

defined('MOODLE_INTERNAL') || die();

class local_broncano_observer {

    /**
     * Triggered when a user is enrolled in a course.
     * Sends data to the academy-service webhook.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolled(\core\event\user_enrolment_created $event) {
        global $DB, $CFG;

        $userid = $event->relateduserid;
        $courseid = $event->courseid;

        // Get user and course info
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Fetch custom profile fields (cedula_pdf and foto_carnet)
        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_load_custom_fields($user);

        // Construct payload for the academy-service
        $payload = [
            'secret' => get_config('local_broncano', 'webhook_secret') ?: '',
            'event' => 'user_enrolled',
            'userid' => $userid,
            'courseid' => $courseid,
            'username' => fullname($user),
            'useremail' => $user->email,
            'coursename' => $course->fullname,
            // Custom fields added via profilefield_file plugin
            'profile_field_cedula_pdf' => self::get_file_url($user, 'cedula_pdf'),
            'profile_field_foto_carnet' => self::get_file_url($user, 'foto_carnet'),
        ];

        // Send to academy-service (Internal Docker URL)
        $url = "http://academy-service:3002/moodle/webhook";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            debugging("local_broncano: Failed to send webhook to $url. HTTP Code: $http_code. Resp: $response", DEBUG_DEVELOPER);
        }
    }

    /**
     * Helper to get the public URL for a profile field file.
     */
    private static function get_file_url($user, $shortname) {
        global $CFG, $DB;

        // Find the field ID
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) return '';

        // Find the data for this user/field
        $data = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field->id]);
        if (!$data || empty($data->data)) return '';

        // The profilefield_file plugin stores the filename in the 'data' column.
        // The file itself is stored in the user context, component 'profilefield_file', filearea 'content', itemid 0.
        $usercontext = context_user::instance($user->id);
        
        // We use pluginfile.php to generate a URL that academy-service can reach.
        // Since academy-service is internal, we use the $CFG->wwwroot (public URL).
        return $CFG->wwwroot . "/pluginfile.php/" . $usercontext->id . "/profilefield_file/content/0/" . $data->data;
    }
}
