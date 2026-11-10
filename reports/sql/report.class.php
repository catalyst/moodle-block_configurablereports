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
defined('BLOCK_CONFIGURABLE_REPORTS_MAX_RECORDS') || define('BLOCK_CONFIGURABLE_REPORTS_MAX_RECORDS', 5000);

/**
 * Class report_sql
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class report_sql extends report_base {
    /**
     * @var bool
     */
    private bool $forexport = false;

    /**
     * @var array DML parameters bound by the report filters.
     */
    protected array $filterparams = [];

    /**
     * set_forexport
     *
     * @param bool $isforexport
     * @return void
     */
    public function set_forexport(bool $isforexport): void {
        $this->forexport = $isforexport;
    }

    /**
     * is_forexport
     *
     * @return bool
     */
    public function is_forexport(): bool {
        return $this->forexport;
    }

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->components = [
            'customsql',
            'filters',
            'template',
            'permissions',
            'calcs',
            'plot',
        ];
    }

    /**
     * Cast the %%FILTER_VAR%% value, so numbers are bound as numbers (needed in arithmetic on PostgreSQL).
     *
     * @param string $value
     * @return int|float|string
     */
    protected static function cast_filter_var(string $value) {
        $value = trim($value);
        if (preg_match('/^-?\d{1,18}$/', $value)) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * prepare_sql
     *
     * @param string $sql
     * @param int $forcedcourseid the course id to use instead of the current course
     * @return array|string|string[]
     */
    public function prepare_sql(string $sql, int $forcedcourseid = 0) {
        global $USER, $CFG, $COURSE, $DB;

        $course = $COURSE;

        // If a forced course id has been passed, use the forced course instead of the current course.
        if ($forcedcourseid) {
            $forcedcourse = $DB->get_record('course', ['id' => $forcedcourseid]);
            if ($forcedcourse) {
                $course = $forcedcourse;
            }
        }

        // Enable debug mode from SQL query.
        $this->config->debug = strpos($sql, '%%DEBUG%%') !== false;

        // %%FILTER_VAR%% is replaced with the filter_var URL parameter, always bound as a value, so it can be used
        // anywhere a value is valid: "WHERE cm.id = %%FILTER_VAR%%", "<= %%FILTER_VAR%% * 86400"...
        // Forget the values bound by a previous call, as the same report object prepares several queries.
        $this->filterparams = array_filter(
            $this->filterparams,
            fn($name) => !preg_match('/^filtervar\d+$/', (string) $name),
            ARRAY_FILTER_USE_KEY
        );
        $filtervar = trim(optional_param('filter_var', '', PARAM_RAW));
        $i = 0;
        $filterparams = &$this->filterparams;
        $sql = preg_replace_callback(
            '/%%FILTER_VAR%%/',
            function (array $match) use (&$i, &$filterparams, $filtervar, $sql): string {
                if ($filtervar === '') {
                    // Without a value, a placeholder used as a value is NULL, so the report shows no rows instead of
                    // failing. Anywhere else it is removed, as in reports that add a whole condition with it.
                    $before = substr($sql, 0, $match[0][1]);
                    return preg_match('/(?:[=<>!(,+\-*\/|]|\b(?:LIKE|IN|BETWEEN|THEN|ELSE|WHEN))\s*$/i', $before) ? 'NULL' : '';
                }
                $name = 'filtervar' . ++$i;
                $filterparams[$name] = self::cast_filter_var($filtervar);
                return ':' . $name;
            },
            $sql,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
        unset($filterparams);

        // See http://en.wikipedia.org/wiki/Year_2038_problem.
        $sql = str_replace(
            [
                '%%USERID%%',
                '%%COURSEID%%',
                '%%CATEGORYID%%',
                '%%STARTTIME%%',
                '%%ENDTIME%%',
                '%%WWWROOT%%',
            ],
            [$USER->id, $course->id, $course->category, '0', '2145938400', $CFG->wwwroot],
            $sql
        );
        $sql = preg_replace('/%{2}[^%]+%{2}/i', '', $sql);

        return str_replace('?', '[[QUESTIONMARK]]', $sql);
    }

    /**
     * execute_query
     *
     * @param string $sql
     * @return mixed
     */
    public function execute_query($sql) {
        global $remotedb, $DB, $CFG;

        $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);

        $reportlimit = get_config('block_configurable_reports', 'reportlimit');
        if (empty($reportlimit) || $reportlimit == '0') {
            $reportlimit = BLOCK_CONFIGURABLE_REPORTS_MAX_RECORDS;
        }

        $starttime = microtime(true);

        $params = $this->filterparams ?: null;

        if (preg_match('/\b(INSERT|INTO|CREATE)\b/i', $sql) && !empty($CFG->block_configurable_reports_enable_sql_execution)) {
            // Run special (dangerous) queries directly.
            $results = $remotedb->execute($sql, $params);
        } else {
            $results = $remotedb->get_recordset_sql($sql, $params, 0, $reportlimit);
        }

        // Update the execution time in the DB.
        $updaterecord = $DB->get_record('block_configurable_reports', ['id' => $this->config->id]);
        $updaterecord->lastexecutiontime = round((microtime(true) - $starttime) * 1000);
        $this->config->lastexecutiontime = $updaterecord->lastexecutiontime;

        $DB->update_record('block_configurable_reports', $updaterecord);

        return $results;
    }

    /**
     * create_report
     *
     * @return bool
     */
    public function create_report(): bool {
        global $CFG;

        $components = cr_unserialize($this->config->components);

        $filters = $components['filters']['elements'] ?? [];
        $calcs = $components['calcs']['elements'] ?? [];

        $tablehead = [];
        $finalcalcs = [];
        $finaltable = [];

        $components = cr_unserialize($this->config->components);
        $config = $components['customsql']['config'] ?? new stdClass();
        $totalrecords = 0;

        $sql = '';
        if (isset($config->querysql)) {
            // Filters.
            $sql = $config->querysql;
            $this->filterparams = [];
            if (!empty($filters)) {
                foreach ($filters as $f) {
                    require_once($CFG->dirroot . '/blocks/configurable_reports/components/filters/' . $f['pluginname'] .
                        '/plugin.class.php');
                    $classname = 'plugin_' . $f['pluginname'];
                    $class = new $classname($this->config);
                    [$sql, $filterparams] = $class->execute_for_sql_report($sql, $f['formdata']);
                    $this->filterparams = array_merge($this->filterparams, $filterparams);
                }
            }

            $sql = $this->prepare_sql($sql);

            $filterform = isset($this->filterform);
            $formhasdata = $filterform && $this->filterform->get_data();

            $page = optional_param('page', 0, PARAM_INT);
            if ($page) {
                // Pagination is being used, we assume the filter data is included.
                $formhasdata = true;
            }

            $rs = null;
            if ($formhasdata || !$filterform) {
                try {
                    $rs = $this->execute_query($sql);
                } catch (dml_read_exception $e) {
                    // Databases like PostgreSQL refuse comparing a number with text, so a filter_var value of the
                    // wrong type is an error there. Say so instead of showing a database error.
                    if (trim(optional_param('filter_var', '', PARAM_RAW)) !== '' && strpos($config->querysql, '%%FILTER_VAR%%') !== false) {
                        throw new moodle_exception('invalidfiltervar', 'block_configurable_reports', '', null, $e->getMessage());
                    }
                    throw $e;
                }
            }

            if ($rs) {
                foreach ($rs as $row) {
                    if (empty($finaltable)) {
                        foreach ($row as $colname => $value) {
                            $tablehead[] = $colname;
                        }
                    }
                    $arrayrow = array_values((array) $row);
                    foreach ($arrayrow as $ii => $cell) {
                        if (!$this->is_forexport()) {
                            $cell = format_text($cell, FORMAT_HTML, ['trusted' => true, 'noclean' => true, 'para' => false]);
                        }
                        $arrayrow[$ii] = str_replace('[[QUESTIONMARK]]', '?', $cell ?? '');
                    }
                    $totalrecords++;
                    $finaltable[] = $arrayrow;
                }
            }
        }
        $this->sql = $sql;
        $this->totalrecords = $totalrecords;

        // Calcs.

        $finalcalcs = $this->get_calcs($finaltable, $tablehead);

        $table = new stdClass();
        $table->id = 'reporttable';
        $table->data = $finaltable;
        $table->head = $tablehead;

        $calcs = new html_table();
        $calcs->id = 'calcstable';
        $calcs->data = [$finalcalcs];
        $calcs->head = $tablehead;

        if (!$this->finalreport) {
            $this->finalreport = new stdClass();
        }
        $this->finalreport->name = $this->config->name;
        $this->finalreport->table = $table;
        $this->finalreport->calcs = $calcs;

        return true;
    }
}
