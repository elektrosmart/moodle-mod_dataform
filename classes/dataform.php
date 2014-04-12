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
 * @package mod
 * @subpackage dataform
 * @copyright 2012 Itamar Tzadok
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * The Dataform has been developed as an enhanced counterpart
 * of Moodle's Database activity module (1.9.11+ (20110323)).
 * To the extent that Dataform code corresponds to Database code,
 * certain copyrights on the Database module may obtain.
 */

require_once("$CFG->dirroot/grade/grading/lib.php");
 
/**
 * Dataform class
 */
class mod_dataform_dataform {

    const COUNT_ALL = 0;
    const COUNT_LEFT = 3;

    /** @var stdClass The Dataform instance data. */
    protected $_data;
    /** @var stdClass The Course module data. */
    protected $_cm;
    /** @var stdClass The Course data. */
    protected $_course;
    /** @var context_module The activity context object. */
    protected $_context;
    /** @var string Name of current display page. */
    protected $_pagefile = 'view';   

    /** @var array List of good/bad notifications. */
    protected $_notifications;
    
    /** @var int group mode. */
    protected $_groupmode = 0;
    /** @var int Id of current group in the activity. */
    protected $_currentgroup = 0;
    /** @var int Id of the activity current view. */
    protected $_currentview;
    /** @var dataform_renderer the custom renderer for this module */
    private $_renderer;
    
    // STATIC METHODS
    
    /**
     * Returns dataform instance.
     * Throws an exception on error.
     *
     * @param int $dataformid The id of the dataform instance to return
     * @param int $cmid The id of instance's course module
     * @param bool $autologinguest
     * @return mod_dataform_dataform
     */
    public static function instance($dataformid, $cmid = 0, $autologinguest = false) {
        global $DB;
        
        static $instances = array();

        if (!$dataformid) {
            if (!$cmid or !$dataformid = $DB->get_field('course_modules', 'instance', array('id' => $cmid))) {
                throw new moodle_exception('invalidcoursemodule', 'dataform', null, null, "Cm id: $cmid");
            }
        }
        
        if (empty($instances[$dataformid])) {
            $instances[$dataformid] = new mod_dataform_dataform($dataformid, null, $autologinguest);
        }
        return $instances[$dataformid];
    }
    
    /**
     * Returns dataform content for inline display.
     * Used in {@link dataform_cm_info_view()} and in {@link block_dataform_view::get_content()}.
     *
     * @param int $dataformid The id of the dataform whose content should be displayed
     * @param int $viewid The id of the dataform's view whose content should be displayed
     * @return string
     */
    public static function get_content_inline($dataformid, $viewid, $filterid = null) {
        $df = new mod_dataform_dataform($dataformid, null, true);
        $viewman = new mod_dataform_view_manager($dataformid);

        if ($view = $viewman->get_view_by_id($viewid)) {
            $params = array(
                    'js' => true,
                    'css' => true,
                    'completion' => true,
                    'comments' => true,
                    'nologin' => true,
            );        
            $pageoutput = $df->set_page('external', $params);
            
            if (!empty($filterid)) {
                $view->set_viewfilter(array('id' => $filterid));
            } else {       
                $view->set_viewfilter();
            }
            $viewcontent = $view->display();
            return "$pageoutput\n$viewcontent";
        }
        return null;
    }

    /**
     * Returns dataform content embedded in iframe for inline display.
     * Used in {@link dataform_cm_info_view()} and in {@link block_dataform_view::get_content()}.
     *
     * @param int $dataformid The id of the dataform whose content should be displayed
     * @param int $viewid The id of the dataform's view whose content should be displayed
     * @return string
     */
    public static function get_content_embedded($dataformid, $viewid, $filterid = null, $containerstyle = null) {
        global $DB;
        
        if (!$dataform = $DB->get_record('dataform', array('id' => $dataformid))) {
            return null;
        }
        
        // Set the url for the iframe
        $urlparams = array('d' => $dataformid, 'view' => $viewid);
        if ($filterid) {
            $urlparams['filter'] = $filterid;
        }        
        $dataurl = new moodle_url('/mod/dataform/embed.php', $urlparams);
        
        // The iframe
        $params = array();
        $dataformname = str_replace(' ', '_', $dataform->name);
        $cssclass = "dataform-$dataformname-inline dataform-embedded";
        $params['src'] = $dataurl;
        $params['class'] = $cssclass;

        // Compile any iframe styles
        if (!empty($containerstyle)) {
            $styles = array();
            if ($arr = explode(';', $containerstyle)) {
                foreach ($arr as $rule) {
                    if ($rule = trim($rule) and strpos($rule, ':')) {
                        list($attribute, $value) = array_map('trim', explode(':', $rule));
                        if ($value !== '') {
                            $styles[$attribute] = "$value;";
                        }
                    }
                }                
            }    
            $stylestr = implode('',array_map(function($a, $b){return "$a: $b";}, array_keys($styles), $styles));
            $params['style'] = $stylestr;
            
            // Add scrolling attr
            if (!empty($styles['overflow']) and $styles['overflow'] == 'hidden;') {
                $params['scrolling'] = 'no';
            }
        }

        return html_writer::tag('iframe', null, $params);
    }

 
    
