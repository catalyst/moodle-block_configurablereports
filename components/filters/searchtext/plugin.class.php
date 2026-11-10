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
 * Configurable Reports a Moodle block for creating customizable reports
 *
 * @copyright  2020 Juan Leyva <juan@moodle.com>
 * @package    block_configurable_reports
 * @author     Juan leyva <http://www.twitter.com/jleyvadelgado>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class plugin_searchtext
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_searchtext extends plugin_base {
    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->form = true;
        $this->unique = false;
        $this->fullname = get_string('filter_searchtext', 'block_configurable_reports');
        $this->reporttypes = ['searchtext', 'sql'];
    }

    /**
     * Summary
     *
     * @param object $data
     * @return string
     */
    public function summary(object $data): string {
        return empty($data->idnumber) ? get_string('filter_searchtext_summary', 'block_configurable_reports') : $data->idnumber;
    }

    /**
     * Execute
     *
     * @param string $finalelements
     * @param object $data
     * @return string|array
     */
    public function execute($finalelements, $data) {
        if ($this->report->type === 'sql') {
            // The SQL filter needs bound parameters, see execute_for_sql_report().
            throw new coding_exception('SQL reports must use execute_for_sql_report()');
        }

        return [$this->get_submitted_value($data)];
    }

    /**
     * Applies the filter to an SQL report query, binding the submitted value as a parameter.
     *
     * @param string $sql
     * @param object|null $data
     * @return array [SQL, DML parameters]
     */
    public function execute_for_sql_report(string $sql, ?object $data = null): array {
        $filtersearchtext = $this->get_submitted_value($data);

        if ($filtersearchtext === '') {
            return [$sql, []];
        }

        // For backwards compatibility and filters without idnumber, includes old method of matching without idnumber.
        $filtermatch = !empty($data->idnumber) ? "FILTER_SEARCHTEXT_{$data->idnumber}" : 'FILTER_SEARCHTEXT';

        return $this->apply_text_filter($sql, $filtermatch, $filtersearchtext);
    }

    /**
     * Reads the text the viewer submitted.
     *
     * @param object|null $data
     * @return string
     */
    private function get_submitted_value(?object $data): string {
        // For backwards compatibility and filters without idnumber, includes old method of matching without idnumber.
        if (!empty($data->idnumber)) {
            return (string) optional_param('filter_searchtext_' . $data->idnumber, '', PARAM_RAW);
        }

        return (string) optional_param('filter_searchtext', '', PARAM_RAW);
    }

    /**
     * Print filter
     *
     * @param MoodleQuickForm $mform
     * @param bool|object $formdata
     * @return void
     */
    public function print_filter(MoodleQuickForm $mform, $formdata = false): void {

        // For backwards compatibility and filters without idnumber, includes old method of matching without idnumber.
        if (!empty($formdata->idnumber)) {
            $filtername = 'filter_searchtext_' . $formdata->idnumber;
        } else {
            $filtername = 'filter_searchtext';
        }
        if (isset($formdata->label)) {
            $filterlabel = $formdata->label;
        } else {
            $filterlabel = get_string('filter', 'block_configurable_reports');
        }
        $filtersearchtext = optional_param($filtername, '', PARAM_RAW);
        $mform->addElement('text', $filtername, $filterlabel);
        $mform->setType($filtername, PARAM_RAW);
        $mform->setDefault($filtername, $filtersearchtext);
    }
}
