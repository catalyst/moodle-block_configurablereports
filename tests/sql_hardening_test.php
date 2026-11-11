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
 * Tests that SQL reports cannot change data or show data in database errors.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_configurable_reports;

use advanced_testcase;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/reports/sql/report.class.php');

/**
 * Tests for the checks a SQL report query goes through, and the way it runs.
 *
 * @package    block_configurable_reports
 * @covers     \report_sql
 * @covers     \report_base::uses_remote_db
 * @covers     \block_configurable_reports\local\dynamic_report
 */
final class sql_hardening_test extends advanced_testcase {
    /**
     * Create a SQL report that is saved, as reports are when they run.
     *
     * @param string $sql
     * @param int $remote
     * @return \report_sql
     */
    private function create_report(string $sql, int $remote = 0): \report_sql {
        global $DB, $SITE;

        $record = (object) [
            'courseid' => $SITE->id,
            'ownerid' => get_admin()->id,
            'visible' => 1,
            'name' => 'Hardening test',
            'type' => 'sql',
            'components' => cr_serialize(['customsql' => ['config' => (object) ['querysql' => $sql]]]),
            'remote' => $remote,
        ];
        $record->id = $DB->insert_record('block_configurable_reports', $record);

        return new \report_sql($record);
    }

    /**
     * Queries that change data or hide a statement from the keyword check.
     *
     * @return array
     */
    public static function attack_provider(): array {
        // UPDATE, SET, a quote and a semicolon written as hex escapes, so no blocked word is in the text.
        $hex = "DO E'BEGIN \\x55\\x50\\x44\\x41\\x54\\x45 prefix_config \\x53\\x45\\x54 value=\\x272,1001,1002\\x27 " .
            "WHERE name=\\x27siteadmins\\x27\\x3b END'";

        return [
            'hex escapes in a DO block' => [$hex],
            'comment that swallows the LIMIT the driver adds' => [$hex . ' --'],
            'comment on its own line' => [$hex . "\n-- end"],
            'lower case do' => [strtolower(substr($hex, 0, 2)) . substr($hex, 2)],
            'dollar quoted DO' => ['DO $$ BEGIN PERFORM 1 END $$ --'],
            'comment before the DO' => ["/* report */ " . $hex . ' --'],
            'CALL' => ['CALL some_procedure() --'],
            'COPY' => ["COPY (SELECT 1) TO PROGRAM 'id' --"],
            'EXECUTE a prepared statement' => ['EXECUTE some_statement --'],
            'EXPLAIN' => ['EXPLAIN SELECT 1'],
            'text before the SELECT' => ['x SELECT 1'],
        ];
    }

    /**
     * Queries that are only read.
     *
     * @return array
     */
    public static function read_provider(): array {
        return [
            'select' => ['SELECT id FROM prefix_user'],
            'lower case' => ['select id from prefix_user'],
            'leading white space' => ["\n  SELECT id FROM prefix_user"],
            'comment first' => ["-- the users\nSELECT id FROM prefix_user"],
            'block comment first' => ["/* the users */ SELECT id FROM prefix_user"],
            'trailing comment' => ['SELECT id FROM prefix_user -- all of them'],
            'with' => ['WITH u AS (SELECT id FROM prefix_user) SELECT id FROM u'],
            'bracketed union' => ['(SELECT id FROM prefix_user) UNION (SELECT id FROM prefix_course)'],
            'placeholder first' => ['%%DEBUG%% SELECT id FROM prefix_user'],
            'values' => ['VALUES (1), (2)'],
        ];
    }

    /**
     * Statements that are not a SELECT are refused, however their words are hidden.
     *
     * @dataProvider attack_provider
     * @param string $sql
     */
    #[DataProvider('attack_provider')]
    public function test_attacks_are_refused(string $sql): void {
        $this->assertNotNull(\report_sql::get_query_security_error($sql));
    }

    /**
     * Reading queries are not refused.
     *
     * @dataProvider read_provider
     * @param string $sql
     */
    #[DataProvider('read_provider')]
    public function test_reads_are_allowed(string $sql): void {
        $this->assertNull(\report_sql::get_query_security_error($sql));
    }

    /**
     * The words that change data are refused, with the same error as in the form.
     */
    public function test_blocked_words_are_refused(): void {
        $this->assertSame('notallowedwords', \report_sql::get_query_security_error('SELECT 1 FROM t WHERE x = 1 OR y IN (SELECT 1) UPDATE'));
        $this->assertSame('nosemicolon', \report_sql::get_query_security_error('SELECT 1; SELECT 2'));
        $this->assertSame('notallowedwords', \report_sql::get_query_security_error('DELETE FROM prefix_user'));
        $this->assertSame('notallowedwords', \report_sql::get_query_security_error('SELECT id FROM prefix_user WHERE LOCK = 1'));
        $this->assertSame('notselectstatement', \report_sql::get_query_security_error('DO $$ BEGIN PERFORM 1 END $$ --'));
    }