    // CONSTRUCTOR
    
    /**
     * constructor
     */
    public function __construct($d = 0, $id = 0, $autologinguest = false) {
        global $DB;

        // initialize from dataform id or object
        if ($d) {
            if (is_object($d)) { // try object first
                $this->_data = $d;
            } else if (!$this->_data = $DB->get_record('dataform', array('id' => $d))) {
                throw new moodle_exception('invaliddataform', 'dataform', null, null, "Dataform id: $d");
            }
            if (!$this->_course = $DB->get_record('course', array('id' => $this->_data->course))) {
                throw new moodle_exception('invalidcourse', 'dataform', null, null, "Course id: {$this->_data->course}");
            }
            if (!$this->_cm = get_coursemodule_from_instance('dataform', $this->id, $this->course->id)) {
                throw new moodle_exception('invalidcoursemodule', 'dataform', null, null, "Cm id: {$this->id}");
            }
        // initialize from course module id
        } else if ($id) {
            if (!$this->_cm = get_coursemodule_from_id('dataform', $id)) {
                throw new moodle_exception('invalidcoursemodule '. $id, 'dataform', null, null, "Cm id: $id");
            }
            if (!$this->_course = $DB->get_record('course', array('id' => $this->_cm->course))) {
                throw new moodle_exception('invalidcourse', 'dataform', null, null, "Course id: {$this->_cm->course}");
            }
            if (!$this->_data = $DB->get_record('dataform', array('id' => $this->cm->instance))) {
                throw new moodle_exception('invaliddataform', 'dataform', null, null, "Dataform id: {$this->_cm->instance}");
            }
        }

        // get context
        $this->_context = \context_module::instance($this->_cm->id);

        // set groups
        $this->_groupmode = groups_get_activity_groupmode($this->_cm);
        $this->_currentgroup = groups_get_activity_group($this->_cm, true);
    }

    /**
     * Magic property method
     *
     * Attempts to call a set_$key method if one exists otherwise falls back
     * to simply set the property
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value) {
        if (method_exists($this, 'set_'.$key)) {
            $this->{'set_'.$key}($value);
        }
        $this->_data->$key = $value;
    }

    /**
     * Magic get method
     *
     * Attempts to call a get_$key method to return the property and ralls over
     * to return the raw property
     *
     * @param str $key
     * @return mixed
     */
    public function __get($key) {
        if (method_exists($this, 'get_'.$key)) {
            return $this->{'get_'.$key}();
        }
        if (isset($this->_data->{$key})) {
            return $this->_data->{$key};
        }
        return null;
    }


    /**
     * Sets the dataform page.
     *
     * @param string $pagefile current page file
     * @param array $params 
     */
    public function set_page($pagefile = 'view', $params = null) {
        global $CFG, $PAGE, $USER, $OUTPUT;
        
        $htmloutput = '';

        $this->_pagefile = ($pagefile == 'external' ? 'view' : $pagefile);
        
        $params = (object) $params;
        $urlparams = array();
        if (!empty($params->urlparams)) {
            foreach ($params->urlparams as $param => $value) {
                if ($value != 0 and $value != '') {
                    $urlparams[$param] = $value;
                }
            }
        }
        // Make sure there is at least dataform id param
        $urlparams['d'] = $this->id;
        // Get the edit mode
        $urlparams['edit'] = optional_param('edit', 0, PARAM_BOOL);

        // MANAGER
        $manager = has_capability('mod/dataform:managetemplates', $this->context);

        // LOGIN REQUIREMENT
        if (empty($params->nologin)) {
            // guest auto login
            $autologinguest = false;
            if ($pagefile == 'view' or $pagefile == 'embed' or $pagefile == 'external') {
                $autologinguest = true;

            }
            
            // require login
            require_login($this->course->id, $autologinguest, $this->cm);
        }


        // RENEW if requested
        if ($manager and !empty($urlparams['renew']) and confirm_sesskey()) {
            $this->reset();
        }

        // RSS
        if (!empty($params->rss)) {
            $this->set_page_rss();
        }
        
        // COMMENTS
        if (!empty($params->comments)) {
            require_once("$CFG->dirroot/comment/lib.php");
            comment::init();
        }

        $externalpage = ($pagefile == 'external');
        
        // EDITING (not on external pages)
        $this->set_page_editing_mode($pagefile, $urlparams);
        
        
        // AUTO REFRESH
        if (!empty($urlparams['refresh']) and !$externalpage) {
           $PAGE->set_periodic_refresh_delay($urlparams['refresh']);
        }

        // PAGE LAYOUT
        if (!empty($params->pagelayout) and !$externalpage) {
            $PAGE->set_pagelayout($params->pagelayout);
        }
            
        // COMPLETION Mark as viewed
        if (!empty($params->completion) and !$externalpage) {
            require_once($CFG->libdir . '/completionlib.php');
            $completion = new completion_info($this->course);
            $completion->set_module_viewed($this->cm);
        }

        // CSS
        $htmloutput .=  !empty($params->css) ? $this->set_page_css() : '';
        
        // JS
        if (!empty($params->js)) {
            $this->set_page_js();
        }

        $PAGE->set_title($this->name);
        $PAGE->set_heading($this->course->fullname);            
        
        // Set current view and view's page requirements only if activity ready (with default view)
        // and access allowed
        if ($this->defaultview) {
            $currentviewid = !empty($urlparams['view']) ? $urlparams['view'] : $this->defaultview;

            // Ensure access allowed
            $params = array('dataformid' => $this->id, 'viewid' => $currentviewid);
            if (mod_dataform\access\view_access::validate($params)) {
                if ($this->_currentview = $this->view_manager->get_view_by_id($currentviewid)) {
                    $this->_currentview->set_page($pagefile, $urlparams);
                }
            }
        }

        // Notifications for not ready, early and past due.
        $this->set_notification_activity_not_ready($pagefile);
        
        return $htmloutput;
    }

