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
 * Session logger for SmartSpe.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\session;

/**
 * Session logger for SmartSpe.
 *
 * Logs user session segments (start/end times) for a given response.
 */
final class logger {
    /** Cap any “abandoned” session at 30 minutes. */
    private const IDLE_CAP = 1800;

    /**
     * Rollover the session for the given response.
     *
     * @param int $responseid The response ID.
     * @return void
     * @throws \dml_exception If a database error occurs.
     */
    public function rollover(int $responseid): void {
        global $DB;

        // Close any open session first.
        if ($open = $DB->get_record('smartspe_response_session', ['responseid' => $responseid, 'timeend' => 0])) {
            $now = time();
            $maxend = (int)$open->timestart + self::IDLE_CAP;
            $end = min($now, $maxend);

            $open->timeend = $end;
            $open->duration = max(0, $end - (int)$open->timestart);
            $DB->update_record('smartspe_response_session', $open);
        }

        // Start a new session.
        $DB->insert_record('smartspe_response_session', (object)[
          'responseid' => $responseid,
          'timestart' => time(),
          'timeend' => 0,
          'duration' => 0,
        ]);
    }

    /**
     * Close any open session for the given response.
     *
     * @param int $responseid The response ID.
     * @return void
     * @throws \dml_exception If a database error occurs.
     */
    public function close_open(int $responseid): void {
        global $DB;
        if ($open = $DB->get_record('smartspe_response_session', ['responseid' => $responseid, 'timeend' => 0])) {
            $end = time();
            $open->timeend = $end;
            $open->duration = max(0, $end - (int)$open->timestart);
            $DB->update_record('smartspe_response_session', $open);
        }
    }
}
