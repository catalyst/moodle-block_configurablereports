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

use moodle_exception;

/**
 * Tests for dynamic SQL placeholder expansion.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Monash University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_configurable_reports\local\dynamic_sql
 */
final class dynamic_sql_test extends \advanced_testcase {
    /**
     * Tests the extraction of supported placeholder metadata.
     */
    public function test_extract_parameters(): void {
        $sql = 'SELECT * FROM prefix_user u WHERE 1 = 1 '
            . '%%DYNAMIC_USER:u.id:=%% %%DYNAMIC_NAME:u.username:~%%';

        $this->assertSame([
            [
                'name' => 'USER',
                'field' => 'u.id',
                'operator' => '=',
                'placeholder' => '%%DYNAMIC_USER:u.id:=%%',
                'required' => false,
            ],
            [
                'name' => 'NAME',
                'field' => 'u.username',
                'operator' => '~',
                'placeholder' => '%%DYNAMIC_NAME:u.username:~%%',
                'required' => false,
            ],
        ], dynamic_sql::extract_parameters($sql));
    }

    /**
     * Tests replacement and quoting of submitted values.
     */
    public function test_apply_parameters(): void {
        global $DB, $remotedb;

        $remotedb = $DB;

        $sql = 'SELECT * FROM prefix_user u WHERE 1 = 1 '
            . '%%DYNAMIC_USER:u.id:=%% %%DYNAMIC_NAME:u.username:=%% %%DYNAMIC_UNUSED:u.deleted:=%%';

        [$actual, $params] = dynamic_sql::apply_parameters($sql, [
            ['name' => 'USER', 'value' => '42'],
            ['name' => 'NAME', 'value' => "O'Brien"],
        ]);

        $this->assertStringContainsString(' AND u.id = :blockdynamic0', $actual);
        $this->assertStringContainsString(' AND u.username = :blockdynamic1', $actual);
        $this->assertStringNotContainsString('DYNAMIC_UNUSED', $actual);
        $this->assertSame(['blockdynamic0' => '42', 'blockdynamic1' => "O'Brien"], $params);
    }

    /**
     * Tests that IN placeholders use distinct query parameter names.
     */
    public function test_apply_parameters_with_multiple_in_conditions(): void {
        global $DB, $remotedb;

        $remotedb = $DB;
        $sql = 'SELECT * FROM prefix_user u WHERE 1 = 1 '
            . '%%DYNAMIC_IDS:u.id:in%% %%DYNAMIC_DELETED:u.deleted:in%%';

        [$actual, $params] = dynamic_sql::apply_parameters($sql, [
            ['name' => 'IDS', 'value' => '1,2'],
            ['name' => 'DELETED', 'value' => '0,1'],
        ]);

        $this->assertStringContainsString(' AND u.id IN (', $actual);
        $this->assertStringContainsString(' AND u.deleted IN (', $actual);
        preg_match_all('/:(blockdynamicin\d+)/', $actual, $matches);
        $this->assertSame(array_keys($params), $matches[1]);
        $this->assertSame(['1', '2', '0', '1'], array_values($params));
    }

    /**
     * Tests rejection of malformed placeholders.
     */
    public function test_apply_parameters_rejects_malformed_placeholder(): void {
        global $DB, $remotedb;

        $remotedb = $DB;
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('unresolvedplaceholder', 'block_configurable_reports'));

        dynamic_sql::apply_parameters('SELECT 1 %%DYNAMIC_BAD:u.id:drop%%', []);
    }

    /**
     * Tests rejection of duplicate placeholder names.
     */
    public function test_extract_parameters_rejects_duplicate_names(): void {
        $sql = 'SELECT * FROM prefix_user u WHERE 1 = 1 '
            . '%%DYNAMIC_SEARCH:u.firstname:~%% %%DYNAMIC_SEARCH:u.lastname:~%%';

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('duplicatedynamicparameter', 'block_configurable_reports'));

        dynamic_sql::extract_parameters($sql);
    }
}
