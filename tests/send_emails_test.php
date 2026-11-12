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
 * Tests for the send emails button of report tables
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');

/**
 * Emailing the users of a report is bulk messaging, which needs its own capability.
 *
 * @package    block_configurable_reports
 * @covers     ::cr_print_table
 */
#[CoversFunction('cr_print_table')]
final class send_emails_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A report table with a column to select users.
     *
     * @return \stdClass
     */
    private function create_table(): \stdClass {
        $table = new \stdClass();
        $table->head = ['Name', 'sendemail'];
        $table->data = [['Someone', '5']];

        return $table;
    }

    /**
     * Users with the capability get the button, in the current course.
     */
    public function test_the_button_needs_the_bulk_messaging_capability(): void {
        global $COURSE;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $COURSE = get_course($course->id);

        $this->setUser($teacher);
        $this->assertStringContainsString('type="submit"', cr_print_table($this->create_table(), true));

        $this->setUser($student);
        $this->assertStringNotContainsString('type="submit"', cr_print_table($this->create_table(), true));
    }

    /**
     * In a site report the capability is checked in the system context.
     */
    public function test_the_button_in_the_site_context(): void {
        global $COURSE, $SITE;

        $COURSE = $SITE;

        $this->setAdminUser();
        $this->assertStringContainsString('type="submit"', cr_print_table($this->create_table(), true));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertStringNotContainsString('type="submit"', cr_print_table($this->create_table(), true));
    }
}
