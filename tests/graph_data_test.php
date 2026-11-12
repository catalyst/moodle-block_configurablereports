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
 * Unit tests for the data of the report charts
 *
 * @package    block_configurable_reports
 * @copyright  2026 Juan Leyva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Tests for cr_get_graph_url() and cr_get_graph_data()
 *
 * @package    block_configurable_reports
 * @covers     ::cr_get_graph_url
 * @covers     ::cr_get_graph_data
 */
#[CoversFunction('cr_get_graph_url')]
#[CoversFunction('cr_get_graph_data')]
final class graph_data_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        unset($_GET['key']);
        parent::tearDown();
    }

    /**
     * Build the image URL of a chart, and make the request for the image.
     *
     * @param string $plot
     * @param object $formdata
     * @param array $rows
     * @return string The image URL.
     */
    private function chart_url(string $plot, object $formdata, array $rows): string {
        $classname = cr_require_component_plugin('plot', $plot);
        $plugin = new $classname((object) ['id' => 7, 'courseid' => SITEID, 'type' => 'sql']);
        $url = $plugin->execute('g1', $formdata, $rows);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $_GET['key'] = $params['key'];

        return $url;
    }

    /**
     * The image URL only names the chart data, which stays in the session for the image of that chart.
     */
    public function test_the_chart_data_stays_in_the_session(): void {
        $url = $this->chart_url('bar', (object) ['label_field' => '0,Name', 'value_fields' => ['1,Count']],
            [['Ada', 3], ['Bob', 5]]);

        $this->assertStringNotContainsString('Ada', $url);
        $this->assertStringNotContainsString('graphdata', $url);
        $this->assertSame(['Name' => ['Ada', 'Bob'], 'Count' => [3, 5]], cr_get_graph_data(7, 'g1'));

        // The key does not draw the data of another chart or report.
        $this->assertSame([], cr_get_graph_data(7, 'g2'));
        $this->assertSame([], cr_get_graph_data(8, 'g1'));

        // Nor the data of a key the session does not have.
        $_GET['key'] = '7_g1_unknown';
        $this->assertSame([], cr_get_graph_data(7, 'g1'));
    }

    /**
     * The line chart keeps its series and scale, and the pie chart its slices and colors.
     */
    public function test_line_and_pie_data(): void {
        $this->chart_url('line', (object) ['xaxis' => 1, 'yaxis' => 2, 'serieid' => 1], [['Ada', 3], ['Ada', 5], ['Bob', -1]]);
        $this->assertSame([
            'series' => [['serie' => [3, 5], 'name' => 'Ada'], ['serie' => [-1], 'name' => 'Bob']],
            'min' => -1,
            'max' => 5,
        ], cr_get_graph_data(7, 'g1'));

        $this->chart_url('pie', (object) ['areaname' => 0, 'areavalue' => 1, 'piechart_label' => ['Bob'],
            'piechart_label_color' => ['#ff0000']], [['Ada', 3], ['Bob', 5]]);
        $this->assertSame([
            'series' => [['Ada', 'Bob'], [3, 5]],
            'colors' => [1 => ['255', '0', '0']],
        ], cr_get_graph_data(7, 'g1'));
    }
}
