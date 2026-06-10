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

namespace block_configurable_reports\local;

/**
 * Runs a Configurable Reports SQL report with bound dynamic parameters.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Frank Saikali <fsjk85@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dynamic_report extends \report_sql {
    /** @var array DML query parameters. */
    private array $queryparams = [];

    /**
     * Sets the DML parameters for the next report execution.
     *
     * @param array $queryparams DML query parameters.
     */
    public function set_query_parameters(array $queryparams): void {
        $this->queryparams = $queryparams;
    }

    /**
     * Executes the report query with its dynamic DML parameters.
     *
     * @param string $sql SQL query.
     * @return mixed
     */
    public function execute_query($sql) {
        global $CFG, $DB, $remotedb;

        $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);
        $reportlimit = get_config('block_configurable_reports', 'reportlimit');
        if (empty($reportlimit) || $reportlimit === '0') {
            $reportlimit = BLOCK_CONFIGURABLE_REPORTS_MAX_RECORDS;
        }

        $starttime = microtime(true);
        $results = $remotedb->get_recordset_sql($sql, $this->queryparams, 0, $reportlimit);

        $updaterecord = $DB->get_record('block_configurable_reports', ['id' => $this->config->id]);
        $updaterecord->lastexecutiontime = round((microtime(true) - $starttime) * 1000);
        $this->config->lastexecutiontime = $updaterecord->lastexecutiontime;
        $DB->update_record('block_configurable_reports', $updaterecord);

        return $results;
    }
}
