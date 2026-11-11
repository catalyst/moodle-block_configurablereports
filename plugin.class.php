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

// TODO namespace.

/**
 * Class plugin_base
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class plugin_base {
    /**
     * @var string
     */
    public string $fullname = '';

    /**
     * @var string
     */
    public $type = '';

    /**
     * @var false|mixed|stdClass|null
     */
    public $report = null;

    /**
     * @var bool
     */
    public bool $form = false;

    /**
     * @var array
     */
    public $cache = [];

    /**
     * @var bool
     */
    public bool $unique = false;

    /**
     * @var array
     */
    public array $reporttypes = [];

    /**
     * @var true
     */
    public bool $ordering;

    /**
     * __construct
     *
     * @param int|object $report
     */
    public function __construct($report) {
        global $DB;

        if (is_numeric($report)) {
            $this->report = $DB->get_record('block_configurable_reports', ['id' => $report]);
        } else {
            $this->report = $report;
        }
        $this->init();
    }

    /**
     * Summary
     *
     * @param object $data
     * @return string
     */
    public function summary(object $data): string {
        return '';
    }

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        throw new coding_exception('init method not implemented');
    }

    /**
     * Applies the filter to an SQL report query.
     *
     * Request-derived values must be returned as named DML parameters rather than concatenated into the SQL.
     *
     * @param string $sql
     * @param object|null $data
     * @return array [SQL, DML parameters]
     */
    public function execute_for_sql_report(string $sql, ?object $data = null): array {
        return [$this->execute($sql, $data), []];
    }

    /** @var int Counter that keeps the DML parameter names of SQL report filters unique within a request. */
    private static int $sqlparamcounter = 0;

    /**
     * Replaces every %%NAME:spec%% placeholder of an SQL report with SQL built by the filter.
     *
     * Moodle does not allow repeating a named parameter, so each occurrence gets its own parameter prefix.
     *
     * @param string $sql
     * @param string $name Placeholder name, e.g. FILTER_SEMESTER.
     * @param callable $build function(string $spec, string $prefix): array [SQL, DML parameters], where the
     *                        parameters must be named with the prefix.
     * @return array [SQL, DML parameters]
     */
    protected function replace_sql_placeholders(string $sql, string $name, callable $build): array {
        $params = [];
        $sql = preg_replace_callback(
            '/%%' . preg_quote($name, '/') . ':([^%]+)%%/i',
            function (array $match) use (&$params, $build): string {
                [$replace, $newparams] = $build($match[1], 'crfilter' . ++self::$sqlparamcounter);
                $params = array_merge($params, $newparams);
                return $replace;
            },
            $sql
        );

        return [$sql, $params];
    }

    /**
     * Applies a free text value to the %%NAME:field:operator%% placeholders of an SQL report, as bound parameters.
     *
     * @param string $sql
     * @param string $name Placeholder name, e.g. FILTER_SEARCHTEXT.
     * @param string $value The value submitted by the viewer.
     * @param string $defaultoperator Operator used when the placeholder does not give one, '' to require it.
     * @return array [SQL, DML parameters]
     */
    protected function apply_text_filter(string $sql, string $name, string $value, string $defaultoperator = ''): array {
        global $remotedb;

        $operators = ['=', '<', '>', '<=', '>=', '~', 'in'];

        return $this->replace_sql_placeholders(
            $sql,
            $name,
            function (string $spec, string $prefix) use ($remotedb, $operators, $value, $defaultoperator): array {
                [$field, $operator] = array_pad(explode(':', $spec), 2, '');
                if ($operator === '') {
                    $operator = $defaultoperator;
                }
                if (!in_array($operator, $operators, true)) {
                    throw new moodle_exception('nosuchoperator');
                }

                if ($operator === '~') {
                    return [' AND ' . $remotedb->sql_like($field, ':' . $prefix, false), [$prefix => '%' . trim($value) . '%']];
                }

                if ($operator === 'in') {
                    $conditions = [];
                    $params = [];
                    // Accept comma-separated values, allowing for '\,' as a literal comma.
                    foreach (preg_split("/(?<!\\\\),/", $value) as $key => $item) {
                        $item = str_replace('\\,', ',', trim(trim($item), '"\''));
                        $conditions[] = $remotedb->sql_like($field, ':' . $prefix . '_' . $key, false);
                        $params[$prefix . '_' . $key] = $item;
                    }

                    // Despite the name, by not actually using in() we can support wildcards, and maybe be more portable as well.
                    return [' AND (' . implode(' OR ', $conditions) . ')', $params];
                }

                return [" AND {$field} {$operator} :{$prefix}", [$prefix => $value]];
            }
        );
    }

    /**
     * Applies a value to the %%NAME:field%% placeholders of an SQL report as a "contains" match on bound parameters.
     *
     * @param string $sql
     * @param string $name Placeholder name, e.g. FILTER_SEMESTER.
     * @param string $value The value submitted by the viewer, matched literally.
     * @return array [SQL, DML parameters]
     */
    protected function apply_contains_filter(string $sql, string $name, string $value): array {
        global $remotedb;

        return $this->replace_sql_placeholders(
            $sql,
            $name,
            fn(string $field, string $prefix): array => [
                ' AND ' . $remotedb->sql_like($field, ':' . $prefix, false),
                [$prefix => '%' . $remotedb->sql_like_escape($value) . '%'],
            ]
        );
    }

    /**
     * colformat
     *
     * @param object|null $data
     * @return string[]
     */
    public function colformat(?object $data): array {
        $align = $data->align ?? '';
        $size = $data->size ?? '';
        $wrap = $data->wrap ?? '';

        return [$align, $size, $wrap];
    }

    /**
     * print_filter
     *
     * @param MoodleQuickForm $mform
     * @param bool|object $formdata
     * @return mixed
     */
    public function print_filter(MoodleQuickForm $mform, $formdata = false): void {
        throw new coding_exception('print_filter method not implemented');
    }
}