    /**
     * Delegates data processing to current view if exists and can be accessed.
     * Otherwise does nothing.
     *
     * @return void
     */
    public function process_data() {
        if ($this->currentview) {
            $this->currentview->process_data();
        }
    }

    /**
     * Returns display content from current view if exists.
     *
     * @return string HTML fragment
     */
    public function display() {
        if ($this->currentview) {
            return $this->currentview->display();
        }
        return null;
    }

    /**
     * Lazy load the page renderer and expose the renderer to plugins.
     *
     * @return dataform_renderer
     */
    public function get_renderer(moodle_page $page = null) {
        if ($this->_renderer) {
            return $this->_renderer;
        }
        
        global $PAGE;
        $this->_renderer = $PAGE->get_renderer('mod_dataform');
        $this->_renderer->set_df($this->id);
        return $this->_renderer;
    }

    
    /**
     * Sets the dataform page css.
     *
     * @return string 
     */
    protected function set_page_css() {
        global $PAGE;
        
        $cssurls = array();
        // js includes from the js template
        if ($this->cssincludes) {
            foreach (explode("\n", $this->cssincludes) as $cssinclude) {
                $cssinclude = trim($cssinclude);
                if ($cssinclude) {
                    $cssurls[] = new moodle_url($cssinclude);
                }
            }
        }
        // Uploaded css files
        $fs = get_file_storage();
        if ($files = $fs->get_area_files($this->context->id, 'mod_dataform', 'css', 0, 'sortorder', false)) {
            $path = "/{$this->context->id}/mod_dataform/css/0";
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $cssurls[] = moodle_url::make_file_url('/pluginfile.php', "$path/$filename");
            }
        }                
        // css code from the css template
        if ($this->css) {
            $cssurls[] = new moodle_url('/mod/dataform/css.php', array('d' => $this->id));
        }

        // Add the css to the page if we're in the right state
        if ($PAGE->state == moodle_page::STATE_BEFORE_HEADER) {
            foreach ($cssurls as $cssurl) {
                $PAGE->requires->css($cssurl);
            }
            return '';
        }

