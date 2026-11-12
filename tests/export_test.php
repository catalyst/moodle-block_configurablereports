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
 * Tests for the permissions of the report XML export
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use context_system;
use moodle_exception;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');

/**
 * The export file holds the query of a SQL report, so it needs the same capability as editing it.
 *
 * @package    block_configurable_reports
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class export_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a report of a type, in the site.
     *
     * @param string $type
     * @return \stdClass
     */
    private function create_report(string $type): \stdClass {
        global $DB, $SITE;

        $report = (object) [
            'courseid' => $SITE->id,
            'ownerid' => get_admin()->id,
            'visible' => 1,
            'name' => 'Exported',
            'summary' => '',
            'type' => $type,
            'components' => cr_serialize($type === 'sql' ? ['customsql' => ['config' => (object) ['querysql' => 'SELECT 1']]] : []),
        ];
        $report->id = $DB->insert_record('block_configurable_reports', $report);

        return $report;
    }

    /**
     * Give a user the capability to manage reports, but not SQL reports.
     *
     * @return \stdClass
     */
    private function create_report_manager(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/configurable_reports:managereports', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());

        return $user;
    }

    /**
     * Run export.php for a report.
     *
     * @param \stdClass $report
     */
    private function export(\stdClass $report): void {
        global $CFG, $DB, $USER, $PAGE;

        $_GET = $_REQUEST = ['id' => $report->id, 'sesskey' => sesskey()];

        // The script finds config.php relative to its own directory.
        $cwd = getcwd();
        chdir($CFG->dirroot . '/blocks/configurable_reports');
        try {
            require($CFG->dirroot . '/blocks/configurable_reports/export.php');
        } finally {
            chdir($cwd);
        }
    }

    /**
     * Managing reports is not enough to download the query of a SQL report.
     */
    public function test_sql_reports_need_the_sql_capability(): void {
        $this->setUser($this->create_report_manager());

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('nosqlpermissions', 'block_configurable_reports'));
        $this->export($this->create_report('sql'));
    }
}
