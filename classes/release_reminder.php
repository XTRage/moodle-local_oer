<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Release reminder handling.
 *
 * @package    local_oer
 * @copyright  2026 Educational Technologies, Graz, University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oer;

use local_oer\helper\activecourse;
use local_oer\time\time_settings;
use local_oer\userlist\userlist;

/**
 * Sends configured emails for upcoming OER releases.
 */
class release_reminder {
    /**
     * Enable release reminder emails.
     */
    const CONF_ENABLED = 'releasereminder_enabled';

    /**
     * Days before release when reminder should be sent.
     */
    const CONF_DAYSBEFORE = 'releasereminder_daysbefore';

    /**
     * Configured reminder subject.
     */
    const CONF_SUBJECT = 'releasereminder_subject';

    /**
     * Configured reminder body.
     */
    const CONF_BODY = 'releasereminder_body';

    /**
     * Last release timestamp for which reminders have been sent.
     */
    const CONF_LASTSENT = 'releasereminder_lastsent';

    /**
     * Sends emails if the configured release window is due.
     *
     * @return int Number of sent emails
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function send_if_due(): int {
        $settings = self::get_settings();
        if ($settings === null) {
            return 0;
        }

        $releasetime = (int) get_config('local_oer', time_settings::RELEASETIME);
        if (empty($releasetime) || (int) get_config('local_oer', self::CONF_LASTSENT) >= $releasetime) {
            return 0;
        }

        $sendtime = $releasetime - ((int) $settings['daysbefore'] * DAYSECS);
        if (time() < $sendtime) {
            return 0;
        }

        $sent = self::send($releasetime, $settings['subject'], $settings['body']);
        if ($sent === 0) {
            return 0;
        }

        set_config(self::CONF_LASTSENT, $releasetime, 'local_oer');
        logger::add(
            0,
            logger::LOGSUCCESS,
            'Sent ' . $sent . ' OER release email(s) for release window ' . userdate($releasetime) . '.'
        );

        return $sent;
    }

    /**
     * Return configured email settings, or null if disabled/incomplete.
     *
     * @return array|null
     * @throws \dml_exception
     */
    private static function get_settings(): ?array {
        if (!get_config('local_oer', self::CONF_ENABLED)) {
            return null;
        }

        $subject = get_config('local_oer', self::CONF_SUBJECT);
        $body = get_config('local_oer', self::CONF_BODY);
        if (empty($subject) || empty($body)) {
            return null;
        }

        return [
            'daysbefore' => (int) get_config('local_oer', self::CONF_DAYSBEFORE),
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Send release email to all recipients for each course with files marked for release.
     *
     * @param int $releasetime Release timestamp
     * @param string $subject Subject template
     * @param string $body Body template
     * @return int Number of sent emails
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private static function send(int $releasetime, string $subject, string $body): int {
        $sent = 0;
        $recipients = self::get_recipients();
        foreach (self::get_courses_with_files_for_release() as $course) {
            $files = self::get_files_for_course($course->id);
            if (empty($files)) {
                continue;
            }

            foreach ($recipients as $user) {
                if (is_siteadmin($user->id)) {
                    continue;
                }

                if (self::send_email($user, $course, $files, $releasetime, $subject, $body)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Get reminder recipients depending on the active allowance/disallowance list mode.
     *
     * @return \stdClass[]
     * @throws \dml_exception
     */
    private static function get_recipients(): array {
        global $DB;

        if (get_config('local_oer', 'allowedlist') == '1') {
            $sql = "SELECT DISTINCT u.*
                      FROM {user} u
                      JOIN {local_oer_userlist} ul
                        ON ul.userid = u.id AND ul.type = :listtype
                     WHERE u.deleted = 0 AND u.suspended = 0
                  ORDER BY u.lastname ASC, u.firstname ASC";
            return $DB->get_records_sql($sql, [
                'listtype' => userlist::TYPE_A,
            ]);
        }

        $sql = "SELECT DISTINCT u.*
                  FROM {user} u
                  JOIN {local_oer_elements} e
                    ON e.usermodified = u.id AND e.releasestate = :releasestate
             LEFT JOIN {local_oer_userlist} ul
                    ON ul.userid = u.id AND ul.type = :listtype
                 WHERE u.deleted = 0 AND u.suspended = 0 AND ul.id IS NULL
              ORDER BY u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, [
            'listtype' => userlist::TYPE_D,
            'releasestate' => 1,
        ]);
    }

    /**
     * Return courses with files marked for release.
     *
     * @return \stdClass[]
     * @throws \dml_exception
     */
    private static function get_courses_with_files_for_release(): array {
        global $DB;

        $courses = [];
        foreach (activecourse::get_list_of_courses() as $activecourse) {
            if (
                !$DB->record_exists('local_oer_courseinfo', [
                    'courseid' => $activecourse->courseid,
                    'ignored' => 0,
                    'deleted' => 0,
                ])
            ) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $activecourse->courseid], 'id,fullname');
            if ($course) {
                $courses[] = $course;
            }
        }

        return $courses;
    }

    /**
     * Return current course files that are marked for release.
     *
     * @param int $courseid Moodle course id
     * @return \stdClass[]
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private static function get_files_for_course(int $courseid): array {
        $files = [];
        foreach (filelist::get_course_files($courseid) as $element) {
            if (!$element->already_stored()) {
                continue;
            }

            $metadata = $element->get_stored_metadata();
            if ((int) $metadata->releasestate !== 1) {
                continue;
            }

            $file = new \stdClass();
            $file->title = $element->get_title();
            $files[] = $file;
        }

        usort($files, static function ($a, $b) {
            return strcasecmp($a->title, $b->title);
        });

        return $files;
    }

    /**
     * Send an email with all placeholders replaced.
     *
     * @param \stdClass $user Moodle user object
     * @param \stdClass $course Moodle course object
     * @param array $files Files marked for release in this course, each item is a stdClass
     * @param int $releasetime Release timestamp
     * @param string $subject Subject template
     * @param string $body Body template
     * @return bool
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    private static function send_email(
        \stdClass $user,
        \stdClass $course,
        array $files,
        int $releasetime,
        string $subject,
        string $body
    ): bool {
        $replacements = self::get_replacements($user, $course, $files, $releasetime);
        $emailsubject = format_string(strtr($subject, $replacements['subject']));
        $emailbody = format_text(strtr($body, $replacements['body']), FORMAT_HTML, ['noclean' => true]);
        $emailbodyhtml = text_to_html($emailbody, false, false);

        return email_to_user($user, \core_user::get_support_user(), $emailsubject, $emailbody, $emailbodyhtml);
    }

    /**
     * Prepare placeholder replacements.
     *
     * @param \stdClass $user Moodle user object
     * @param \stdClass $course Moodle course object
     * @param array $files Files marked for release, each item is a stdClass
     * @param int $releasetime Release timestamp
     * @return array
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    private static function get_replacements(
        \stdClass $user,
        \stdClass $course,
        array $files,
        int $releasetime
    ): array {
        $courselink = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);

        return [
            'subject' => [
                '{coursename}' => format_string($course->fullname),
                '{releasedate}' => userdate($releasetime),
            ],
            'body' => [
                '{firstname}' => $user->firstname,
                '{lastname}' => $user->lastname,
                '{coursename}' => format_string($course->fullname),
                '{courselink}' => format_string($courselink),
                '{files}' => self::format_file_list($files),
                '{releasedate}' => userdate($releasetime),
            ],
        ];
    }

    /**
     * Format files as a readable plain-text list.
     *
     * @param array $files Files marked for release, each item is a stdClass
     * @return string
     */
    private static function format_file_list(array $files): string {
        $lines = [];

        foreach ($files as $file) {
            $lines[] = '* ' . $file->title;
        }

        return implode("\n", $lines);
    }
}