    /**
     * Statements that change data are always refused: there is no setting that enables them.
     */
    public function test_writes_are_always_refused(): void {
        foreach (['INSERT INTO prefix_log_test SELECT 1', 'CREATE TABLE prefix_x AS SELECT 1', 'SELECT 1 INTO prefix_x'] as $sql) {
            $this->assertSame('notallowedwords', \report_sql::get_query_security_error($sql), $sql);
        }
    }

    /**
     * A saved or imported report is checked when it runs, as it skipped the form.
     *
     * @dataProvider attack_provider
     * @param string $sql
     */
    #[DataProvider('attack_provider')]
    public function test_query_is_checked_when_it_runs(string $sql): void {
        global $DB;
        $this->resetAfterTest();

        $before = $DB->get_field('config', 'value', ['name' => 'siteadmins']);
        $report = $this->create_report($sql);

        try {
            $report->execute_query($sql);
            $this->fail('The query ran');
        } catch (\moodle_exception $e) {
            $this->assertContains($e->errorcode, ['notallowedwords', 'notselectstatement', 'nosemicolon']);
        }

        $this->assertEquals($before, $DB->get_field('config', 'value', ['name' => 'siteadmins']));
    }

    /**
     * A comment at the end of a query does not swallow the LIMIT the driver adds.
     */
    public function test_trailing_comment_keeps_the_row_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        set_config('reportlimit', 2, 'block_configurable_reports');

        $sql = 'SELECT id FROM prefix_user ORDER BY id -- all the users';
        $report = $this->create_report($sql);
        $rs = $report->execute_query($sql);
        $count = 0;
        foreach ($rs as $row) {
            $count++;
        }
        $rs->close();

