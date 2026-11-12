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
 * Tests for the course field filter
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/reports/courses/report.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/components/filters/fcoursefield/plugin.class.php');

/**
 * The filter only offers the values of the courses the report selects.
 *
 * @package    block_configurable_reports
 * @covers     \plugin_fcoursefield
 */
#[CoversClass(\plugin_fcoursefield::class)]
final class fcoursefield_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The options of the filter for a report of the courses with an idnumber.
     *
     * @return string[] The text of the options, without the one for all the courses.
     */
    private function get_options(): array {
        global $DB, $SITE;

        $components = [
            'conditions' => [
                'elements' => [[
                    'id' => 'c1',
                    'pluginname' => 'ccoursefield',
                    'formdata' => (object) ['field' => 'idnumber', 'operator' => '=', 'value' => 'A&1'],
                ]],
            ],
        ];
        $report = (object) [
            'courseid' => $SITE->id,
            'ownerid' => get_admin()->id,
            'visible' => 1,
            'name' => 'Courses',
            'summary' => '',
            'type' => 'courses',
            'components' => cr_serialize($components),
            'remote' => 0,
        ];
        $report->id = $DB->insert_record('block_configurable_reports', $report);
        $report = $DB->get_record('block_configurable_reports', ['id' => $report->id]);

        // Building the report sets up the connection the filter reads from.
        new \report_courses($report);

        $mform = new \MoodleQuickForm('filters', 'post', '');
        (new \plugin_fcoursefield($report))->print_filter($mform, (object) ['field' => 'idnumber']);

        $options = array_column($mform->getElement('filter_fcoursefield_idnumber')->_options, 'text');

        return array_slice($options, 1);
    }

    /**
     * Values of courses the report does not select are not listed, and the ones listed are escaped.
     */
    public function test_only_the_values_of_the_report_courses_are_listed(): void {
        $this->getDataGenerator()->create_course(['idnumber' => 'A&1']);
        $this->getDataGenerator()->create_course(['idnumber' => 'Hidden']);

        $this->assertSame(['A&amp;1'], $this->get_options());
    }
}
