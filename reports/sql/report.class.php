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
     * Check a query against the rules for SQL reports, wherever it comes from (form, import, saved report).
     *
     * SQL reports are read only, there is no way to enable anything else. A keyword blocklist can be bypassed (hex
     * escapes in a DO block, chr() concatenation...), so a query must also start with SELECT, WITH or VALUES. The
     * database enforces read only on top of this, see run_read_only().
     *
     * @param string $sql
     * @return string|null The error string identifier, or null if the query is allowed.
     */
    public static function get_query_security_error(string $sql): ?string {
        // Functions that change the server or other connections, or hold it up, which the read only transaction does not
        // stop (it does stop the ones that write, like nextval()) and no report needs. This list is not exhaustive: only
        // users trusted as site administrators should write SQL reports.
        if (preg_match('/\b(SET_CONFIG|PG_TERMINATE_BACKEND|PG_CANCEL_BACKEND|PG_RELOAD_CONF|' .
                'PG_ROTATE_LOGFILE|PG_SWITCH_WAL|PG_CREATE_RESTORE_POINT|PG_PROMOTE|PG_\w*ADVISORY\w*|PG_STAT_RESET\w*|' .
                'PG_READ_FILE|PG_READ_BINARY_FILE|PG_LS_DIR|PG_FILE_\w+|PG_NOTIFY|PG_LOGICAL_EMIT_MESSAGE|PG_SLEEP\w*|' .
                'LO_IMPORT|LO_EXPORT|LO_UNLINK|LO_CREATE|LO_CREAT|LO_PUT|LO_FROM_BYTEA|LO_TRUNCATE|DBLINK\w*|GET_LOCK|' .
                'RELEASE_LOCK|RELEASE_ALL_LOCKS|LOAD_FILE|SLEEP|BENCHMARK)\b/i', $sql)) {
            return 'notallowedwords';
        }
        if (preg_match('/\b(ALTER|CREATE|DELETE|DROP|GRANT|INSERT|INTO|TRUNCATE|UPDATE|SET|VACUUM|REINDEX|DISCARD|LOCK)\b/i', $sql)) {
            return 'notallowedwords';
        }
        if (strpos($sql, ';') !== false) {
            return 'nosemicolon';
        }
        if (!self::is_select_statement($sql)) {
            return 'notselectstatement';
        }

        return null;
    }

    /**
     * Whether a query starts with SELECT, WITH or VALUES, ignoring comments, placeholders and opening brackets.
     *
     * Block comments are only skipped when they have no other comment inside: PostgreSQL nests them, so the end of
     * the first one is not where the comment ends, and a keyword after it can still be inside the comment. MySQL
     * runs the code in /*! comments, which are not skipped either.
     *
     * @param string $sql
     * @return bool
     */
    protected static function is_select_statement(string $sql): bool {
        $skip = '(?:\s|--[^\n]*(?:\n|$)|\/\*(?!!)(?:(?!\/\*|\*\/).)*\*\/|%%[^%]*%%|\()*';

        return (bool) preg_match('/^' . $skip . '(?:SELECT|WITH|VALUES)\b/is', $sql);
    }

    /**
     * Make a database error safe to show to report authors and viewers.
     *
     * Databases repeat the value that failed in the message, e.g. invalid input syntax for type integer: "<value>",
     * which lets a query read any column through its error message, and a routine can raise any text. So only
     * the first line is considered, cut at the first quote or colon (where the databases put the value), and it is
     * shown only if what is left is one of the known diagnostics below. Anything else is replaced by a generic
     * message. The full message is only logged on the server.
     *
     * @param string $error The message of the database.
     * @return string
     */
    public static function redact_db_error(string $error): string {
        $line = trim((string) strtok(trim($error), "\n"));
        if ($line === '') {
            return get_string('queryfailedgeneric', 'block_configurable_reports');
        }

        $prefix = '';
        if (preg_match('/^(?:ERROR|FATAL|PANIC):\s+/i', $line, $matches)) {
            $prefix = 'ERROR: ';
            $line = substr($line, strlen($matches[0]));
        }

        $cut = strcspn($line, ":\"'`");
        $diagnostic = rtrim(substr($line, 0, $cut));
        $suffix = $cut < strlen($line) ? '…' : '';

        $types = 'smallint|integer|bigint|numeric|real|double precision|boolean|date|time|timestamp|uuid|json|jsonb|interval|'
            . 'timestamp with time zone|timestamp without time zone|time with time zone|time without time zone';
        $known = [
            "invalid input syntax for type ($types)",
            "invalid input syntax for ($types)",
            '(?:date\/time|timestamp|interval) field value out of range',
            'numeric field overflow|(?:smallint|integer|bigint|value) out of range',
            'division by zero',
            'division by 0',
            'syntax error(?: at or near| at end of input)?',
            'column',
            'relation',
            'function',
            'operator does not exist',
            'permission denied for (?:table|relation|schema|function|column)',
            'Truncated incorrect (?:INTEGER|DOUBLE|DECIMAL|DATETIME|DATE|TIME) value',
            'Incorrect (?:INTEGER|DOUBLE|DECIMAL|DATETIME|DATE|TIME) value',
            'Unknown column',
            'Table',
            'You have an error in your SQL syntax; check the manual that corresponds to your \w+ server version for the '
                . 'right syntax to use near',
        ];
        if ($diagnostic === '' || !preg_match('/^(?:' . implode('|', $known) . ')$/i', $diagnostic)) {
            return get_string('queryfailedgeneric', 'block_configurable_reports');
        }

        return $prefix . $diagnostic . $suffix;
    }

    /**
     * Log the whole message of a database error on the server, where only administrators can read it.
     *
     * It is not shown with debugging(): that is a site wide setting, and the output goes to whoever views the report.
     *
     * @param \Throwable $e
     * @return void
     */
    protected static function log_db_error(\Throwable $e): void {
        error_log('block_configurable_reports: query failed: ' . ($e->error ?? $e->getMessage()));
    }

    /**
     * Run a query that must not change data, in a read only transaction.
     *
     * The query runs in a transaction that is read only, so the database refuses anything that writes even if the
     * checks in get_query_security_error() missed it (a SELECT can call a function that writes), and the query
     * cannot switch it off. The transaction is opened with BEGIN READ ONLY and not with Moodle's transaction API, as
     * Moodle's driver opens a savepoint right after BEGIN, and a mode set after it is lost when the savepoint is
     * released.
     *
     * On the main connection, never run without it: if the database is not PostgreSQL or MySQL, or the caller has
     * a transaction open (web service calls run in one) where read only could not be undone for the code that
     * follows, the query is refused. The remote database settings give a dedicated connection that does not have
     * these limits, where the database user must be read only.
     *
     * @param \moodle_database $db
     * @param string $sql
     * @param array|null $params
     * @param int $limit
     * @return \moodle_recordset
     */
    protected function run_read_only(\moodle_database $db, string $sql, ?array $params, int $limit) {
        global $DB;

        // A comment at the end of the query must not swallow the LIMIT the driver appends.
        $sql = rtrim($sql) . "\n";

        $begin = [
            'postgres' => 'BEGIN READ ONLY',
            'mysql' => 'START TRANSACTION READ ONLY',
        ][$db->get_dbfamily()] ?? null;

        if ($begin === null || $db->is_transaction_started()) {
            if ($db === $DB) {
                throw new moodle_exception('readonlyunavailable', 'block_configurable_reports');
            }

            return $db->get_recordset_sql($sql, $params, 0, $limit);
        }

        $db->execute($begin);
        try {
            // The rows are fetched here, as there is a limit, so the transaction can end before they are read.
            $results = $db->get_recordset_sql($sql, $params, 0, $limit);
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
        $db->execute('COMMIT');

        return $results;
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
        return $this->run_query($sql, $this->filterparams ?: null);
    }

    /**
     * Check and run a report query. Every way of running a SQL report goes through here.
     *
     * @param string $sql
     * @param array|null $params
     * @return mixed
     */
    protected function run_query(string $sql, ?array $params) {
        global $remotedb, $DB, $CFG;

        $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);

        // Imported reports do not go through the form, so check the query every time it runs.
        if ($error = self::get_query_security_error($sql)) {
            throw new moodle_exception($error, 'block_configurable_reports');
        }

        $reportlimit = get_config('block_configurable_reports', 'reportlimit');
        if (empty($reportlimit) || $reportlimit == '0') {
            $reportlimit = BLOCK_CONFIGURABLE_REPORTS_MAX_RECORDS;
        }

        $starttime = microtime(true);

        try {
            $results = $this->run_read_only($remotedb, $sql, $params, (int) $reportlimit);
        } catch (\dml_exception $e) {
            // The database exception carries the message, the SQL and the parameters, which can hold data the query
            // read, and callers show it as debugging information. Nothing of it leaves here but a redacted message.
            self::log_db_error($e);
            throw new \block_configurable_reports\local\query_exception(self::redact_db_error((string) ($e->error ?? '')));
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
                    $classname = cr_require_component_plugin('filters', $f['pluginname']);
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
                } catch (\block_configurable_reports\local\query_exception $e) {
                    // Databases like PostgreSQL refuse comparing a number with text, so a filter_var value of the
                    // wrong type is an error there. Say so instead of showing a database error.
                    if (trim(optional_param('filter_var', '', PARAM_RAW)) !== '' && strpos($config->querysql, '%%FILTER_VAR%%') !== false) {
                        throw new moodle_exception('invalidfiltervar', 'block_configurable_reports');
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
