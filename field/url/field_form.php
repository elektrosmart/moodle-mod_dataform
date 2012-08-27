<?php
// This file is part of Moodle - http://moodle.org/.
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
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
 
/**
 * @package mod-dataform
 * @subpackage dataformfield-url
 * @copyright 2011 Itamar Tzadok
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->dirroot/mod/dataform/field/field_form.php");

class mod_dataform_field_url_form extends mod_dataform_field_form {

    /**
     *
     */
    function field_definition() {

        $mform =& $this->_form;

    //-------------------------------------------------------------------------------
        $mform->addElement('header', 'fieldattributeshdr', get_string('fieldattributes', 'dataform'));
        
        // use url picker
        $mform->addElement('selectyesno', 'param1', get_string('usepicker', 'dataformfield_url'));

        // force link name
        $mform->addElement('text', 'param2', get_string('forcename', 'dataformfield_url'), array('size'=>'32'));
        $mform->setType('param2', PARAM_NOTAGS);
/*
        // make link
        $mform->addElement('selectyesno', 'param1', get_string('makelink', 'dataformfield_url'));

        // make image
        $mform->addElement('selectyesno', 'param3', get_string('makeimage', 'dataformfield_url'));

        // apply media filter
        $mform->addElement('selectyesno', 'param4', get_string('applymediafilter', 'dataformfield_url'));
*/
    }

}
