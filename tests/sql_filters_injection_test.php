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
 * Regression tests for the request-driven filters of SQL reports: values must be bound, never concatenated.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Monash University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plugin_searchtext
 * @covers     \plugin_fuserfield
 * @covers     \plugin_semester
 * @covers     \plugin_yearhebrew
 * @covers     \plugin_base
 */
final class sql_filters_injection_test extends \advanced_testcase {
    /**
     * SQL reports run in a read only transaction of their own, so the test cannot be wrapped in one.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
    }

    /** @var string Value that would break out of a quoted literal and match every user. */
    private const PAYLOAD = "x' OR '1'='1";

    /**
     * Run an SQL report restricted to one user, with the given filter and request values.
     *
     * @param string $pluginname Filter plugin.
     * @param object $formdata Filter configuration.
     * @param string $placeholder Placeholder used in the query.
     * @param array $get Request parameters.
     * @param int $userid The one user the report is restricted to.
     * @param int $repeat How many times the placeholder is used in the query.
     * @return int[] User ids returned by the report.
     */
    private function run_report(
        string $pluginname,
        object $formdata,
        string $placeholder,
        array $get,
        int $userid,
        int $repeat = 1
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/reports/sql/report.class.php');

        $originalget = $_GET;
        try {
            $components = [
                'customsql' => [
                    'config' => (object) [
                        'querysql' => 'SELECT u.id FROM {user} u WHERE u.id = ' . $userid . str_repeat(' ' . $placeholder, $repeat),
                    ],
                ],
                'filters' => ['elements' => [['pluginname' => $pluginname, 'formdata' => $formdata]]],
            ];
            $config = (object) [
                'courseid' => SITEID,
                'ownerid' => get_admin()->id,
                'visible' => 1,
                'name' => 'Filter injection',
                'type' => 'sql',
                'components' => cr_serialize($components),
                'remote' => 0,
            ];
            $config->id = $DB->insert_record('block_configurable_reports', $config);
            $report = new \report_sql($config);
            $report->set_forexport(true);

            $_GET = $get;
            $this->assertTrue($report->create_report());

            return array_map('intval', array_column($report->finalreport->table->data, 0));
        } finally {
            $_GET = $originalget;
        }
    }

    /**
     * Provides each filter with a value that matches the user, and the same filter with an injection payload.
     *
     * @return array
     */
    public static function filters_provider(): array {
        return [
            'searchtext contains' => ['searchtext', [], '%%FILTER_SEARCHTEXT:u.firstname:~%%', 'filter_searchtext', 'lic'],
            'searchtext equals' => ['searchtext', [], '%%FILTER_SEARCHTEXT:u.firstname:=%%', 'filter_searchtext', 'Alice'],
            'searchtext in' => ['searchtext', [], '%%FILTER_SEARCHTEXT:u.firstname:in%%', 'filter_searchtext', 'Bob, Alice'],
            'searchtext idnumber' => [
                'searchtext', ['idnumber' => 'name'], '%%FILTER_SEARCHTEXT_name:u.firstname:~%%', 'filter_searchtext_name', 'Alice',
            ],
            'fuserfield' => [
                'fuserfield', ['field' => 'firstname'], '%%FILTER_USERS_firstname:u.firstname%%', 'filter_fuserfield_firstname',
                'Alice',
            ],
            'semester' => ['semester', [], '%%FILTER_SEMESTER:u.firstname%%', 'filter_semester', 'Alice'],
            'yearhebrew' => ['yearhebrew', [], '%%FILTER_YEARHEBREW:u.firstname%%', 'filter_yearhebrew', 'Alice'],
        ];
    }

    /**
     * A matching value keeps the user, a value that does not match removes it.
     *
     * @dataProvider filters_provider
     * @param string $plugin
     * @param array $formdata
     * @param string $placeholder
     * @param string $param
     * @param string $value A value that matches the fixture user.
     */
    public function test_filter_applies_the_value(string $plugin, array $formdata, string $placeholder, string $param, string $value): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);

        $encode = fn(string $v): string => $plugin === 'fuserfield' ? base64_encode($v) : $v;

        $this->assertSame(
            [(int) $user->id],
            $this->run_report($plugin, (object) $formdata, $placeholder, [$param => $encode($value)], $user->id)
        );
        $this->assertSame(
            [],
            $this->run_report($plugin, (object) $formdata, $placeholder, [$param => $encode('Zed')], $user->id)
        );
    }

    /**
     * A submitted value is data: it must not widen the report's WHERE clause.
     *
     * @dataProvider filters_provider
     * @param string $plugin
     * @param array $formdata
     * @param string $placeholder
     * @param string $param
     */
    public function test_filter_value_cannot_inject_sql(string $plugin, array $formdata, string $placeholder, string $param): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);

        $encode = fn(string $v): string => $plugin === 'fuserfield' ? base64_encode($v) : $v;

        foreach ([self::PAYLOAD, "x') OR 1=1 --", 'x UNION SELECT id FROM {user}'] as $payload) {
            $this->assertSame(
                [],
                $this->run_report($plugin, (object) $formdata, $placeholder, [$param => $encode($payload)], $user->id),
                'The value must be treated as data: ' . $payload
            );
        }
    }

    /**
     * Moodle does not allow repeating a named parameter, so a placeholder used twice still has to run.
     */
    public function test_placeholder_used_twice(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);

        $this->assertSame(
            [(int) $user->id],
            $this->run_report(
                'searchtext',
                (object) [],
                '%%FILTER_SEARCHTEXT:u.firstname:~%%',
                ['filter_searchtext' => 'Alice'],
                $user->id,
                2
            )
        );
    }

    /**
     * With no value submitted the placeholder is removed and the report is not narrowed.
     */
    public function test_no_value_leaves_the_report_unchanged(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);

        $this->assertSame(
            [(int) $user->id],
            $this->run_report('searchtext', (object) [], '%%FILTER_SEARCHTEXT:u.firstname:~%%', [], $user->id)
        );
    }
}
