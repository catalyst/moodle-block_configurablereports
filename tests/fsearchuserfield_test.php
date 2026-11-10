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

namespace block_configurable_reports;

/**
 * Regression tests for user-field filters in SQL reports.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Monash University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plugin_fsearchuserfield
 * @covers     \report_sql
 */
final class fsearchuserfield_test extends \advanced_testcase {
    /**
     * Submitted filter values must not change the report's SQL restrictions.
     *
     * @dataProvider filter_values_provider
     * @param string $value Decoded value submitted to the filter.
     * @param bool $matches Whether the fixture user should match.
     */
    public function test_sql_filter_preserves_report_restrictions(string $value, bool $matches): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/reports/sql/report.class.php');

        $this->resetAfterTest();
        $originalget = $_GET;
        $originalpost = $_POST;

        try {
            $target = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
            $viewer = $this->getDataGenerator()->create_user();
            $context = \context_course::instance(SITEID);
            $roleid = create_role('Report viewer', 'reportviewer', '');
            assign_capability('block/configurable_reports:viewreports', CAP_ALLOW, $roleid, $context->id);
            role_assign($roleid, $viewer->id, $context->id);
            $this->setUser($viewer);

            $components = [
                'customsql' => [
                    'config' => (object) [
                        'querysql' => 'SELECT u.id, u.firstname FROM {user} u WHERE u.id = ' . $target->id
                            . ' %%FILTER_USERS:u.firstname%%',
                    ],
                ],
                'filters' => [
                    'elements' => [
                        [
                            'pluginname' => 'fsearchuserfield',
                            'formdata' => (object) ['field' => 'firstname'],
                        ],
                    ],
                ],
            ];
            $config = (object) [
                'courseid' => SITEID,
                'ownerid' => get_admin()->id,
                'visible' => 1,
                'name' => 'User-field filter regression',
                'type' => 'sql',
                'components' => cr_serialize($components),
                'remote' => 0,
            ];
            $config->id = $DB->insert_record('block_configurable_reports', $config);
            $report = new \report_sql($config);
            $report->set_forexport(true);

            $this->assertFalse(has_capability('block/configurable_reports:managereports', $context));
            $this->assertFalse(has_capability('block/configurable_reports:manageownreports', $context));
            $this->assertTrue($report->check_permissions($viewer->id, $context));

            $_GET = ['filter_fuserfield_firstname' => base64_encode($value)];
            $_POST = [];
            $this->assertTrue($report->create_report());
            $ids = array_map('intval', array_column($report->finalreport->table->data, 0));

            $this->assertSame(
                $matches ? [(int) $target->id] : [],
                $ids,
                'The filter must treat the submitted value as data, not broaden the report WHERE clause.'
            );
        } finally {
            $_GET = $originalget;
            $_POST = $originalpost;
        }
    }

    /**
     * Filter parameters must be bound alongside dynamic SQL parameters.
     */
    public function test_filter_parameters_are_merged_with_dynamic_parameters(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/reports/sql/report.class.php');

        $this->resetAfterTest();
        $originalget = $_GET;

        try {
            $target = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
            $components = [
                'customsql' => [
                    'config' => (object) [
                        'querysql' => 'SELECT u.id FROM {user} u WHERE u.id = :blockdynamic0 %%FILTER_USERS:u.firstname%%',
                    ],
                ],
                'filters' => [
                    'elements' => [
                        [
                            'pluginname' => 'fsearchuserfield',
                            'formdata' => (object) ['field' => 'firstname'],
                        ],
                    ],
                ],
            ];
            $config = (object) [
                'courseid' => SITEID,
                'ownerid' => get_admin()->id,
                'visible' => 1,
                'name' => 'User-field filter with dynamic parameters',
                'type' => 'sql',
                'components' => cr_serialize($components),
                'remote' => 0,
            ];
            $config->id = $DB->insert_record('block_configurable_reports', $config);
            $report = new \block_configurable_reports\local\dynamic_report($config);
            $report->set_query_parameters(['blockdynamic0' => $target->id]);

            $_GET = ['filter_fuserfield_firstname' => base64_encode('lic')];
            $this->assertTrue($report->create_report());
            $this->assertSame([(int) $target->id], array_map('intval', array_column($report->finalreport->table->data, 0)));
        } finally {
            $_GET = $originalget;
        }
    }

    /**
     * Filter controls and an attempted SQL predicate breakout.
     *
     * @return array
     */
    public static function filter_values_provider(): array {
        return [
            'matching substring' => ['lic', true],
            'non-matching value' => ['NoSuchFirstname', false],
            'SQL predicate breakout' => ["NoSuchFirstname' OR 1=1 -- ", false],
        ];
    }
}
