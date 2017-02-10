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
 * outage class.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_outage\local;

use coding_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * outage class.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outage {
    /**
     * Outage is before warning period.
     */
    const STAGE_WAITING = 'waiting';

    /**
     * Outage not started but in warning period.
     */
    const STAGE_WARNING = 'warning';

    /**
     * Outage ongoing, it has passed the warning period.
     */
    const STAGE_ONGOING = 'ongoing';

    /**
     * Outage finished, it is after the marked finished time.
     */
    const STAGE_FINISHED = 'finished';

    /**
     * Outage stopped, it is after the stop time and not marked as finished.
     */
    const STAGE_STOPPED = 'stopped';

    /**
     * @var int|null Outage ID (auto generated by the DB).
     */
    public $id = null;

    /**
     * @var bool|null Maintenance mode auto start flag.
     */
    public $autostart = null;

    /**
     * @var int|null Start Time timestamp.
     */
    public $starttime = null;

    /**
     * @var int|null Stop Time timestamp.
     */
    public $stoptime = null;

    /**
     * @var int|null Warning start timestamp.
     */
    public $warntime = null;

    /**
     * @var int|null Finished timestamp, null if not marked as finished yet.
     */
    public $finished = null;

    /**
     * @var string|null Short description of the outage (no HTML).
     */
    public $title = null;

    /**
     * @var string|null Description of the outage (some HTML allowed).
     */
    public $description = null;

    /**
     * @var int|null Moodle User Id that created this outage.
     */
    public $createdby = null;

    /**
     * @var int|null Moodle User Id that last modified this outage.
     */
    public $modifiedby = null;

    /**
     * @var int|null Timestamp of when this outage was last modified.
     */
    public $lastmodified = null;
    /**
     * @var string|null Site admin emails
     */
    public $outagemailinglist = null;

    /**
     * outage constructor.
     * @param stdClass|array|null $data The data for the outage.
     * @throws coding_exception
     */
    public function __construct($data = null) {
        if (is_null($data)) {
            return;
        }
        if (is_object($data)) {
            $data = (array)$data;
        }
        if (!is_array($data)) {
            throw new coding_exception('$data is not an object, an array or null.', $data);
        }

        // Load data from array.
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
        $this->adjust_field_types();
    }

    /**
     * Gets at which stage is this outage.
     * @param int|null $time Null to check the current stage or a timestamp to check for another time.
     * @return int Stage, compare with STAGE_* constants.
     * @throws coding_exception
     */
    public function get_stage($time = null) {
        if ($time === null) {
            $time = time();
        }
        if (!is_int($time) || ($time <= 0)) {
            throw new coding_exception('$time must be an positive int.', $time);
        }

        if (!is_null($this->finished) && ($time >= $this->finished)) {
            return self::STAGE_FINISHED;
        }
        if ($time >= $this->stoptime) {
            return self::STAGE_STOPPED;
        }
        if ($time < $this->warntime) {
            return self::STAGE_WAITING;
        }
        if ($time < $this->starttime) {
            return self::STAGE_WARNING;
        }
        return self::STAGE_ONGOING;
    }

    /**
     * Checks if the outage is active (in warning period or ongoing).
     * @param int|null $time Null to check if the outage is active now or another time to use as reference.
     * @return bool True if outage is ongoing or during the warning period.
     */
    public function is_active($time = null) {
        switch ($this->get_stage($time)) {
            case self::STAGE_WARNING:
            case self::STAGE_ONGOING:
                return true;
            default:
                return false;
        }
    }

    /**
     * Checks if the outage is happening.
     * @param int|null $time Null to check if the outage is happening now or another time to use as reference.
     * @return bool True if outage has started but not yet stopped. False otherwise including if in warning period.
     */
    public function is_ongoing($time = null) {
        return ($this->get_stage($time) == self::STAGE_ONGOING);
    }

    /**
     * Checks if the outage has ended (either marked as finished or after stop time).
     * @param int|null $time Null to check if the outage has already ended or another time to use as reference.
     * @return bool True if outage has been marked as finished after the provided time or it has already stopped.
     */
    public function has_ended($time = null) {
        switch ($this->get_stage($time)) {
            case self::STAGE_FINISHED:
            case self::STAGE_STOPPED:
                return true;
            default:
                return false;
        }
    }

    /**
     * Get the title with properly replaced placeholders such as {{start}} and {{stop}}.
     * @return string Title.
     */
    public function get_title() {
        return $this->replace_placeholders($this->title);
    }

    /**
     * Gets the duration of the outage (start to actual finish, warning not included).
     * @return int|null Duration in seconds or null if not finished.
     */
    public function get_duration_actual() {
        if (is_null($this->finished)) {
            return null;
        }
        return $this->finished - $this->starttime;
    }

    /**
     * Gets the planned duration of the outage (start to planned stop, warning not included).
     * @return int Duration in seconds.
     */
    public function get_duration_planned() {
        return $this->stoptime - $this->starttime;
    }

    /**
     * Get the description with properly replaced placeholders such as {{start}} and {{stop}}.
     * @return string Description.
     */
    public function get_description() {
        return $this->replace_placeholders($this->description);
    }

    /**
     * Gets the warning duration from the outage (from warning time to start time).
     * @return int Warning duration in seconds.
     */
    public function get_warning_duration() {
        return $this->starttime - $this->warntime;
    }

    /**
     * Gets emails from all site admins
     * @return string site admin emails
     */
    public function get_siteadmin_emails() {
        $admins = get_admins();
        $emails = array();
        foreach ($admins as $admin) {
            $emails[] = $admin->email;
        }
        $email = implode(",", $emails);
        return $email;
    }

    /**
     * Returns the input string with all placeholders replaced.
     * @param string $str Input string.
     * @return string Output string.
     */
    private function replace_placeholders($str) {
        return str_replace(
            [
                '{{start}}',
                '{{stop}}',
                '{{duration}}',
            ],
            [
                userdate($this->starttime, get_string('datetimeformat', 'auth_outage')),
                userdate($this->stoptime, get_string('datetimeformat', 'auth_outage')),
                format_time($this->get_duration_planned()),
            ],
            $str
        );
    }

    /**
     * Converts the type of the fields as needed.
     */
    private function adjust_field_types() {
        // Adjust int fields.
        $fs = ['createdby', 'id', 'lastmodified', 'modifiedby', 'starttime', 'stoptime', 'warntime', 'finished'];
        foreach ($fs as $f) {
            $this->$f = ($this->$f === null) ? null : (int)$this->$f;
        }

        // Adjust bool fields.
        $this->autostart = ($this->autostart === null) ? null : (bool)$this->autostart;
    }
}
