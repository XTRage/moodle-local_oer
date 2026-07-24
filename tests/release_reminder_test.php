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
 * Tests release reminder handling.
 *
 * @package    local_oer
 * @copyright  2026 Educational Technologies, Graz, University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oer;

use local_oer\userlist\userlist;

/**
 * Class release_reminder_test
 *
 * @coversDefaultClass \local_oer\release_reminder
 */
final class release_reminder_test extends \advanced_testcase {
    /**
     * Set up test defaults.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once(__DIR__ . '/helper/testcourse.php');

        set_config(release_reminder::CONF_ENABLED, 1, 'local_oer');
        set_config(release_reminder::CONF_DAYSBEFORE, 1, 'local_oer');
        set_config(release_reminder::CONF_SUBJECT, 'Release {coursename} {releasedate}', 'local_oer');
        set_config(
            release_reminder::CONF_BODY,
            'Hello {firstname} {lastname}' . "\n" . '{courselink}' . "\n" . '{files}',
            'local_oer'
        );
        set_config(release_reminder::CONF_LASTSENT, 0, 'local_oer');
        set_config(\local_oer\time\time_settings::RELEASETIME, time() + HOURSECS, 'local_oer');
    }

    /**
     * Test that due reminders are sent to all allowed users with the same course file list.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_to_allowed_users(): void {
        [$course, $titles] = $this->create_release_course();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $otheruser = $this->getDataGenerator()->create_user(['firstname' => 'Grace']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($otheruser->id, $course->id, 'editingteacher');

        set_config('allowedlist', 1, 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);
        $this->add_userlist_entry($otheruser->id, userlist::TYPE_A);

        $sink = $this->redirectEmails();
        $this->assertEquals(2, release_reminder::send_if_due());
        $emails = $sink->get_messages();

        $this->assertCount(2, $emails);
        $bodies = '';
        foreach ($emails as $email) {
            $this->assertStringContainsString($course->fullname, $email->subject);
            $this->assertStringContainsString($titles[0], $email->body);
            $bodies .= $email->body . "\n";
        }
        $this->assertStringContainsString('Lovelace', $bodies);
        $this->assertStringContainsString('Grace', $bodies);

        $sink = $this->redirectEmails();
        $this->assertEquals(0, release_reminder::send_if_due());
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * Test disallowance list mode sends to users with files except blocked users.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_with_disallowance_list(): void {
        [$course, $titles] = $this->create_release_course(2);
        $alloweduser = $this->getDataGenerator()->create_user();
        $blockeduser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alloweduser->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($blockeduser->id, $course->id, 'editingteacher');

        set_config('allowedlist', 0, 'local_oer');
        $this->add_userlist_entry($blockeduser->id, userlist::TYPE_D);
        $this->set_release_file_users($course->id, [$alloweduser->id, $blockeduser->id]);

        $sink = $this->redirectEmails();
        $this->assertEquals(1, release_reminder::send_if_due());
        $emails = $sink->get_messages();

        $this->assertCount(1, $emails);
        $this->assertStringContainsString($titles[0], $emails[0]->body);
        $this->assertStringContainsString($titles[1], $emails[0]->body);
    }

    /**
     * Test reminders are sent per course to all recipients.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_sends_one_email_per_course(): void {
        [$course1, $titles1] = $this->create_release_course();
        [$course2, $titles2] = $this->create_release_course();
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($otheruser->id, $course2->id, 'editingteacher');

        set_config('allowedlist', 1, 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);
        $this->add_userlist_entry($otheruser->id, userlist::TYPE_A);

        $sink = $this->redirectEmails();
        $this->assertEquals(2, release_reminder::send_if_due());
        $emails = $sink->get_messages();

        $this->assertCount(2, $emails);
        $emailsbyrecipient = [];
        foreach ($emails as $email) {
            $emailsbyrecipient[$email->to] = $email;
        }

        $this->assertArrayHasKey($user->email, $emailsbyrecipient);
        $this->assertArrayHasKey($otheruser->email, $emailsbyrecipient);
        $this->assertStringContainsString($course1->fullname, $emailsbyrecipient[$user->email]->subject);
        $this->assertStringContainsString($titles1[0], $emailsbyrecipient[$user->email]->body);
        $this->assertStringNotContainsString($course2->fullname, $emailsbyrecipient[$user->email]->subject);
        $this->assertStringNotContainsString($titles2[0], $emailsbyrecipient[$user->email]->body);

        $this->assertStringContainsString($course2->fullname, $emailsbyrecipient[$otheruser->email]->subject);
        $this->assertStringContainsString($titles2[0], $emailsbyrecipient[$otheruser->email]->body);
        $this->assertStringNotContainsString($course1->fullname, $emailsbyrecipient[$otheruser->email]->subject);
        $this->assertStringNotContainsString($titles1[0], $emailsbyrecipient[$otheruser->email]->body);
    }

    /**
     * Test files marked for release are included even when required metadata is incomplete.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_includes_incomplete_release_metadata(): void {
        [$course, $titles] = $this->create_release_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');

        set_config('allowedlist', 1, 'local_oer');
        set_config('requiredfields', 'description', 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);

        $sink = $this->redirectEmails();
        $this->assertEquals(1, release_reminder::send_if_due());
        $emails = $sink->get_messages();

        $this->assertCount(1, $emails);
        $this->assertStringContainsString($titles[0], $emails[0]->body);
    }

    /**
     * Test reminder waits until configured send time.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_waits_until_send_time(): void {
        [$course] = $this->create_release_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');

        set_config('allowedlist', 1, 'local_oer');
        set_config(release_reminder::CONF_DAYSBEFORE, 7, 'local_oer');
        set_config(\local_oer\time\time_settings::RELEASETIME, time() + (8 * DAYSECS), 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);

        $sink = $this->redirectEmails();
        $this->assertEquals(0, release_reminder::send_if_due());
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * Test a release window is not marked as sent when no email was sent.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_does_not_set_lastsent_without_emails(): void {
        $user = $this->getDataGenerator()->create_user();

        set_config('allowedlist', 1, 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);

        $sink = $this->redirectEmails();
        $this->assertEquals(0, release_reminder::send_if_due());
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals(0, get_config('local_oer', release_reminder::CONF_LASTSENT));
    }

    /**
     * Test stale metadata rows for deleted files are ignored.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @covers ::send_if_due
     */
    public function test_send_if_due_ignores_stale_release_rows(): void {
        $helper = new testcourse();
        $course = $this->getDataGenerator()->create_course();
        $helper->sync_course_info($course->id);
        $user = $this->getDataGenerator()->create_user();

        set_config('allowedlist', 1, 'local_oer');
        $this->add_userlist_entry($user->id, userlist::TYPE_A);
        $this->add_stale_release_element($course->id, $user->id, 'Stale file');

        $sink = $this->redirectEmails();
        $this->assertEquals(0, release_reminder::send_if_due());
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals(0, get_config('local_oer', release_reminder::CONF_LASTSENT));
    }

