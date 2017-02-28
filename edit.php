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
 * Edit an outage.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use auth_outage\dml\outagedb;
use auth_outage\form\outage\edit;
use auth_outage\local\outage;
use auth_outage\local\outagelib;
use auth_outage\output\renderer;

require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/formslib.php');

global $DB;
admin_externalpage_setup('auth_outage_manage');
$PAGE->set_url(new moodle_url('/auth/outage/manage.php'));

$mform = new edit();

if ($mform->is_cancelled()) {
    redirect('/auth/outage/manage.php');
} else if ($outage = $mform->get_data()) {
    $id = outagedb::save($outage);

    $userid = explode(',', $outage->outagemailinglist);
    $userobjects = array();
    foreach ($userid as $uid) {
        $userobjects[] = $DB->get_record('user', array('id'=>$uid));
    }
    $adminuser = $DB->get_record('user', array('id'=>'2'));
    foreach ($userobjects as $userobject) {
        email_to_user($userobject, $adminuser, $outage->title, $outage->description);
    }

    redirect($CFG->wwwroot . '/auth/outage/manage.php#auth_outage_id_'.$id);
}

$clone = optional_param('clone', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
if ($clone && $edit) {
    throw new invalid_parameter_exception('Cannot provide both clone and edit ids.');
}
if ($clone) {
    // Remove outage id to force creating a new one.
    $outage = outagedb::get_by_id($clone);
    $outage->id = null;
    $action = 'outageclone';
} else if ($edit) {
    $outage = outagedb::get_by_id($edit);
    $action = 'outageedit';
} else {
    $config = outagelib::get_config();
    $time = time();
    $outage = new outage([
        'autostart' => $config->default_autostart,
        'starttime' => $time,
        'stoptime' => $time + $config->default_duration,
        'warntime' => $time - $config->default_warning_duration,
        'title' => $config->default_title,
        'outagemailinglist' => $config->mailinglist,
        'description' => $config->default_description,
    ]);
    $action = 'outagecreate';
}

if ($outage == null) {
    throw new invalid_parameter_exception('Outage not found.');
}

$mform->set_data($outage);

$PAGE->navbar->add(get_string($action.'crumb', 'auth_outage'));
echo $OUTPUT->header();
echo renderer::get()->rendersubtitle($action);
$mform->display();
echo $OUTPUT->footer();
