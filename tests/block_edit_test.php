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

namespace block_configurable_reports;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');

/**
 * Tests for the report editing killswitch.
 *
 * @package    block_configurable_reports
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::cr_require_report_editing_enabled
 * @covers     ::cr_import_xml
 */
#[CoversFunction('cr_require_report_editing_enabled')]
#[CoversFunction('cr_import_xml')]
final class block_edit_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * An unset or false setting preserves editing, and turning it off restores editing.
     */
    public function test_editing_is_enabled_by_default_and_can_be_restored(): void {
        unset_config('block_edit', 'block_configurable_reports');
        cr_require_report_editing_enabled();
        set_config('block_edit', 0, 'block_configurable_reports');
        cr_require_report_editing_enabled();
        set_config('block_edit', 1, 'block_configurable_reports');
        try {
            cr_require_report_editing_enabled();
            $this->fail('Editing must be blocked even for administrators.');
        } catch (\moodle_exception $e) {
            $this->assertSame('reporteditingdisabled', $e->errorcode);
        }
        set_config('block_edit', 0, 'block_configurable_reports');
        cr_require_report_editing_enabled();
    }

    /**
     * Direct controller requests, including GET actions and submitted forms.
     *
     * @return array
     */
    public static function blocked_requests_provider(): array {
        return [
            'create' => ['editreport.php', []],
            'edit' => ['editreport.php', ['id' => 1]],
            'save' => ['editreport.php', ['id' => 1], true],
            'duplicate' => ['editreport.php', ['id' => 1, 'duplicate' => 1]],
            'delete' => ['editreport.php', ['id' => 1, 'delete' => 1, 'confirm' => 1]],
            'hide' => ['editreport.php', ['id' => 1, 'hide' => 1]],
            'show' => ['editreport.php', ['id' => 1, 'show' => 1]],
            'component' => ['editcomp.php', ['id' => 1, 'comp' => 'columns']],
            'save component' => ['editcomp.php', ['id' => 1, 'comp' => 'columns'], true],
            'plugin' => ['editplugin.php', ['id' => 1, 'comp' => 'columns', 'pname' => 'userfield']],
            'save plugin' => ['editplugin.php', ['id' => 1, 'comp' => 'columns', 'pname' => 'userfield'], true],
            'delete plugin' => [
                'editplugin.php', ['id' => 1, 'comp' => 'columns', 'pname' => 'userfield', 'cid' => 'c1', 'delete' => 1],
            ],
            'reorder plugin' => [
                'editplugin.php', ['id' => 1, 'comp' => 'columns', 'pname' => 'userfield', 'cid' => 'c1', 'moveup' => 1],
            ],
            'repository import' => ['managereport.php', ['importurl' => 'https://api.github.com/repos/example/reports/a']],
            'upload import' => ['managereport.php', [], true],
        ];
    }

    /**
     * Controller guards reject requests before any report data can change.
     *
     * @dataProvider blocked_requests_provider
     * @param string $script Controller.
     * @param array $params Request parameters (id is replaced with the fixture id).
     * @param bool $post Whether to submit a form.
     */
    #[DataProvider('blocked_requests_provider')]
    public function test_direct_requests_are_blocked(string $script, array $params, bool $post = false): void {
        global $CFG, $DB, $USER, $PAGE, $OUTPUT;

        $record = (object) [
            'courseid' => SITEID,
            'ownerid' => $USER->id,
            'visible' => 1,
            'name' => 'Frozen report',
            'type' => 'users',
            'components' => '',
        ];
        $record->id = $DB->insert_record('block_configurable_reports', $record);
        $before = $DB->get_records('block_configurable_reports');
        set_config('block_edit', 1, 'block_configurable_reports');

        if (isset($params['id'])) {
            $params['id'] = $record->id;
        }
        $params['sesskey'] = sesskey();
        $originalget = $_GET;
        $originalpost = $_POST;
        $originalmethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originalcwd = getcwd();
        try {
            chdir($CFG->dirroot . '/blocks/configurable_reports');
            $_GET = $params;
            $_POST = $post ? $params : [];
            $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
            try {
                include($CFG->dirroot . '/blocks/configurable_reports/' . $script);
                $this->fail('The editing request must be blocked.');
            } catch (\moodle_exception $e) {
                $this->assertSame('reporteditingdisabled', $e->errorcode);
            }
            $this->assertEquals($before, $DB->get_records('block_configurable_reports'));
        } finally {
            chdir($originalcwd);
            $_GET = $originalget;
            $_POST = $originalpost;
            if ($originalmethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $originalmethod;
            }
        }
    }

    /**
     * The shared importer cannot bypass the killswitch.
     */
    public function test_import_is_blocked(): void {
        global $DB, $SITE;

        set_config('block_edit', 1, 'block_configurable_reports');
        try {
            cr_import_xml('<report version="1"><type>users</type><name>Imported</name></report>', $SITE);
            $this->fail('Importing must be blocked.');
        } catch (\moodle_exception $e) {
            $this->assertSame('reporteditingdisabled', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('block_configurable_reports', []));
    }

    /**
     * Both values of the killswitch.
     *
     * @return array
     */
    public static function editing_states_provider(): array {
        return ['enabled' => [false], 'blocked' => [true]];
    }

    /**
     * Management remains available, but only shows editing and import controls when editing is enabled.
     *
     * @dataProvider editing_states_provider
     * @param bool $blocked Whether to block editing.
     */
    #[DataProvider('editing_states_provider')]
    public function test_management_controls(bool $blocked): void {
        global $CFG, $DB, $USER, $PAGE, $OUTPUT, $COURSE, $SITE;

        $id = $DB->insert_record('block_configurable_reports', (object) [
            'courseid' => SITEID,
            'ownerid' => $USER->id,
            'visible' => 1,
            'name' => 'Frozen report',
            'type' => 'users',
            'components' => '',
            'export' => 'csv,',
        ]);
        set_config('block_edit', (int) $blocked, 'block_configurable_reports');
        set_config('crrepository', 'example/reports', 'block_configurable_reports');

        $originalcwd = getcwd();
        ob_start();
        try {
            chdir($CFG->dirroot . '/blocks/configurable_reports');
            include($CFG->dirroot . '/blocks/configurable_reports/managereport.php');
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            chdir($originalcwd);
        }

        $this->assertStringContainsString('viewreport.php?id=' . $id, $html);
        $this->assertStringContainsString('export.php?id=' . $id, $html);
        $this->assertStringContainsString('format=csv', $html);
        foreach (['editreport.php', 'id_crreportcategories', 'name="userfile"'] as $control) {
            if ($blocked) {
                $this->assertStringNotContainsString($control, $html);
            } else {
                $this->assertStringContainsString($control, $html);
            }
        }
    }

    /**
     * Report tabs retain viewing and listing, but hide all editing tabs when blocked.
     *
     * @dataProvider editing_states_provider
     * @param bool $blocked Whether to block editing.
     */
    #[DataProvider('editing_states_provider')]
    public function test_report_tabs(bool $blocked): void {
        global $CFG, $COURSE, $PAGE;

        set_config('block_edit', (int) $blocked, 'block_configurable_reports');
        $PAGE->set_url('/blocks/configurable_reports/viewreport.php', ['id' => 1]);
        $course = $COURSE;
        $report = (object) ['id' => 1];
        $reportclass = (object) ['components' => ['columns', 'filters']];
        $currenttab = 'viewreport';
        ob_start();
        try {
            include($CFG->dirroot . '/blocks/configurable_reports/tabs.php');
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString(get_string('viewreport', 'block_configurable_reports'), $html);
        $this->assertStringContainsString('managereport.php', $html);
        foreach (['editreport.php', 'editcomp.php'] as $control) {
            if ($blocked) {
                $this->assertStringNotContainsString($control, $html);
            } else {
                $this->assertStringContainsString($control, $html);
            }
        }
    }

    /**
     * Existing reports still run with submitted dynamic parameters, without changing their definition.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function test_report_parameters_still_work(): void {
        global $DB, $USER;

        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $components = cr_serialize([
            'customsql' => ['config' => (object) [
                'querysql' => 'SELECT u.id FROM {user} u WHERE 1 = 1 %%DYNAMIC_USER:u.id:=%%',
            ]],
        ]);
        $id = $DB->insert_record('block_configurable_reports', (object) [
            'courseid' => SITEID,
            'ownerid' => $USER->id,
            'visible' => 1,
            'name' => 'Parameterized report',
            'type' => 'sql',
            'components' => $components,
            'remote' => 0,
        ]);
        set_config('block_edit', 1, 'block_configurable_reports');

        $result = external::get_report_data($id, SITEID, [['name' => 'USER', 'value' => (string) $user->id]]);

        $this->assertSame('', $result['warnings']);
        $this->assertEquals([['id' => $user->id]], json_decode($result['data'], true));
        $this->assertSame($components, $DB->get_field('block_configurable_reports', 'components', ['id' => $id]));
    }
}