    /**
     * Add a user list entry.
     *
     * @param int $userid User id
     * @param string $type List type
     * @return void
     * @throws \dml_exception
     */
    private function add_userlist_entry(int $userid, string $type): void {
        global $DB, $USER;

        $entry = new \stdClass();
        $entry->userid = $userid;
        $entry->type = $type;
        $entry->timecreated = time();
        $entry->timemodified = time();
        $entry->usermodified = $USER->id;
        $DB->insert_record('local_oer_userlist', $entry);
    }

    /**
     * Create a course with files marked for release.
     *
     * @param int $amount Number of files to mark for release
     * @return array Course object and file titles
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private function create_release_course(int $amount = 1): array {
        $helper = new testcourse();
        $course = $helper->generate_testcourse($this->getDataGenerator());
        $helper->sync_course_info($course->id);
        $helper->set_files_to($course->id, $amount, true);

        return [$course, $this->get_release_file_titles($course->id)];
    }

    /**
     * Get titles of files marked for release in a course.
     *
     * @param int $courseid Course id
     * @return array
     * @throws \dml_exception
     */
    private function get_release_file_titles(int $courseid): array {
        global $DB;

        $records = $DB->get_records(
            'local_oer_elements',
            ['courseid' => $courseid, 'releasestate' => 1],
            'title ASC',
            'id,title'
        );

        return array_map(static function ($record) {
            return $record->title;
        }, array_values($records));
    }

    /**
     * Assign usermodified values to release file records in deterministic order.
     *
     * @param int $courseid Course id
     * @param array $userids User ids
     * @return void
     * @throws \dml_exception
     */
    private function set_release_file_users(int $courseid, array $userids): void {
        global $DB;

        $records = $DB->get_records(
            'local_oer_elements',
            ['courseid' => $courseid, 'releasestate' => 1],
            'id ASC'
        );
        foreach (array_values($records) as $key => $record) {
            if (!isset($userids[$key])) {
                break;
            }
            $record->usermodified = $userids[$key];
            $DB->update_record('local_oer_elements', $record);
        }
    }

    /**
     * Add a release-marked metadata row that is not backed by a current course file.
     *
     * @param int $courseid Course id
     * @param int $userid User id
     * @param string $title File title
     * @return void
     * @throws \dml_exception
     */
    private function add_stale_release_element(int $courseid, int $userid, string $title): void {
        global $DB;

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->identifier = 'test:release-reminder:stale';
        $record->type = 1;
        $record->title = $title;
        $record->description = '';
        $record->context = 1;
        $record->license = 'cc-4.0';
        $record->persons = '{"persons":[{"role":"Author","lastname":"Test","firstname":"User"}]}';
        $record->tags = '';
        $record->language = 'en';
        $record->resourcetype = 1;
        $record->classification = '';
        $record->releasestate = 1;
        $record->usermodified = $userid;
        $record->timecreated = time();
        $record->timemodified = time();

        $DB->insert_record('local_oer_elements', $record);
    }
}