        $this->assertSame(2, $count);
    }

    /**
     * On PostgreSQL the query runs in a read only transaction, which stops a function that writes.
     */
    public function test_query_runs_in_a_read_only_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('Read only transactions are used on PostgreSQL.');
        }
        // Tests run in a transaction on PostgreSQL, and the query only goes read only when it can start its own.
        $this->preventResetByRollback();
        $this->assertFalse($DB->is_transaction_started());

        // Taking the next value of a sequence is a write.
        $sql = "SELECT nextval('prefix_user_id_seq')";
        $report = $this->create_report($sql);
        try {
            $report->execute_query($sql);
            $this->fail('The query was not read only');
        } catch (\dml_read_exception $e) {
            $this->assertStringContainsString('read-only transaction', $e->error);
        }

        // The transaction was ended, and the next query can write.
        $this->assertFalse($DB->is_transaction_started());
        $this->assertNotEmpty($this->getDataGenerator()->create_user()->id);
    }

    /**
     * Inside a transaction of the caller the query still runs and is still checked, and the caller can write after it.
     */
    public function test_query_in_a_transaction_of_the_caller(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertTrue($DB->is_transaction_started());

        $sql = 'SELECT id FROM prefix_user WHERE id = 2';
        $report = $this->create_report($sql);
        $rs = $report->execute_query($sql);
        $this->assertCount(1, iterator_to_array($rs, false));
        $rs->close();
        $this->assertNotEmpty($this->getDataGenerator()->create_user()->id);

        $this->expectException(\moodle_exception::class);
        $report->execute_query('DO $$ BEGIN PERFORM 1 END $$ --');
    }

    /**
     * Reports run as before in a read only transaction.
     */
    public function test_reports_still_return_rows(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'rowcheck']);

        $sql = "SELECT id, username FROM prefix_user WHERE username = 'rowcheck'";
        $report = $this->create_report($sql);
        $rs = $report->execute_query($sql);
        $rows = iterator_to_array($rs, false);
        $rs->close();

        $this->assertCount(1, $rows);
        $this->assertEquals($user->id, $rows[0]->id);
    }

    /**
     * The message of a database error does not show the data the query read.
     */
    public function test_database_error_does_not_show_data(): void {
        global $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('The error needs PostgreSQL to refuse the cast.');
        }
        $this->setAdminUser();

        // PostgreSQL says: invalid input syntax for type integer: "<the text>".
        $report = $this->create_report("SELECT ('abc-secret-value:42')::int AS leak");
        try {
            $report->create_report();
            $this->fail('The query worked');
        } catch (\moodle_exception $e) {
            $this->assertSame('queryfailed', $e->errorcode);
            $this->assertStringNotContainsString('abc-secret-value', $e->getMessage());
            $this->assertStringNotContainsString('abc-secret-value', (string) $e->debuginfo);
            $this->assertStringContainsString('invalid input syntax for type integer', $e->getMessage());
        }

        // The whole message is only for developers.
        $this->assertDebuggingCalled();
    }

    /**
     * Database errors that show a value.
     *
     * @return array
     */
    public static function redact_provider(): array {
        return [
            'postgres cast' => [
                "ERROR:  invalid input syntax for type integer: \"17:2:admin:manual:admin@example.test\"\nLINE 1: SELECT (x)::int",
                'ERROR:  invalid input syntax for type integer: "…"',
            ],
            'value with a quote in it' => [
                'ERROR:  invalid input syntax for type integer: "a"b-secret"',
                'ERROR:  invalid input syntax for type integer: "…"',
            ],
            'value with a new line' => [
                "ERROR:  invalid input syntax for type integer: \"line one\nsecret line two\"",
                'ERROR:  invalid input syntax for type integer: "…"',
            ],
            'mysql' => [
                "Truncated incorrect INTEGER value: 'secret'",
                "Truncated incorrect INTEGER value: '…'",
            ],
            'backticks' => [
                'Unknown column `secret` in field list',
                'Unknown column `…`',
            ],
            'no value' => ['ERROR:  division by zero', 'ERROR:  division by zero'],
            'only the first line' => ["ERROR:  syntax error\nLINE 1: SELECT secret", 'ERROR:  syntax error'],
            'empty' => ['', ''],
        ];
    }

    /**
     * The values are removed from a database error.
     *
     * @dataProvider redact_provider
     * @param string $error
     * @param string $expected
     */
    #[DataProvider('redact_provider')]
    public function test_database_error_is_redacted(string $error, string $expected): void {
        $this->assertSame($expected, \report_sql::redact_db_error($error));
    }

    /**
     * A SQL report uses the remote database whatever its option says, and other reports follow their option.
     */
    public function test_sql_reports_always_use_the_remote_database(): void {
        global $CFG, $SITE;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/blocks/configurable_reports/reports/courses/report.class.php');

        // Created before the settings, so that no connection is made.
        $sqlreport = $this->create_report('SELECT 1', 0);
        $otherreport = new \report_courses((object) [
            'id' => 0,
            'courseid' => $SITE->id,
            'type' => 'courses',
            'remote' => 0,
            'components' => cr_serialize([]),
        ]);
        $this->assertFalse($sqlreport->uses_remote_db());

        foreach (['dbhost', 'dbname', 'dbuser', 'dbpass'] as $setting) {
            set_config($setting, 'x', 'block_configurable_reports');
        }
        $this->assertTrue($sqlreport->uses_remote_db());
        $this->assertFalse($otherreport->uses_remote_db());

        // The option still chooses for the reports that are not SQL.
        $otherreport->config->remote = 1;
        $this->assertTrue($otherreport->uses_remote_db());

        // Not set up completely: SQL reports refuse to run, they never fall back to the main connection.
        set_config('dbpass', '', 'block_configurable_reports');
        $this->assertTrue($sqlreport->uses_remote_db());
        $this->assertTrue($otherreport->uses_remote_db());
        try {
            $this->create_report('SELECT 1', 0);
            $this->fail('The report was created with incomplete remote settings');
        } catch (\moodle_exception $e) {
            $this->assertSame('remotedbnotconfigured', $e->errorcode);
        }

        // Nothing set up: SQL reports use the main connection, in a read only transaction.
        foreach (['dbhost', 'dbname', 'dbuser'] as $setting) {
            set_config($setting, '', 'block_configurable_reports');
        }
        $this->assertFalse($sqlreport->uses_remote_db());

        // The admin setting forces every report to the remote connection.
        set_config('alwaysuseremote', 1, 'block_configurable_reports');
        $this->assertTrue($sqlreport->uses_remote_db());
        $this->assertTrue($otherreport->uses_remote_db());
    }

    /**
     * Dynamic reports go through the same checks.
     */
    public function test_dynamic_reports_are_checked(): void {
        $this->resetAfterTest();

        $sql = "DO E'BEGIN \\x55\\x50\\x44\\x41\\x54\\x45 prefix_config \\x53\\x45\\x54 value=\\x27x\\x27 END' --";
        $record = $this->create_report($sql)->config;
        $report = new \block_configurable_reports\local\dynamic_report($record);

        foreach ([$sql, 'INSERT INTO prefix_config SELECT 1'] as $query) {
            try {
                $report->execute_query($query);
                $this->fail('The query ran');
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['notallowedwords', 'notselectstatement']);
            }
        }
    }
}