        // CSS cannot be required after head, so in that case return the tags
        // and they will be added to the html.
        $csstags = '';       
        $attrs = array('rel' => 'stylesheet', 'type' => 'text/css');
        foreach ($cssurls as $cssurl) {
            $attrs['href'] = $cssurl;
            $csstags .= html_writer::empty_tag('link', $attrs). "\n";
            unset($attrs['id']);
        }
        return $csstags;
    }

    /**
     * Sets the dataform page js.
     *
     * @return void
     */
    protected function set_page_js() {
        global $PAGE;

        $jsurls = array();

        // js includes from the js template
        if ($this->jsincludes) {
            foreach (explode("\n", $this->jsincludes) as $jsinclude) {
                $jsinclude = trim($jsinclude);
                if ($jsinclude) {
                    $jsurls[] = new moodle_url($jsinclude);
                }
            }
        }
        // Uploaded js files
        $fs = get_file_storage();
        if ($files = $fs->get_area_files($this->context->id, 'mod_dataform', 'js', 0, 'sortorder', false)) {
            $path = "/{$this->context->id}/mod_dataform/js/0";
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $jsurls[] = moodle_url::make_file_url('/pluginfile.php', "$path/$filename");
            }
        }                
        // js code from the js template
        if ($this->js) {
            $jsurls[] = new moodle_url('/mod/dataform/js.php', array('d' => $this->id));
        }

        foreach ($jsurls as $jsurl) {
            $PAGE->requires->js($jsurl);
        }
    }
       
    /**
     * Sets the dataform page rss.
     *
     * @return void
     */
    protected function set_page_rss() {
        global $CFG;
        
        if (!empty($CFG->enablerssfeeds) and !empty($CFG->dataform_enablerssfeeds)) {
                
            // Get rss views and add http header for each one
            if ($rssviews = $this->get_rss_views()) {
                require_once("$CFG->libdir/rsslib.php");
                foreach ($rssviews as $viewid => $view) {
                    $rsstitle = $view->get_rss_header_title(); //format_string($this->course->shortname) . ': %fullname%';
                    $componentinstance = $this->id. "/$viewid"; 
                    rss_add_http_header($this->context, 'mod_dataform', $componentinstance, $rsstitle);
                }
            }
        }
    }

    /**
     * Sets the dataform page editing mode (not on external pages).
     *
     * @param string $pagefile The activity page file (e.g. view, embed etc.)
     * @param array $urlparams The url params
     * @return void
     */
    protected function set_page_editing_mode($pagefile, $urlparams) {
        global $PAGE, $USER;
        
        if ($pagefile == 'external') {
            return;
        }
        
        // Is user editing
        $PAGE->set_url("/mod/dataform/$pagefile.php", $urlparams);
        
        // Editing button (omit in embedded dataforms)
        if ($pagefile != 'embed' and $PAGE->user_allowed_editing()) {
             // teacher editing mode
            if ($urlparams['edit'] != -1) {
                $USER->editing = $urlparams['edit'];
            }

            $buttons = '<table><tr><td><form method="get" action="'. $PAGE->url. '"><div>'.
                '<input type="hidden" name="d" value="'.$this->id.'" />'.
                '<input type="hidden" name="edit" value="'.($PAGE->user_is_editing()?0:1).'" />'.
                '<input type="submit" value="'.get_string($PAGE->user_is_editing()?'blockseditoff':'blocksediton').'" /></div></form></td></tr></table>';
            $PAGE->set_button($buttons);
        }
    }

    /**
     * Sets the dataform not ready notification. Template managers will see links to management tabs.
     *
     * @param string $pagefile The activity page file (e.g. view, embed etc.)
     * @return void
     */
    protected function set_notification_activity_not_ready($pagefile) {
        $manager = has_capability('mod/dataform:managetemplates', $this->context);
        $thisid = $this->id;
        $this->notifications = array();
        
        // Not ready
        if (!$this->defaultview) {
            if ($manager) {
                if (!$this->view_manager->has_views()) {
                    if ($pagefile == 'view' or $pagefile == 'embed') {
                        $this->notifications = array('problem' => array('getstarted' => get_string('getstarted', 'dataform')));
                        // Add presets
                        $presetslink = html_writer::link(new moodle_url('/mod/dataform/preset/index.php', array('d' => $thisid)), get_string('presets', 'dataform'));
                        $this->notifications = array('success' => array('addpresets' => get_string('getstartedpresets', 'dataform', $presetslink)));
                        // Add fields
                        $fieldslink = html_writer::link(new moodle_url('/mod/dataform/field/index.php', array('d' => $thisid)), get_string('fields', 'dataform'));
                        $this->notifications = array('success' => array('addfields' => get_string('getstartedfields', 'dataform', $fieldslink)));
                        // Add views
                        $viewslink = html_writer::link(new moodle_url('/mod/dataform/view/index.php', array('d' => $thisid)), get_string('views', 'dataform'));
                        $this->notifications = array('success' => array('addviews' => get_string('getstartedviews', 'dataform', $viewslink)));
                    }
                } else if (!$this->defaultview) {
                    $linktoviews = html_writer::link(new moodle_url('/mod/dataform/view/index.php', array('d' => $thisid)), get_string('views', 'dataform'));
                    $this->notifications = array('problem' => array('defaultview' => get_string('viewnodefault','dataform', $linktoviews)));
                }
            } else {
                $this->notifications = array('problem' => array('dataformnotready' => get_string('dataformnotready','dataform')));        
            }
        }
        
        // Early
        if ($this->is_early()) {
            $this->notifications = array('info' => array('dataformearly' => get_string('dataformearly','dataform', userdate($this->timeavailable))));
        }
        
        // Late
        if ($this->is_past_due()) {
            $this->notifications = array('info' => array('dataformpastdue' => get_string('dataformpastdue','dataform', userdate($this->timedue))));
        }
    }


    // UPDATERS

    /**
     * Updates Dataform settings.
     *
     * @return bool
     */
    public function update($params, $notify = '') {
        global $DB;

        if ($params) {
            $updatedf = false;
            foreach ($params as $key => $value) {
                $oldvalue = $this->{$key} ? $this->{$key} : null; 
                $newvalue = !empty($value) ? $value : null; 
                if ($newvalue != $oldvalue) {
                    $this->{$key} = $value;                    
                    $updatedf = true;
                }
            }
            
            if ($updatedf) {
                if ($DB->update_record('dataform', $this->data)) {
                    if ($notify === true) {
                        // Do nothing for now
                    } else if ($notify) {
                        $this->notifications = array('success' => array('' => $notify));;
                    }
                    
                    // Trigger event
                    $eventparams = array(
                        'objectid' => $this->cm->id,
                        'context' => $this->context,
                        'other' => array(
                            'modulename' => 'dataform',
                            'name' => $this->name,
                            'instanceid' => $this->id,
                        )
                    );
                    $event = \core\event\course_module_updated::create($eventparams);
                    $event->add_record_snapshot('course_modules', $this->cm);
                    $event->add_record_snapshot('course', $this->course);
                    $event->add_record_snapshot('dataform', $this->data);
                    $event->trigger();
                    
                } else {
                    if ($notify === true) {
                        $this->notifications = array('problem' => array('dfupdatefailed' => get_string('dfupdatefailed', 'dataform')));
                    } else if ($notify) {
                        $this->notifications = array('problem' => array('' => $notify));;
                    }
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Deletes the Dataform instance completely.
     *
     * @throws dml_exception A DML specific exception is thrown for any errors.
     * @return bool
     */
    public function delete() {
        global $DB;

        // First reset everything
        $this->reset();
        
        // Delete the instance itself
        return $DB->delete_records('dataform', array('id' => $this->id));
    }

    /**
     * Deletes all this instance's structure and user data and resets its settings to defaults.
     *
     * @return bool Always true
     */
    protected function reset() {
        // Must have manage templates capability
        require_capability('mod/dataform:managetemplates', $this->context);
    
        // Reset settings
        $this->reset_settings();
    
        // Delete all component items
        mod_dataform_field_manager::instance($this->id)->delete_fields();
        mod_dataform_view_manager::instance($this->id)->delete_views();
        mod_dataform_filter_manager::instance($this->id)->delete_filters();
        mod_dataform_filter_manager::instance($this->id)->delete_advanced_filters();
        mod_dataform_access_manager::instance($this->id)->delete_rules();
        mod_dataform_notification_manager::instance($this->id)->delete_rules();
        
        // Reset user data
        $this->reset_user_data();
        
        // Delete remaining files (e.g. css, js)
        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'mod_dataform');
        
        // Clean up gradebook
        dataform_grade_item_delete($this->data);
        
        // Refresh events
        dataform_refresh_events($this->course->id);
        
        // Delete context content
        $this->context->delete_content();
        
        return true;
    }

    /**
     * Resets the Dataform settings.
     *
     * @return bool
     */
    protected function reset_settings() {
        global $DB;
        
        $data = new \stdClass;
        $data->name = get_string('dataformnew', 'dataform');
        $data->intro = null;
        $data->inlineview = 0;
        $data->embedded = 0;
        $data->timemodified = time();
        $data->timeavailable = 0;
        $data->timedue = 0;
        $data->timeinterval = 0;
        $data->intervalcount = 1;
        $data->grade = 0;
        $data->gradecalc = null;
        $data->maxentries = -1;
        $data->entriesrequired = 0;
        $data->grouped = 0;
        $data->anonymous = 0;
        $data->individualized = 0;
        $data->timelimit = -1;
        $data->css = null;
        $data->cssincludes = null;
        $data->js = null;
        $data->jsincludes = null;
        $data->defaultview = 0;
        $data->defaultfilter = 0;
        
        // Reset grading manager
        $gradingman = get_grading_manager($this->context, 'mod_dataform', 'activity');
        $gradingman->set_active_method(null);
        
        return $this->update($data);
    }

    /**
     * Deletes all user data in the activity.
     *
     * @return bool Always true.
     */
    protected function reset_user_data($userid = null) {
        // Must have manage templates capability
        require_capability('mod/dataform:managetemplates', $this->context);

        $entryman = new mod_dataform_entry_manager($this->id);
        $entryman->delete_entries($userid);

        // Reset grades
        dataform_update_grades($this->data, $userid);
        
        return true;
    }



    // GETTERS
    
    /**
     *
     */
    public function get_data() {
        return $this->_data;
    }

    /**
     *
     */
    public function get_course() {
        return $this->_course;
    }

    /**
     *
     */
    public function get_cm() {
        return $this->_cm;
    }

    /**
     *
     */
    public function get_context() {
        return $this->_context;
    }

    /**
     *
     */
    public function get_pagefile() {
        return $this->_pagefile;
    }

    /**
     *
     */
    public function get_currentview() {
        return $this->_currentview;
    }

    /**
     *
     */
    public function get_groupmode() {
        return $this->_groupmode;
    }

    /**
     *
     */
    public function get_currentgroup() {
        return $this->_currentgroup;
    }

    /**
     *
     */
    public function get_notifications() {
        return $this->_notifications;
    }


    /**
     * Returns the view manager for the Dataform instance.
     *
     * @return mod_dataform_view_manager
     */
    public function get_view_manager() {
        return mod_dataform_view_manager::instance($this->id);
    }

    /**
     * Returns the field manager for the Dataform instance.
     *
     * @return mod_dataform_field_manager
     */
    public function get_field_manager() {
        return mod_dataform_field_manager::instance($this->id);
    }

    /**
     * Returns the filter manager for the Dataform instance.
     *
     * @return mod_dataform_filter_manager
     */
    public function get_filter_manager() {
        return mod_dataform_filter_manager::instance($this->id);
    }

    /**
     * Returns the grading manager for the Dataform instance.
     *
     * @return grading_manager
     */
    public function get_grading_manager() {
        return get_grading_manger($this->context, 'mod_dataform', 'activity');
    }



    // SETTERS

    /**
     *
     */
    public function set_notifications(array $notifications) {
        if (!$notifications) {
            $this->_notifications = null;
        } else {
            if (!$this->_notifications) {
                $this->_notifications = array();
            }
            foreach ($notifications as $key => $notes) {
                if (empty($this->_notifications[$key])) {
                    $this->_notifications[$key] = array();
                }
                $this->_notifications[$key] = array_merge($this->_notifications[$key], $notes);
            }
        }
    }


    
    /**
     *
     */
    public function get_entries_count($type, $userid = 0) {
        global $DB;
        
        $params = array('id'  => $this->id);
        if ($userid) {
            $params['userid'] = $userid;
        }
        
        switch ($type) {
            case self::COUNT_ALL:
                $count = $DB->count_records('dataform_entries', $params);
                break;
        
            case self::COUNT_LEFT:
                $count = '---';
                break;
                
            default: 
                $count = '---';
        
        }

        return $count;
    }
    
    /**
     *
     */
    public function get_entries_count_per_user($type = self::COUNT_ALL, $userid = 0) {
        global $DB;
        
        $sql = "
            SELECT
                userid AS id,
                userid,
                COUNT(id) AS numentries
            FROM
                {dataform_entries}
            WHERE
                dataid = ?
        ";
        $where = '';
        $groupby = " GROUP BY userid ";
        $params = array('id'  => $this->id);
        
        if ($userid) {
            $where .= " AND userid = ? ";
            $params[] = $userid;
        }
        
        switch ($type) {
            case self::COUNT_ALL:
                return $DB->get_records_sql($sql. $where. $groupby, $params);
        
            case self::COUNT_APPROVED:
                $where .= " state = ? ";
                $params['state'] = 1;
                return $DB->get_records_sql($sql. $where. $groupby, $params);
        
            case self::COUNT_UNAPPROVED:
                $where .= " state = ? ";
                $params['state'] = 0;
                return $DB->get_records_sql($sql. $where. $groupby, $params);
        
            case self::COUNT_LEFT:
                break;
                
            default: 
        
        }

        return null;
    }
    


    //  GRADING

    /**
     *
     */
    public function get_gradebook_users(array $userids = null) {
        global $DB, $CFG;

        // get the list of users by gradebook roles
        if (!empty($CFG->gradebookroles)) {
            $gradebookroles = explode(",", $CFG->gradebookroles);

        } else {
            $gradebookroles = '';
        }

        if (!empty($CFG->enablegroupings) and $this->cm->groupmembersonly) {
            $groupingsusers = groups_get_grouping_members($this->cm->groupingid, 'u.id', 'u.id');
            $gusers = $groupingsusers ? array_keys($groupingsusers) : null;
        }
        
        if (!empty($userids)) {
            if (!empty($gusers)) {
                $gusers = array_intersect($userids, $gusers);
            } else {
                $gusers = $userids;
            }
        }           
                    
        if (isset($gusers)) {
            if (!empty($gusers)) {
                list($inuids, $params) = $DB->get_in_or_equal($gusers);
                return get_rolentryusers(
                    $gradebookroles,
                    $this->context,
                    true,
                    user_picture::fields('u'),
                    'u.lastname ASC', 
                    true,
                    $this->currentgroup,
                    '',
                    '',
                    "u.id $inuids",
                    $params
                );
            } else {
                return null;
            }
        } else {
            return get_role_users(
                $gradebookroles,
                $this->context,
                true,
                'u.id, u.lastname, u.firstname',
                'u.lastname ASC', 
                true,
                $this->currentgroup
            );
        }
    }


    /**
     * Returns user's grades in the dataform instance per the instance grade settings.
     * For simple direct grading can use grade calculation to automate the grading.
     * Simple direct with no calculation returns nothing because the grades are overriden 
     * in the gradebook and cannot be changed from the activity.
     *
     * @global object CFG
     * @param int $userid optional user id, 0 means all users
     * @return array array of grades, false if none
     */
    public function get_user_grades($userid = 0) {
        global $CFG;
        
        if (!$this->grade) {
            return null;
        }

        // Advanced grading
        $gradingman = get_grading_manager($this->context, 'mod_dataform', 'activity');
        $controller = $gradingman->get_active_controller();
        if (!empty($controller)) {
            return null;
        }
        // Calculated grade
        if ($this->gradecalc) {
            return $this->get_user_grades_calculated($userid);
        }
        
        return false;
    }

    /**
     * Returns user's calculated grades in the dataform instance.
     *
     * @param int $userid The user id whose grades should be retrieved or 0 for all grades
     */
    public function get_user_grades_calculated($userid = 0) {
        global $CFG;
        
        if (!$this->gradecalc) {
            return null;
        }
        
        require_once("$CFG->libdir/mathslib.php");
        $formula = $this->gradecalc;
        
        $patterns = array();
        $users = array();
        $grades = array();
        
        // Num entries pattern 
        if (strpos($formula, '##numentries##') !== false) {
            $patterns['##numentries##'] = 0;
            if ($numentries = $this->get_entries_count_per_user(self::COUNT_ALL, $userid)) {
                foreach ($numentries as $userid => $count) {
                    if (empty($users[$userid])) {
                        $users[$userid] = array();
                    }
                    $users[$userid]['##numentries##'] = $count->numentries;
                }    
            }
        }

        // Extract field patterns from the formula
        if (preg_match_all("%\[\[[^\]]+\]\]%", $formula, $matches)) {
            foreach ($matches[0] as $pattern) {
                $patterns[$pattern] = 0;

                // Get the field from the pattern
                if (!$field = $this->field_manager->get_field_by_pattern($pattern)) {
                    continue;
                }
                
                // The field must be an instance of interface grading
                if (!($field instanceof mod_dataform\interfaces\grading)) {
                    continue;
                }
                
                // Get user values for the pattern
                if (!$values = $field->get_user_values($pattern, $userid)) {
                    continue;
                }

                // Register pattern values for users
                foreach ($values as $userid => $value) {
                    if (empty($users[$userid])) {
                        $users[$userid] = array();
                    }
                    $users[$userid][$pattern] = $value;
                }
            }
        }
        
        // For each user calculate the formula and create a grade object
        foreach ($users as $userid => $values) {
            $replacements = array_merge($patterns, $values);
            $calculation = str_replace(array_keys($replacements), $replacements, $formula);

            $calc = new calc_formula("=$calculation");
            $result = $calc->evaluate();
            // false as result indicates some problem
            if ($result !== false) {
                $grades[$userid] = (object) array(
                    'id' => $userid,
                    'userid' => $userid,
                    'rawgrade' => $result
                );
            }
        }
        return $grades;
    }

    /**
     * Returns user's calculated grades in the dataform instance.
     *
     * @param int $userid The user id whose grades should be retrieved or 0 for all grades
     */
    public function is_grading_num_entries() {
        global $CFG;
        
        if (!$this->grade) {
            return false;
        }
        
        // Look for numentries in gradecalc.
        if (!$this->gradecalc or strpos($this->gradecalc, '##numentries##') === false) {
            return false;
        }

        // There is numentries in gradecalc so check the grading method to see if calc is used. 
        require_once("$CFG->dirroot/grade/grading/lib.php");
        $gradingman = get_grading_manager($this->context, 'mod_dataform', 'activity');
        $controller = $gradingman->get_active_controller();
        if (!empty($controller)) {
            return false;
        }
        
        return true;
    }


    //  USER

    /**
     * has a user reached the max number of entries?
     * if interval is set then required entries, max entrie etc. are relative to the current interval
     * @return boolean
     */
    public function user_at_max_entries($perinterval = false) {
        if ($this->maxentries < 0 or has_capability('mod/dataform:manageentries', $this->context)) {
            return false;
        } else if ($this->maxentries == 0) {
            return true;
        } else {
            return ($this->user_num_entries($perinterval) >= $this->maxentries);
        }
    }

    /**
     * Check the number of entries required for the activity.
     *
     * @returrn int Number of entries required
     */
    public function user_require_entries($options = null) {
        global $OUTPUT;
        
        if (!$this->entriesrequired) {
            return 0;
        }

        if (has_capability('mod/dataform:manageentries', $this->context)) {
            return 0;
        }

        $numentries = $this->user_num_entries();
        if ($numentries < $this->entriesrequired) {
            $entriesleft = $this->entriesrequired - $numentries;
            if (!empty($options['notify'])) {
                echo $OUTPUT->notification(get_string('entrieslefttoadd', 'dataform', $entriesleft));
            }
            return $entriesleft;
        }
        return 0;
    }

    /**
     * returns the number of entries already made by this user; defaults to all entries
     * @param global $CFG, $USER
     * @param boolean $perinterval
     * output int
     */
    public function user_num_entries($perinterval = false) {
        global $USER, $CFG, $DB;

        static $numentries = null;
        static $numentries_intervaled = null;

        if (!$perinterval and !is_null($numentries)) {
            return $numentries;
        }

        if ($perinterval and !is_null($numentries_intervaled)) {
            return $numentries_intervaled;
        }        

        $params = array();
        $params[] = $this->id;

        $and_whereuserorgroup = '';
        $and_whereinterval = '';
        
        // go by user
        if (!$this->grouped) {
            $and_whereuserorgroup = " AND userid = ? ";
            $params[] = $USER->id;            
        // go by group
        } else {
            $and_whereuserorgroup = " AND groupid = ? ";
            // if user is trying add an entry and got this far
            //  the user should belong to the current group
            $params[] = $this->currentgroup;                    
        }
        
        // time interval
        if ($timeinterval = $this->timeinterval and $perinterval) {
            $timeavailable = $this->timeavailable;
            $elapsed = time() - $timeavailable;
            $intervalstarttime = (floor($elapsed / $timeinterval) * $timeinterval) + $timeavailable;
            $intervalendtime = $intervalstarttime + $timeinterval;
            $and_whereinterval = " AND timecreated >= ? AND timecreated < ? ";
            $params[] = $intervalstarttime;
            $params[] = $intervalendtime;

        }

        $select = "dataid = ? $and_whereuserorgroup $and_whereinterval";
        $entriescount = $DB->count_records_select('dataform_entries', $select, $params);
        
        if (!$perinterval) {
            $numentries = $entriescount;
        } else {
            $numentries_intervaled = $entriescount;
        }        

        return $entriescount;        
    }

    /**
     * Returns a list of manage permissions for management areas in the activity.
     *
     * @return array Associative array area => bool (has capability)
     */
    public function get_user_manage_permissions() {
        static $manager = null;

        if ($manager === null) {
            $manager = array();
        
            $manager['templates'] = has_capability('mod/dataform:managetemplates', $this->context);
            $manager['views'] = has_capability('mod/dataform:manageviews', $this->context);
            $manager['fields'] = has_capability('mod/dataform:managefields', $this->context);
            $manager['filters'] = has_capability('mod/dataform:managefilters', $this->context);
            $manager['access'] = has_capability('mod/dataform:manageaccess', $this->context);
            $manager['notifications'] = has_capability('mod/dataform:managenotifications', $this->context);
            $manager['css'] = has_capability('mod/dataform:managecss', $this->context);
            $manager['js'] = has_capability('mod/dataform:managejs', $this->context);
            $manager['tools'] = has_capability('mod/dataform:managetools', $this->context);
            $manager['presets'] = has_capability('mod/dataform:managepresets', $this->context);
            
            // Empty if no permissions
            if (!in_array(true, $manager)) {
                $manager = array();
            }
        }
        
        return $manager;
    }

    /**
     * Returns a list of manage permissions for management areas in the activity.
     *
     * @return array Associative array area => bool (has capability)
     */
    public function require_manage_permission($area = 'templates') {
        if (!$manager = $this->user_manage_permissions or empty($manager[$area])) {
            throw new \moodle_exception('accessdenied');
        }
    }

    
    // TIMING

    /**
     * Returns true if the activity has a designated availability time
     * and now is before that time.
     *
     * @return bool
     */
    public function is_early() {
        $now = time();
        if ($this->timeavailable and $now < $this->timeavailable) {
            return true;
        }
        return false;
    }
    
    /**
     * Returns true if the activity has a designated due time
     * and now is after that time.
     *
     * @return bool
     */
    public function is_past_due() {
        $now = time();
        
        // No intervals
        if ($timedue = $this->timedue and $now > $timedue) {
            return true;
        }
        
        // With intervals
        if ($this->intervalcount > 1) {
            return ($now > ($this->timeavailable + ($this->intervalcount * $this->timeinterval)));
        }
        
        return false;
    }

    /**
     * Returns the number of the current interval.
     *
     * @return int
     */
    public function get_interval_current_number() {
        if ($this->is_early() or $this->is_past_due()) {
            return 0;
        }
    
        if ($this->intervalcount > 1) {
            $now = time();
            return ceil(($now - $this->timeavailable) / $this->timeinterval);
        }
        return 1;
    }

    /**
     * Returns true if the activity has a designated due time
     * and now is after that time.
     *
     * @return int time
     */
    public function get_interval_current_start() {
        if (!$currentinterval = $this->interval_current_number) {
            return 0;
        }

        return ((($currentinterval - 1) * $this->timeinterval) + $this->timeavailable);
    }

    /**
     * Returns true if the activity has a designated due time
     * and now is after that time.
     *
     * @return int time
     */
    public function get_interval_current_end() {
        if (!$this->timeinterval) {
            return $this->timedue;
        }
        return ($this->interval_current_start + $this->timeinterval -1);
    }

    // UTILITY

    /**
     *
     */
    public function name_exists($table, $name, $id = 0) {
        global $DB;

        $params = array(
            $this->id,
            $name,
            (int) $id
        );
        $where = " dataid = ? AND name = ? AND id <> ? ";
        
        return $DB->record_exists_select("dataform_{$table}", $where, $params);
    }

    // RSS

    /**
     * Returns a list of rss view the current user has access to.
     *
     * @return array|bool Associative array viewid => dataformview_rss, false if no views    
     */
    public function get_rss_views() {
        static $views;
        
        if (!isset($views)) {
            if ($views = $this->view_manager->get_views_by_instanceof('mod_dataform\interfaces\rss')) {
                // Remove unpermitted
                foreach ($views as $viewid => $unused) {
                    $params = array('dataformid' => $this->id, 'viewid' => $viewid);
                    if (!mod_dataform\access\view_access::validate($params)) {
                        unset($views[$viewid]);
                    }
                }
            }
        }   
        
        return $views;
    }
    
}
