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
 * Configurable Reports - A Moodle block for creating customizable reports
 *
 * @package    block_configurable_reports
 * @copyright  Daniel Neis Araujo <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

use context_course;
use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use block_configurable_reports\local\dynamic_report;
use block_configurable_reports\local\dynamic_sql;
use moodle_exception;

/**
 * This is the external API for this component.
 *
 * @copyright  Daniel Neis Araujo <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {
    /**
     * get_report_data parameters.
     *
     * @return external_function_parameters
     */
    public static function get_report_data_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'reportid' => new external_value(PARAM_INT, 'The report id', VALUE_REQUIRED),
                'courseid' => new external_value(PARAM_INT, 'The course id', VALUE_DEFAULT, 1),
                'parameters' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_ALPHANUMEXT, 'Dynamic parameter name'),
                        'value' => new external_value(PARAM_RAW, 'Dynamic parameter value'),
                    ]),
                    'Dynamic report parameters',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Returns data of given report id.
     *
     * @param int $reportid the report id
     * @param int $courseid course id (default to site)
     * @param array $parameters dynamic report parameters
     * @return array An array with a 'data' JSON string and a 'warnings' string
     */
    public static function get_report_data(int $reportid, int $courseid = 1, array $parameters = []): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(
            self::get_report_data_parameters(),
            ['reportid' => $reportid, 'courseid' => $courseid, 'parameters' => $parameters]
        );

        if ($courseid === SITEID) {
            $context = context_system::instance();
        } else {
            $context = context_course::instance($courseid);
        }

        self::validate_context($context);

        $json = [];
        $warnings = '';
        if (!$report = $DB->get_record('block_configurable_reports', ['id' => $reportid])) {
            $warnings = get_string('reportdoesnotexists', 'block_configurable_reports');
        } else {
            require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
            require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
            require_once($CFG->dirroot . '/blocks/configurable_reports/reports/' . $report->type . '/report.class.php');

            $reportclassname = 'report_' . $report->type;
            $reportclass = new $reportclassname($report);
            if (!$reportclass->check_permissions($USER->id, $context)) {
                return [
                    'data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'warnings' => get_string('badpermissions', 'block_configurable_reports'),
                ];
            }

            if ($report->type === 'sql' && self::has_dynamic_sql_placeholders($report)) {
                [$report, $queryparams] = self::apply_dynamic_sql_to_report($report, $parameters);
                $reportclass = new dynamic_report($report);
                $reportclass->set_query_parameters($queryparams);
            }

            $reportclass->create_report();
            $table = $reportclass->finalreport->table;
            $headers = $table->head;
            foreach ($table->data as $data) {
                $jsonobject = [];
                foreach ($data as $index => $value) {
                    $jsonobject[$headers[$index]] = $value;
                }
                $json[] = $jsonobject;
            }
        }

        return [
            'data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'warnings' => $warnings,
        ];
    }

    /**
     * get_report_data return
     *
     * @return external_single_structure
     */
    public static function get_report_data_returns(): external_single_structure {
        return new external_single_structure(
            [
                'data' => new external_value(PARAM_RAW, 'JSON-formatted report data'),
                'warnings' => new external_value(PARAM_TEXT, 'Warning message'),
            ]
        );
    }

    /**
     * get_reports parameters.
     *
     * @return external_function_parameters
     */
    public static function get_reports_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns reports available to the current user and their dynamic parameters.
     *
     * @return array
     */
    public static function get_reports(): array {
        global $CFG, $DB, $USER;

        self::validate_parameters(self::get_reports_parameters(), []);

        require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
        require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');

        $reports = $DB->get_records_sql(
            "SELECT r.*
               FROM {block_configurable_reports} r
          LEFT JOIN {course} c ON c.id = r.courseid
              WHERE r.global = 1 OR c.id IS NOT NULL
           ORDER BY r.name ASC"
        );

        $result = [];
        $warnings = [];
        foreach ($reports as $report) {
            $reportcontext = $report->global
                ? context_system::instance()
                : context_course::instance($report->courseid, IGNORE_MISSING);
            if (!$reportcontext) {
                continue;
            }

            try {
                self::validate_context($reportcontext);
            } catch (moodle_exception $e) {
                continue;
            }

            $reportclassfile = $CFG->dirroot . '/blocks/configurable_reports/reports/' . $report->type . '/report.class.php';
            if (!file_exists($reportclassfile)) {
                $warnings[] = [
                    'item' => 'report',
                    'itemid' => $report->id,
                    'warningcode' => 'missingreportclassfile',
                    'message' => get_string('missingreportclassfile', 'block_configurable_reports'),
                ];
                continue;
            }

            require_once($reportclassfile);
            $reportclassname = 'report_' . $report->type;
            if (!class_exists($reportclassname)) {
                $warnings[] = [
                    'item' => 'report',
                    'itemid' => $report->id,
                    'warningcode' => 'missingreportclass',
                    'message' => get_string('missingreportclass', 'block_configurable_reports'),
                ];
                continue;
            }

            $reportclass = new $reportclassname($report);
            if (!$reportclass->check_permissions($USER->id, $reportcontext)) {
                continue;
            }

            $parameters = [];
            if ($report->type === 'sql') {
                $components = cr_unserialize($report->components);
                if (!empty($components['customsql']['config']->querysql)) {
                    try {
                        $parameters = dynamic_sql::extract_parameters($components['customsql']['config']->querysql);
                    } catch (moodle_exception $e) {
                        $warnings[] = [
                            'item' => 'report',
                            'itemid' => $report->id,
                            'warningcode' => 'invaliddynamicparameters',
                            'message' => get_string('invaliddynamicparameters', 'block_configurable_reports'),
                        ];
                        continue;
                    }
                }
            }

            $result[] = [
                'id' => $report->id,
                'name' => $report->name,
                'summary' => $report->summary ?? '',
                'courseid' => $report->courseid ?? 0,
                'global' => !empty($report->global),
                'type' => $report->type,
                'parameters' => $parameters,
            ];
        }

        return [
            'reports' => $result,
            'warnings' => $warnings,
        ];
    }

    /**
     * get_reports return.
     *
     * @return external_single_structure
     */
    public static function get_reports_returns(): external_single_structure {
        return new external_single_structure(
            [
                'reports' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Report ID'),
                        'name' => new external_value(PARAM_TEXT, 'Report name'),
                        'summary' => new external_value(PARAM_RAW, 'Report summary'),
                        'courseid' => new external_value(PARAM_INT, 'Report course ID'),
                        'global' => new external_value(PARAM_BOOL, 'Whether the report is global'),
                        'type' => new external_value(PARAM_ALPHANUMEXT, 'Report type'),
                        'parameters' => new external_multiple_structure(
                            new external_single_structure([
                                'name' => new external_value(PARAM_ALPHANUMEXT, 'Dynamic parameter name'),
                                'field' => new external_value(PARAM_TEXT, 'SQL field used by the parameter'),
                                'operator' => new external_value(PARAM_RAW, 'SQL operator'),
                                'placeholder' => new external_value(PARAM_RAW, 'Full SQL placeholder'),
                                'required' => new external_value(PARAM_BOOL, 'Whether the parameter is required'),
                            ])
                        ),
                    ])
                ),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_TEXT, 'Warning item type'),
                        'itemid' => new external_value(PARAM_INT, 'Warning item ID'),
                        'warningcode' => new external_value(PARAM_TEXT, 'Warning code'),
                        'message' => new external_value(PARAM_TEXT, 'Warning message'),
                    ])
                ),
            ]
        );
    }

    /**
     * Applies dynamic SQL parameters to the report object in memory.
     *
     * This does not save the modified SQL back to the database.
     *
     * @param object $report Configurable Reports DB record.
     * @param array $parameters Dynamic parameters.
     * @return array Modified in-memory report object and DML query parameters.
     * @throws \invalid_parameter_exception If a submitted parameter is invalid or duplicated.
     * @throws moodle_exception If the report has no custom SQL or contains unresolved dynamic placeholders.
     */
    private static function apply_dynamic_sql_to_report(object $report, array $parameters): array {
        $components = cr_unserialize($report->components);

        if (empty($components['customsql']['config']->querysql)) {
            throw new moodle_exception('missingcustomsql', 'block_configurable_reports');
        }

        $sql = $components['customsql']['config']->querysql;
        [$sql, $queryparams] = dynamic_sql::apply_parameters($sql, $parameters);

        $components['customsql']['config']->querysql = $sql;
        $report->components = cr_serialize($components);

        return [$report, $queryparams];
    }

    /**
     * Checks whether the SQL report contains dynamic placeholders.
     *
     * @param object $report Configurable Reports DB record.
     * @return bool
     */
    private static function has_dynamic_sql_placeholders(object $report): bool {
        $components = cr_unserialize($report->components);
        if (empty($components['customsql']['config']->querysql)) {
            return false;
        }

        return stripos($components['customsql']['config']->querysql, '%%DYNAMIC_') !== false;
    }
}
