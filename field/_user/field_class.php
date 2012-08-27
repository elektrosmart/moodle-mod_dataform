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
 * @subpackage dataformfield-_user
 * @copyright 2012 Itamar Tzadok
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/mod/dataform/field/field_class.php');

class dataform_field__user extends dataform_field_no_content {

    public $type = '_user';

    /**
     *
     */
    protected function get_sql_compare_text() {
        global $DB;
        // the sort sql here returns the field's sql name       
        return $DB->sql_compare_text($this->get_sort_sql());    
    }

    /**
     * 
     */
    public function get_sort_sql() {
        if ($this->field->internalname != 'picture') {
            if ($this->field->internalname == 'name') {
                $internalname = 'id';
            } else {
                $internalname = $this->field->internalname;
            }
            return 'u.'. $internalname;
        } else {
            return '';
        }
    }

    /**
     *
     */
    public function get_search_sql($search) {
        global $USER;
        // set search value -1 in id and name to user->id
        $internalname = $this->field->internalname;
        if ($internalname == 'id' or $internalname == 'name') {
            if ($search[2] == -1) {
                $search[2] = $USER->id;
            }
        }
        
        return parent::get_search_sql($search);
    }

    /**
     * returns an array of distinct content of the field
     */
    public function get_distinct_content($sortdir = 0) {
        global $CFG, $DB;
        
        $sortdir = $sortdir ? 'DESC' : 'ASC';
        $contentfull = $this->get_sort_sql();
        $sql = "SELECT DISTINCT $contentfull 
                  FROM {user} u 
                       JOIN {dataform_entries} e ON u.id = e.userid 
                 WHERE e.dataid = ? AND  $contentfull IS NOT NULL 
                 ORDER BY $contentfull $sortdir";

        $distinctvalues = array();
        if ($options = $DB->get_records_sql($sql, array($this->df->id()))) {
            if ($this->field->internalname == 'name') {
                $internalname = 'id';
            } else {
                $internalname = $this->field->internalname;
            }
            foreach ($options as $data) {
                $value = $data->{$internalname};
                if ($value === '') {
                    continue;
                }
                $distinctvalues[] = $value;
            }
        }
        return $distinctvalues;
    }
}
