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

use invalid_parameter_exception;
use moodle_exception;

/**
 * Expands dynamic placeholders in Configurable Reports SQL.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Frank Saikali <fsjk85@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dynamic_sql {
    /** @var string Regular expression matching supported dynamic placeholders. */
    private const PLACEHOLDER_PATTERN =
        '/%%DYNAMIC_([A-Z0-9_-]+):([a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)?)(?::(<=|>=|=|<|>|~|in))?%%/i';

    /** @var string Regular expression matching any remaining dynamic placeholder. */
    private const UNRESOLVED_PLACEHOLDER_PATTERN = '/%%DYNAMIC_[^%]+%%/i';

    /**
     * Extracts the supported dynamic placeholders from a SQL query.
     *
     * @param string $sql SQL query.
     * @return array
     */
    public static function extract_parameters(string $sql): array {
        $matches = self::get_placeholder_matches($sql);

        $parameters = [];
        foreach ($matches as $match) {
            $name = strtoupper($match[1]);
            $parameters[$name] = [
                'name' => $name,
                'field' => $match[2],
                'operator' => strtolower($match[3] ?? '~'),
                'placeholder' => $match[0],
                'required' => false,
            ];
        }

        return array_values($parameters);
    }

    /**
     * Replaces supported dynamic placeholders with submitted values.
     *
     * @param string $sql SQL query.
     * @param array $parameters Submitted parameters.
     * @return array SQL query and DML parameters.
     * @throws invalid_parameter_exception If a submitted parameter is invalid or duplicated.
     * @throws moodle_exception If the SQL contains unresolved dynamic placeholders.
     */
    public static function apply_parameters(string $sql, array $parameters): array {
        global $remotedb;

        self::get_placeholder_matches($sql);
        $parammap = self::normalise_parameters($parameters);
        $queryparams = [];
        $paramindex = 0;

        $sql = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($parammap, &$queryparams, &$paramindex, $remotedb): string {
                $name = strtoupper($matches[1]);
                if (!array_key_exists($name, $parammap) || $parammap[$name] === '') {
                    return '';
                }

                return self::get_sql_condition(
                    $matches[2],
                    strtolower($matches[3] ?? '~'),
                    $parammap[$name],
                    $queryparams,
                    $paramindex,
                    $remotedb
                );
            },
            $sql
        );

        if (preg_match(self::UNRESOLVED_PLACEHOLDER_PATTERN, $sql)) {
            throw new moodle_exception('unresolvedplaceholder', 'block_configurable_reports');
        }

        return [$sql, $queryparams];
    }

    /**
     * Returns a SQL condition for one dynamic parameter.
     *
     * @param string $field SQL field.
     * @param string $operator SQL operator.
     * @param mixed $value Submitted value.
     * @param array $queryparams DML query parameters.
     * @param int $paramindex Next DML parameter index.
     * @param \moodle_database $database Database connection used to run the query.
     * @return string
     */
    private static function get_sql_condition(
        string $field,
        string $operator,
        $value,
        array &$queryparams,
        int &$paramindex,
        \moodle_database $database
    ): string {
        if ($operator === '~') {
            $placeholder = self::add_query_parameter(
                "%{$database->sql_like_escape(trim((string) $value))}%",
                $queryparams,
                $paramindex
            );
            return ' AND ' . $database->sql_like($field, $placeholder, false);
        }

        if ($operator === 'in') {
            $items = [];
            foreach (preg_split('/(?<!\\\\),/', (string) $value) as $item) {
                $item = str_replace('\\,', ',', trim(trim($item), '"\''));
                if ($item !== '') {
                    $items[] = $item;
                }
            }

            if (empty($items)) {
                return '';
            }

            $prefix = 'blockdynamicin' . $paramindex++;
            [$insql, $inparams] = $database->get_in_or_equal($items, SQL_PARAMS_NAMED, $prefix);
            $queryparams += $inparams;

            return " AND {$field} {$insql}";
        }

        return " AND {$field} {$operator} " . self::add_query_parameter(trim((string) $value), $queryparams, $paramindex);
    }

    /**
     * Adds a submitted value to the DML query parameters.
     *
     * @param string $value Submitted value.
     * @param array $queryparams DML query parameters.
     * @param int $paramindex Next DML parameter index.
     * @return string
     */
    private static function add_query_parameter(string $value, array &$queryparams, int &$paramindex): string {
        $name = 'blockdynamic' . $paramindex++;
        $queryparams[$name] = $value;

        return ':' . $name;
    }

    /**
     * Normalises submitted parameters into a name-to-value map.
     *
     * @param array $parameters Submitted parameters.
     * @return array
     * @throws invalid_parameter_exception If a submitted parameter is invalid or duplicated.
     */
    private static function normalise_parameters(array $parameters): array {
        $map = [];
        foreach ($parameters as $parameter) {
            $name = strtoupper(trim($parameter['name']));
            if (!preg_match('/^[A-Z0-9_-]+$/', $name)) {
                throw new invalid_parameter_exception('Invalid dynamic parameter name: ' . $name);
            }
            if (array_key_exists($name, $map)) {
                throw new invalid_parameter_exception('Duplicate dynamic parameter name: ' . $name);
            }

            $map[$name] = $parameter['value'];
        }

        return $map;
    }

    /**
     * Returns supported dynamic placeholders after validating their names are unique.
     *
     * @param string $sql SQL query.
     * @return array
     * @throws moodle_exception If the SQL contains duplicate dynamic placeholder names.
     */
    private static function get_placeholder_matches(string $sql): array {
        preg_match_all(self::PLACEHOLDER_PATTERN, $sql, $matches, PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $match) {
            $name = strtoupper($match[1]);
            if (array_key_exists($name, $names)) {
                throw new moodle_exception('duplicatedynamicparameter', 'block_configurable_reports');
            }

            $names[$name] = true;
        }

        return $matches;
    }
}
