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
 * Tests for the site context handling of the report helpers
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use context_course;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');

/**
 * SITEID comes from the site record, so it is a string on MySQL and PostgreSQL: comparing it with an integer course id
 * has to be loose, as core does.
 *
 * @package    block_configurable_reports
 * @covers     ::cr_get_my_reports
 * @covers     ::cr_get_report_plugins
 */
#[CoversFunction('cr_get_my_reports')]
#[CoversFunction('cr_get_report_plugins')]
final class locallib_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a report in a course.
     *
     * @param int $courseid
     * @return int The report id.
     */
    private function create_report(int $courseid): int {
        global $DB;

        return $DB->insert_record('block_configurable_reports', (object) [
            'courseid' => $courseid,
            'ownerid' => get_admin()->id,
            'visible' => 1,
            'name' => 'Report ' . $courseid,
            'summary' => '',
            'type' => 'users',
            'components' => cr_serialize([]),
        ]);
    }

    /**
     * Managing reports on the site gives the reports of every course.
     */
    public function test_site_managers_get_the_reports_of_every_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $reportid = $this->create_report($course->id);

        $reports = cr_get_my_reports((int) SITEID, get_admin()->id);

        $this->assertArrayHasKey($reportid, $reports);
    }

    /**
     * Site reports are managed in the system context, not in the context of the front page course.
     */
    public function test_report_types_of_the_site_are_checked_in_the_system_context(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('SQL manager', 'sqlmanager', '');
        assign_capability('block/configurable_reports:managesqlreports', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, context_course::instance(SITEID)->id);
        $this->setUser($user);

        $this->assertArrayNotHasKey('sql', cr_get_report_plugins((int) SITEID));

        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertArrayHasKey('sql', cr_get_report_plugins((int) SITEID));
    }
}
