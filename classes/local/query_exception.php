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
 * A SQL report query that the database refused.
 *
 * It only carries the redacted message, never the SQL, the parameters or the whole database message, which the
 * database exception it replaces shows as debugging information. The whole message is only logged on the server.
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_exception extends \moodle_exception {
    /**
     * @param string $redactederror The message of the database, see report_sql::redact_db_error().
     */
    public function __construct(string $redactederror) {
        parent::__construct('queryfailed', 'block_configurable_reports', '', s($redactederror));
    }
}
