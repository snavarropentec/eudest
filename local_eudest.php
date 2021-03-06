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
 * COMPONENT External functions unit tests
 *
 * @package    local_eudest
 * @copyright  2017 Planificacion de Entornos Tecnologicos SL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/classes/date.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->libdir . '/grade/grade_category.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Schedule task for doin EUDE processes.
 *
 * @copyright  2016 Planificaci�n Entornos Tecnol�gicos {@link http://www.pentec.es/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_eudest {

    /**
     * Name of the plugin
     * @var string $pluginname
     */
    private $pluginname = "local_eudest";

    /**
     * Configuration object of the plugin
     * @var object $eudeconfig
     */
    private $eudeconfig;

    /**
     * Tag to identify the common course of a master
     * @var string $commoncoursetag
     */
    private $commoncoursetag = ".M00";

    /**
     * Tag to identify the intensive courses
     * @var string $intensivetag
     */
    private $intensivetag = "MI";

    /**
     * Tag to identify a module of a master
     * @var string $moduletag
     */
    private $moduletag = ".M.";

    /**
     * Role name of the master admin of a master
     * @var string $rmrolename
     */
    private $rmrolename = "manager";

    /**
     * Date mask
     * @var string $dateformat
     */
    private $dateformat = "Y-m-d";

    /**
     * Date mask 2
     * @var string $dateformat2
     */
    private $dateformat2 = "d/m/Y";

    /**
     * Tag for messages of type New student
     * @var string $msgtypenewstudent
     */
    private $msgtypenewstudent = "NEW_STUDENT";

    /**
     * Tag for messages of type St finish master
     * @var string $msgtypestfinishmaster
     */
    private $msgtypestfinishmaster = "ST_FINISH_MASTER";

    /**
     * Tag for messages of type Rm finish master
     * @var string $msgtypermfinishmaster
     */
    private $msgtypermfinishmaster = "RM_FINISH_MASTER";

    /**
     * Tag for messages of type Rm Inactive6
     * @var string $msgtyperminactivity6
     */
    private $msgtyperminactivity6 = "RM_INACTIVITY6";

    /**
     * Tag for messages of type Rm Inactive18
     * @var string $msgtyperminactivity18
     */
    private $msgtyperminactivity18 = "RM_INACTIVITY18";

    /**
     * Tag for messages of type Rm Inactive24
     * @var string $msgtyperminactivity24
     */
    private $msgtyperminactivity24 = "RM_INACTIVITY24";

    /**
     * Tag for messages of type User locked
     * @var string $msgtypeuserlocked
     */
    private $msgtypeuserlocked = "USER_LOCKED";

    /**
     * Code to replace in messages
     * @var string $userflag
     */
    private $userflag = "[[USER]]";

    /**
     * Code to replace in messages
     * @var string $groupflag
     */
    private $groupflag = "[[GROUP]]";

    /**
     * Main method of the job.
     */
    public function eude_cron () {

        // Retrieve configuration.
        $this->eude_load_configuration();

        // Generate events.
        // Store enrolments in self table.
        $this->eude_register_enrolments();
        // Process enrolments.
        $this->eude_encapsulate_enrolments();
        // Generate events of the user courses.
        $this->eude_generate_course_events();

        // Generate messages.
        // Notify RM by each master user.
        // Notify user when master finishes.
        // Notify RM when group finishes master.
        $this->eude_generate_master_messages();
        // Notify RM if user inactive for 6 months.
        // Notify RM if user inactive for 18 months.
        // Notify RM if user inactive for 24 months.
        $this->eude_generate_inactivity_messages();

        // Calculate grades.
        // Overwrite grades of the normal course with the intensive grade.
        $this->eude_override_califications();

        /* Convalidations. */
        $this->eude_convalidate_modules();

        // Send messages.
        // Send messages of today.
        $this->eude_send_scheduled_messages();

        // Update configuration.
        $this->eude_save_configuration();

    }

    /**
     * Get database configuration
     */
    private function eude_load_configuration () {
        global $DB;
        $record = $DB->get_record('local_eudest_config', array());
        if (!$record) {
            $record = new stdClass();
            $record->id = 0;
            $record->last_enrolid = 0;
            $record->last_inactivity_date = 0;
            $record->last_califications_date = 0;
        }
        $this->eudeconfig = $record;
    }

    /**
     * Save database configuration
     */
    private function eude_save_configuration () {
        global $DB;
        if ($this->eudeconfig->id == 0) {
            $DB->insert_record('local_eudest_config', $this->eudeconfig);
        } else {
            $DB->update_record('local_eudest_config', $this->eudeconfig);
        }
    }

    /**
     * Insert new enrolments into our table and update sentinel
     */
    private function eude_register_enrolments () {
        global $DB;

        $lastid = $this->eudeconfig->last_enrolid;

        $sql = "SELECT CONCAT(ue.id,'_',ra.id) id, ue.id ueid, ra.id raid, ue.userid, ue.timestart, ue.timeend,
                       ue.status, c.category, e.courseid, c.shortname
                  FROM {user_enrolments} ue
                  JOIN {role_assignments} ra ON ra.userid = ue.userid
                  JOIN {context} ct ON ra.contextid = ct.id
                  JOIN {role} r ON ra.roleid = r.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ct.instanceid
                  JOIN {course} c ON c.id = e.courseid
                 WHERE ue.id > :lastid
                   AND r.shortname like '%student%'
                   AND (c.shortname like '%.M.%' OR c.shortname like 'MI.%')
              ORDER BY ue.id ASC";

        $records = $DB->get_records_sql($sql, array('lastid' => $lastid));

        // Process enrolments.
        foreach ($records as $record) {
            $this->eudeconfig->last_enrolid = $record->ueid;

            // Calculate if enrolments must be encapsulated.
            $pdteencapsulation = 1;
            // Check is the course is intensive.
            $intensive = $this->eude_module_is_intensive($record->shortname);
            // Exclude if not allows to master.
            if (!$this->eude_module_allows_to_master($record->shortname)) {
                $pdteencapsulation = 0;
            }
            $pdteconvalidation = 0;
            if ($this->eude_module_is_convalidable($record->shortname)) {
                $pdteconvalidation = 1;
            }

            // Store enrolment in self table.
            $this->eude_save_enrolment_instance($record, $intensive, $pdteencapsulation, $pdteconvalidation);
        }
    }

    /**
     * Insert data into local_eudest_enrols
     * @param object $enrolment Object to save
     * @param int $intensive Indicate if the enrol is in a intensive module
     * @param int $pdteencapsulation Indicate if the enrol has to be encapsulated
     * @param int $pdteconvalidation Indicate if the enrol has to be search for convalidations
     */
    private function eude_save_enrolment_instance ($enrolment, $intensive, $pdteencapsulation, $pdteconvalidation) {
        global $DB;

        $record = new stdClass();
        $record->userid = $enrolment->userid;
        $record->courseid = $enrolment->courseid;
        $record->shortname = $enrolment->shortname;
        $record->categoryid = $enrolment->category;
        $record->startdate = $enrolment->timestart;
        $record->enddate = $enrolment->timeend;
        $pendevent = 1;
        if (strrpos($enrolment->shortname, $this->commoncoursetag)) {
            $pendevent = 0;
        }
        $record->pend_event = $pendevent;
        $record->pend_encapsulation = $pdteencapsulation;
        $record->pend_convalidation = $pdteconvalidation;
        $record->intensive = $intensive;
        $record->pend_int_messages = $intensive;
        $record->masterid = 0;

        $DB->insert_record('local_eudest_enrols', $record, false);
    }

    /**
     * Check if the module is intensive by its TAG
     * @param string $shortname Shortname of the course
     * @return int
     */
    private function eude_module_is_intensive ($shortname) {
        $sub = explode('.', $shortname);
        if ($sub[0] == $this->intensivetag) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * Check if the module allows to a master
     * @param string $shortname Shortname of the course
     * @return int
     */
    private function eude_module_allows_to_master ($shortname) {
        $pos = strrpos($shortname, $this->moduletag);
        if ($pos == false) {
            return 0;
        }
        return 1;
    }

    /**
     * Check if the module is convalidable
     * @param string $shortname Shortname of the course
     * @return int
     */
    private function eude_module_is_convalidable ($shortname) {
        $pos1 = strrpos($shortname, "[-");
        $pos2 = strrpos($shortname, "-]");
        if ($pos1 == false || $pos2 == false) {
            return 0;
        }
        return 1;
    }

    /**
     * Encapsulate enrolments in order to process them
     */
    private function eude_encapsulate_enrolments () {
        global $DB;
        $sqlcats = 'SELECT *
                      FROM {course_categories}
                     WHERE parent=0';
        $categories = $DB->get_records_sql($sqlcats, array());

        // Order data.
        $nodecategories = [];
        foreach ($categories as $category) {
            $sqlenrolments = 'SELECT *
                                FROM {local_eudest_enrols}
                               WHERE pend_encapsulation = 1
                                 AND categoryid = :cat
                            ORDER BY userid, startdate ASC';
            $enrols = $DB->get_records_sql($sqlenrolments, array("cat" => $category->id));

            foreach ($enrols as $enrol) {

                $existsnodecategory = false;
                foreach ($nodecategories as $nodecategory) {
                    if ($nodecategory->categoryid == $enrol->categoryid) {
                        $existsnodecategory = true;
                        $existsnodeuser = false;
                        foreach ($nodecategory->users as $nodeuser) {
                            if ($nodeuser->userid == $enrol->userid) {
                                array_push($nodeuser->enrolments, $enrol);
                                $existsnodeuser = true;
                                break 2;
                            }
                        }
                        if (!$existsnodeuser) {
                            $nodeuser = new stdClass();
                            $nodeuser->userid = $enrol->userid;
                            $nodeuser->enrolments = [];
                            array_push($nodeuser->enrolments, $enrol);
                            array_push($nodecategory->users, $nodeuser);
                        }
                    }
                }
                if (!$existsnodecategory) {
                    $nodecategory = new stdClass();
                    $nodecategory->categoryid = $enrol->categoryid;
                    $nodeuser = new stdClass();
                    $nodeuser->userid = $enrol->userid;
                    $nodeuser->enrolments = [];
                    array_push($nodeuser->enrolments, $enrol);
                    $nodecategory->users = [];
                    array_push($nodecategory->users, $nodeuser);
                    array_push($nodecategories, $nodecategory);
                }
            }
        }

        // Process ordered enrolments.
        foreach ($nodecategories as $nodecategory) {
            $categoryid = $nodecategory->categoryid;

            // Count modules of the category.
            $sqlcount = "SELECT count(*)
                           FROM {course}
                          WHERE category = :cat
                            AND shortname not like CONCAT('$this->intensivetag', '.%')";
            $categorymodulescount = $DB->count_records_sql($sqlcount, array('cat' => $categoryid));

            // Process category users.
            foreach ($nodecategory->users as $nodeuser) {

                // The user must have enrol in all courses of the category.
                if (count($nodeuser->enrolments) != $categorymodulescount) {
                    continue;
                }

                // Calculate min and max dates.
                $startdate = 0;
                $enddate = 0;
                foreach ($nodeuser->enrolments as $nodeenrol) {
                    if ($startdate == 0) {
                        $startdate = $nodeenrol->startdate;
                        $enddate = $nodeenrol->enddate;
                    }
                    if ($nodeenrol->startdate < $startdate) {
                        $startdate = $nodeenrol->startdate;
                    }
                    if ($nodeenrol->enddate > $enddate) {
                        $enddate = $nodeenrol->enddate;
                    }
                }

                // Insert the encapsulation.
                $masterid = $this->eude_save_encapsulation($nodeuser->userid, $categoryid, $startdate, $enddate);

                // Assign the encapsulation to the enrolments.
                foreach ($nodeuser->enrolments as $nodeenrol) {
                    $nodeenrol->masterid = $masterid;
                    $nodeenrol->pend_encapsulation = 0;
                    $DB->update_record('local_eudest_enrols', $nodeenrol);
                }
            }
        }
    }

    /**
     * Insert data into local_eudest_masters
     * @param int $userid Id of the user
     * @param int $categoryid Id of the category
     * @param int $startdate Start date of the master for the user
     * @param int $enddate End date of the master for the user
     * @return int Id of the inserted encapsulation
     */
    private function eude_save_encapsulation ($userid, $categoryid, $startdate, $enddate) {
        global $DB;
        $record = new stdClass();
        $record->userid = $userid;
        $record->categoryid = $categoryid;
        $record->startdate = $startdate;
        $record->enddate = $enddate;
        $record->pend_holidays = 1;
        $record->pend_master_messages = 1;
        $record->inactivity6 = 0;
        $record->inactivity18 = 0;
        $record->inactivity24 = 0;
        $ret = $DB->insert_record('local_eudest_masters', $record);
        return $ret;
    }

    /**
     * Generate course events from the enrolments.
     */
    private function eude_generate_course_events () {
        global $CFG;
        global $DB;
        // Check if functionallity enabled.
        $generatecourseevents = $CFG->local_eudest_genenrolcalendar;
        if (!$generatecourseevents) {
            return 0;
        }
        // Get enrolments unprocessed.
        $sql = "SELECT *
                  FROM {local_eudest_enrols}
                 WHERE pend_event=1
              ORDER BY startdate ASC";
        $records = $DB->get_records_sql($sql, array());

        // Process enrolments.
        foreach ($records as $record) {

            // Calculate the start of the next event.
            $tend = $record->enddate;
            $nextsql = "SELECT *
                          FROM {local_eudest_enrols}
                         WHERE pend_event = 1
                           AND categoryid = :categoryid
                           AND userid = :userid
                           AND startdate > :startdate
                      ORDER BY startdate ASC
                         LIMIT 1";
            $next = $DB->get_record_sql($nextsql,
                    array("categoryid" => $record->categoryid, "userid" => $record->userid, "startdate" => $record->startdate));
            if ($next) {
                $tend = $next->startdate;
            }

            // Generate event in calendar.
            $evname = "[[COURSE]]$record->shortname";
            if ($record->intensive) {
                $evname = "[[MI]]$record->shortname";
            }
            $evdescription = $evname;
            $evtimestart = $record->startdate;
            $evduration = $tend - $record->startdate;
            $evuserid = $record->userid;
            $this->eude_add_event_to_calendar($evname, $evdescription, $evtimestart, $evduration, $evuserid);

            // Update column of 'pend_event'.
            $record->pend_event = 0;
            $DB->update_record('local_eudest_enrols', $record);
        }
    }

    /**
     * Create an event in the moodle calendar
     * @param string $name Name of the event
     * @param string $description Description of the event
     * @param int $timestart Start datetime of the event
     * @param int $duration Duration of the event
     * @param int $userid Id of the user
     */
    private function eude_add_event_to_calendar ($name, $description, $timestart, $duration, $userid) {
        $cstart = strtotime(date($this->dateformat, $timestart));
        if ($duration < 0) {
            $duration = 24 * 60 * 60;
        }
        $min = $cstart + $duration;
        $today = strtotime(date('Y-m-d', time('00:00')));
        // Do not create past events.
        if ($min < $today) {
            return;
        }
        $event = new stdClass();
        $event->name = $name;
        $event->modulename = "";
        $event->description = $description;
        $event->groupid = 0;
        $event->timestart = $cstart;
        $event->visible = 1;
        $event->timeduration = $duration;
        $event->userid = $userid;
        calendar_event::create($event);
    }

    /**
     * Add a message to the stack of messages
     * @param int $categoryid Id of the category
     * @param string $shortname Shortname of the course
     * @param string $to Ids of the users targets of the message
     * @param string $target Information to include inside the body of the message
     * @param string $msgtype Type of the message
     * @param int $msgdate Date for sending the message.
     * @return int Id of the inserted message
     */
    private function eude_add_message_to_stack ($categoryid, $shortname, $to, $target, $msgtype, $msgdate) {
        global $DB;
        $record = new stdClass();
        $record->categoryid = (int) $categoryid;
        $record->shortname = $shortname;
        $record->msgto = $to;
        $record->msgtarget = $target;
        $record->msgtype = $msgtype;
        $record->msgdate = (float) $msgdate;
        $record->sended = (int) 0;
        $ret = $DB->insert_record('local_eudest_msgs', $record);
        return $ret;
    }

    /**
     * Check if message exists in stack
     * @param int $categoryid Id of the category
     * @param string $to Ids of the users targets of the message
     * @param string $target Information to include inside the body of the message
     * @param string $msgtype Type of the message
     * @param int $msgdate Date for sending the message.
     * @return boolean TRUE if exists
     */
    private function eude_find_message_in_stack ($categoryid, $to, $target, $msgtype, $msgdate) {
        global $DB;
        $sqlcount = "SELECT count(*)
                       FROM {local_eudest_msgs}
                      WHERE categoryid = :categoryid
                        AND msgto = :msgto
                        AND msgtarget = :msgtarget
                        AND msgtype = :msgtype
                        AND msgdate = :msgdate";
        $result = $DB->count_records_sql($sqlcount,
                array('categoryid' => $categoryid,
            'msgto' => $to,
            'msgtarget' => $target,
            'msgtype' => $msgtype,
            'msgdate' => $msgdate));
        return $result;
    }

    /**
     * Generate messages of the master.
     */
    private function eude_generate_master_messages () {
        global $CFG;
        global $DB;

        // Check if must send notification to RM by each new user in master.
        $noticermonnewuser = $CFG->local_eudest_enrolnotice;
        // Check if must send notification to user when finishes master.
        $noticeusersonfinishmaster = $CFG->local_eudest_stfinishnotice;
        // Check if must send notification to RM when users finish master.
        $noticermonfinishmaster = $CFG->local_eudest_rmfinishnotice;

        if (!$noticermonnewuser && !$noticeusersonfinishmaster && !$noticermonfinishmaster) {
            return 0;
        }

        // Get pendings encapsulations.
        $sql = "SELECT *
                  FROM {local_eudest_masters}
                 WHERE pend_master_messages=1";
        $masters = $DB->get_records_sql($sql, array());

        $nodecategoriesusers = [];
        $nodecategoriesdates = [];
        foreach ($masters as $master) {

            // Group users by category to send message to new users.
            if ($noticermonnewuser) {
                $exists = false;
                foreach ($nodecategoriesusers as $nodecategory) {
                    if ($nodecategory->categoryid == $master->categoryid) {
                        if (!in_array($master->userid, $nodecategory->users)) {
                            array_push($nodecategory->users, $master->userid);
                        }
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $nodecategory = new stdClass();
                    $nodecategory->categoryid = $master->categoryid;
                    $nodecategory->users = [];
                    array_push($nodecategory->users, $master->userid);
                    array_push($nodecategoriesusers, $nodecategory);
                }
            }

            // Group users by end date to send messages.
            if ($noticeusersonfinishmaster) {
                $exists = false;
                foreach ($nodecategoriesdates as $nodecategory) {
                    if ($nodecategory->categoryid == $master->categoryid && $nodecategory->enddate == $master->enddate) {
                        if (!in_array($master->userid, $nodecategory->users)) {
                            array_push($nodecategory->users, $master->userid);
                        }
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $nodecategory = new stdClass();
                    $nodecategory->categoryid = $master->categoryid;
                    $nodecategory->startdate = $master->startdate;
                    $nodecategory->enddate = $master->enddate;
                    $nodecategory->users = [];
                    array_push($nodecategory->users, $master->userid);
                    array_push($nodecategoriesdates, $nodecategory);
                }
            }

            // Update column 'pend_master_messages'.
            $master->pend_master_messages = 0;
            $DB->update_record('local_eudest_masters', $master);
        }

        /*
         * As the intensive courses are in a single category we have to generate the messages without encapsulating them.
         */
        $intensivecourserecords = [];
        try {
            $sql = "SELECT lee.*
                      FROM {local_eudest_enrols} lee
                     WHERE lee.intensive = 1
                       AND lee.pend_int_messages = 1";
            $intensivecourserecords = $DB->get_records_sql($sql, array());
            $intensivetag = $this->intensivetag;
            // We recover the intensive module category.
            $sql = "SELECT DISTINCT c.category
                      FROM {course} c
                     WHERE c.shortname LIKE CONCAT('$intensivetag', '.%')
                  ORDER BY c.category ASC
                     LIMIT 1";
            $intensivecoursecategory = $DB->get_record_sql($sql, array());

            // We get the responsable master for the intensive courses categories.
            $rmintensive = $this->eude_get_rm($intensivecoursecategory->category);

            foreach ($intensivecourserecords as $record) {
                // Update column 'pend_int_messages'.
                $record->pend_int_messages = 0;
                $DB->update_record('local_eudest_enrols', $record);
            }
        } catch (Exception $e) {
            return $e;
        }

        $today = strtotime(date('Y-m-d', time('00:00')));

        // Add messages to stack (one message to RM by each enrolled in master).
        if ($noticermonnewuser) {
            foreach ($nodecategoriesusers as $nodecategory) {
                $rm = $this->eude_get_rm($nodecategory->categoryid);
                if ($rm == 0) {
                    continue;
                }
                $target = implode(",", $nodecategory->users);
                $this->eude_add_message_to_stack($nodecategory->categoryid, null, $rm, $target, $this->msgtypenewstudent, $today);
            }
            if ($rmintensive) {
                foreach ($intensivecourserecords as $record) {
                    $this->eude_add_message_to_stack($intensivecoursecategory->category, $record->shortname, $rmintensive,
                            $record->userid, $this->msgtypenewstudent, $today);
                }
            }
        }

        // Create messages.
        if ($noticeusersonfinishmaster) {
            foreach ($nodecategoriesdates as $nodecategory) {
                $to = implode(",", $nodecategory->users);
                $tend = strtotime('+1 day', $nodecategory->enddate);
                $tend = strtotime(date($this->dateformat, $tend));
                $this->eude_add_message_to_stack($nodecategory->categoryid, null, $to, null, $this->msgtypestfinishmaster, $tend);
            }
            foreach ($intensivecourserecords as $record) {
                $tend = strtotime('+1 day', $record->enddate);
                $tend = strtotime(date($this->dateformat, $tend));
                $this->eude_add_message_to_stack($intensivecoursecategory->category, null, $record->userid, null,
                        $this->msgtypestfinishmaster, $tend);
            }
        }
        // Create messages.
        if ($noticermonfinishmaster) {
            foreach ($nodecategoriesdates as $nodecategory) {
                $rm = $this->eude_get_rm($nodecategory->categoryid);
                if ($rm == 0) {
                    continue;
                }
                $tend = strtotime('+1 day', $nodecategory->enddate);
                $tend = strtotime(date($this->dateformat, $tend));
                // Check if record exists.
                $exists = $this->eude_find_message_in_stack($nodecategory->categoryid, $rm,
                        date($this->dateformat2, $nodecategory->startdate), $this->msgtypermfinishmaster, $tend);
                if ($exists) {
                    continue;
                }
                // Add message to stack.
                $this->eude_add_message_to_stack($nodecategory->categoryid, null, $rm,
                        date($this->dateformat2, $nodecategory->startdate), $this->msgtypermfinishmaster, $tend);
            }
            if ($rmintensive) {
                foreach ($intensivecourserecords as $record) {
                    if ($rmintensive == 0) {
                        continue;
                    }
                    $tend = strtotime('+1 day', $record->enddate);
                    $tend = strtotime(date($this->dateformat, $tend));

                    // Check if record exists.
                    $exists = $this->eude_find_message_in_stack($record->categoryid, $rmintensive,
                        date($this->dateformat2, $record->startdate), $this->msgtypermfinishmaster, $tend);
                    if ($exists) {
                        continue;
                    }

                    // Add message to stack.
                    $this->eude_add_message_to_stack($record->categoryid, null, $rmintensive,
                        date($this->dateformat2, $record->startdate), $this->msgtypermfinishmaster, $tend);
                }
            }
        }
    }

    /**
     * Get manager of the category.
     * @param int $categoryid Id of the category
     * @return int Id of the manager
     */
    private function eude_get_rm ($categoryid) {
        global $DB;
        $sql = "SELECT userid
                  FROM {role_assignments} ra
            INNER JOIN {role} r ON r.id = ra.roleid
            INNER JOIN {context} cxt ON cxt.id = ra.contextid
                 WHERE cxt.instanceid = :cat
                   AND cxt.contextlevel = :coursecat
                   AND r.shortname = :rmrolename";
        $record = $DB->get_record_sql($sql,
                array("cat" => $categoryid,
            'coursecat' => CONTEXT_COURSECAT, "rmrolename" => $this->rmrolename));
        if ($record) {
            return $record->userid;
        }
        return 0;
    }

    /**
     * Generate messages of inactivity.
     */
    private function eude_generate_inactivity_messages () {
        global $CFG;
        global $DB;

        // Get the last check inactivity date.
        $lastcheck = $this->eudeconfig->last_inactivity_date;
        $today = strtotime(date('Y-m-d', time()));

        // Only one check by day.
        if ($lastcheck == $today) {
            return 0;
        }

        // Get messages configuration.
        $noticermoninactivity6 = $CFG->local_eudest_inac6notice;
        $noticermoninactivity18 = $CFG->local_eudest_inac18notice;
        $noticermoninactivity24 = $CFG->local_eudest_inac24notice;
        $lockuseroninactivity24 = $noticeuseroninactivity24 = $noticermoninactivity24;

        // Database specific functions.
        $bdtimestamp = "UNIX_TIMESTAMP()";
        $nummonthsfunction = "TIMESTAMPDIFF(MONTH, FROM_UNIXTIME(max(timeaccess),'%Y-%m-%d'),
                FROM_UNIXTIME(UNIX_TIMESTAMP(),'%Y-%m-%d'))";
        $nummonths = "num_months";
        $add18months = "UNIX_TIMESTAMP(TIMESTAMPADD(MONTH,18,FROM_UNIXTIME( enddate )))";
        $type = strpos($CFG->dbtype, 'pgsql');
        if ($type || $type === 0) {
            $bdtimestamp = "EXTRACT(EPOCH FROM(CURRENT_TIMESTAMP))";
            $nummonthsfunction = "(DATE_PART('year', CURRENT_TIMESTAMP) - DATE_PART('year', TO_TIMESTAMP(max(timeaccess)))) * 12 +
                                  (DATE_PART('month', CURRENT_TIMESTAMP) -
                                        DATE_PART('month', TO_TIMESTAMP(max(timeaccess))))";
            $nummonths = $nummonthsfunction;
            $add18months = "extract(epoch from (TO_TIMESTAMP(enddate) + INTERVAL '18 month'))";
        }
        // Get users inactives for 6 months.
        if ($noticermoninactivity6) {
            $sql = "SELECT u.*
                  FROM {local_eudest_masters} u,
                       (SELECT userid,
                               $nummonthsfunction as num_months
                          FROM {user_lastaccess}
                         GROUP BY userid
                        HAVING $nummonths >= 6) la
                 WHERE la.userid = u.userid
                   AND startdate < $bdtimestamp
                   AND enddate > $bdtimestamp
                   AND inactivity6 = 0";
            $records = $DB->get_records_sql($sql, array());
            foreach ($records as $record) {
                $rm = $this->eude_get_rm($record->categoryid);
                // Add message to stack.
                $this->eude_add_message_to_stack($record->categoryid, null, $rm, $record->userid, $this->msgtyperminactivity6,
                        $today);
                // Update inactivity in master.
                $record->inactivity6 = 1;
                $DB->update_record('local_eudest_masters', $record);
            }
        }
        // Get users inactives for 18 months after finish the master.
        $sql = "SELECT u.*, la.num_months
                  FROM {local_eudest_masters} u,
                       (SELECT userid,
                               $nummonthsfunction as num_months
                          FROM {user_lastaccess}
                         GROUP BY userid
                        HAVING $nummonths >= 18) la
                 WHERE la.userid = u.userid
                   AND $add18months < $bdtimestamp
                   AND inactivity18 = 0;";

        $records = $DB->get_records_sql($sql, array());
        foreach ($records as $record) {
            $inactivitytime = $record->num_months;
            $inactive18 = $inactive24 = 0;
            $msgtype = "";
            if ($inactivitytime >= 18) {
                if ($record->inactivity18 == 0 && $noticermoninactivity18) {
                    $inactive18 = 1;
                    $msgtype = $this->msgtyperminactivity18;
                }
            }
            if ($inactivitytime >= 24) {
                if ($record->inactivity24 == 0 && $noticermoninactivity24) {
                    $inactive24 = 1;
                    $msgtype = $this->msgtyperminactivity24;
                }
            }

            if ($inactive18 == 1 || $inactive24 == 1) {

                $rm = $this->eude_get_rm($record->categoryid);
                // Add message to stack.
                $this->eude_add_message_to_stack($record->categoryid, null, $rm, $record->userid, $msgtype, $today);

                // Update inactivity in master.
                unset($record->num_months);
                $record->inactivity18 = $inactive18;
                $record->inactivity24 = $inactive24;
                $DB->update_record('local_eudest_masters', $record);

                if ($inactive24 == 1) {
                    // Lock user.
                    if ($lockuseroninactivity24) {
                        $this->eude_lock_user();
                    }
                    // Notice user.
                    if ($noticeuseroninactivity24) {
                        $this->eude_add_message_to_stack($record->categoryid, null, $record->userid, "",
                                $this->msgtypeuserlocked, $today);
                    }
                }
            }
        }

        // Update check date.
        $this->eudeconfig->last_inactivity_date = $today;
    }

    /**
     * Lock an user.
     * Reserved for next releases.
     */
    private function eude_lock_user () {
        return 0;
    }

    /**
     * Overrides grades of the normal courses by grades of intensive courses.
     */
    private function eude_override_califications () {
        global $CFG;
        global $DB;

        // Get grades configuration.
        $overridecalifications = $CFG->local_eudest_override;
        if (!$overridecalifications) {
            return 0;
        }

        // Get last check date.
        $lastcheck = $this->eudeconfig->last_califications_date;
        $intensivetag = $this->intensivetag;
        // Get new grades.
        $sql = "SELECT GG.id, GG.finalgrade, GG.timemodified, GG.userid, GG.information, GC.shortname, GC.fullname
                 FROM {grade_grades} GG
                 JOIN {grade_items} GI ON GG.itemid = GI.id
                 JOIN {course} GC ON GI.courseid = GC.id
                WHERE GI.itemtype = 'course'
                  AND GG.finalgrade is not null
                  AND GG.timemodified > :lastcheck
                  AND upper(GC.shortname) LIKE CONCAT('$intensivetag', '.%')
             ORDER BY GG.timemodified asc";
        $records = $DB->get_records_sql($sql, array("lastcheck" => $lastcheck));
        foreach ($records as $record) {
            // Get new grade value.
            $newcalification = $record->finalgrade;

            // Get actual grade value.
            $lastcheck = $record->timemodified;
            $userid = $record->userid;
            $shortname = str_replace("$this->intensivetag", "", $record->shortname);

            $actualcalification = 0;
            $information = "";

            /* If the user has a grade in an intensive course and didn't have a grade in the normal course
             * related to that intensive grade.
             */
            // Recover all the normal courses related to the intensive course where the user is enroled.
            $sql = "SELECT c.courseid
                          FROM {local_eudest_enrols} c
                         WHERE c.shortname LIKE CONCAT('%.M', CONCAT('$shortname', '%'))
                           AND c.userid = :userid";
            $modules = $DB->get_records_sql($sql, array('userid' => $userid));
            // Recover the grade_item of each normal course and register a new entry for the user in grade_grades.
            foreach ($modules as $module) {
                $sql = "SELECT gi.*
                          FROM {grade_items} gi
                         WHERE gi.courseid = :courseid
                           AND gi.itemtype = 'course'";
                $gradeitem = $DB->get_record_sql($sql, array('courseid' => $module->courseid));
                // If the user already has a record of a grade in that course.
                if ($DB->record_exists('grade_grades', array('itemid' => $gradeitem->id, 'userid' => $userid))) {
                    $actualgradeinfo = $DB->get_record('grade_grades', array('itemid' => $gradeitem->id, 'userid' => $userid));
                    if ($newcalification > $actualgradeinfo->finalgrade) {
                        $actualgradeinfo->finalgrade = $newcalification;
                        $information = $actualgradeinfo->information;

                        if ($information == null || $information == "") {
                            $information = new lang_string('normal_grade', $this->pluginname) .
                                    ": " . number_format($actualgradeinfo->finalgrade, 2, '.', '') . "/ "
                                    . number_format($actualgradeinfo->rawgrademax, 2, '.', '') . ".";
                        }
                        $information = new lang_string('intensive_grade', $this->pluginname) .
                                " (" . date("d/m/y, H:i:s", $record->timemodified) . "): " .
                                number_format($newcalification, 2, '.', '') . "/ "
                                . number_format($actualgradeinfo->rawgrademax, 2, '.', '') . ". " . $information;
                        // Update total course grade.
                        $this->eude_update_course_grade($gradeitem->id, $module->courseid, $userid, $newcalification, $information);
                    }
                } else {
                    $information = new lang_string('intensive_grade', $this->pluginname)
                            . " (" . date("d/m/y, H:i:s", $record->timemodified) . "): "
                            . number_format($newcalification, 2, '.', '') . "/ "
                            . number_format($gradeitem->grademax, 2, '.', '') . ". ";
                    $this->eude_update_course_grade($gradeitem->id, $module->courseid, $userid,
                            $newcalification, $information);
                }
                // We add a new role for the user in the course with different capabilities.
                $studentvaldata = $DB->get_record('role', array('shortname' => 'studentval'));
                $coursecontextdata = context_course::instance($module->courseid);
                role_assign($studentvaldata->id, $userid, $coursecontextdata->id);
            }
        }
        // Update check date.
        $this->eudeconfig->last_califications_date = $lastcheck;
    }

    /**
     * Update the total grade of a course.
     * @param int $gradeitemid Id of the gradeitem
     * @param int $courseid Id of the course
     * @param int $userid Id of the user
     * @param float $finalgrade New grade of the total course
     * @param string $info Feedback for the column
     */
    private function eude_update_course_grade ($gradeitemid, $courseid, $userid, $finalgrade, $info) {
        $gradeitem = new grade_item(array('id' => $gradeitemid, 'courseid' => $courseid));
        $gradeitem->update_final_grade(intval($userid), $finalgrade, null, format_string($info));
    }

    /**
     * Update the grade of a new course with the max grade of similar courses(Convalidables).
     */
    private function eude_convalidate_modules () {
        global $CFG;
        global $DB;

        // Get convalidations configuration.
        $doconvalidations = $CFG->local_eudest_convalidations;
        if (!$doconvalidations) {
            return 0;
        }

        // Get enrols pending of recognize modules.
        $enrols = $DB->get_records('local_eudest_enrols', array('intensive' => 0, 'pend_convalidation' => 1));
        foreach ($enrols as $enrol) {
            // Check if the user has already a grade.
            $sqlitems = "SELECT *
                            FROM {grade_items}
                           WHERE itemtype = :type
                             AND courseid = :courseid
                             AND iteminstance IS NOT NULL";
            $record = $DB->get_record_sql($sqlitems, array('type' => 'course', 'courseid' => $enrol->courseid));
            // If he doesn't have a grade we search the recognizables courses and get the maximum grade.
            if ($record && !$DB->record_exists('grade_grades', array('itemid' => $record->id, 'userid' => $enrol->userid))) {
                // Check if user has enrolments in recognized modules.
                $cod = substr($enrol->shortname, strrpos($enrol->shortname, "["), strlen($enrol->shortname));
                $sql = "SELECT *
                          FROM {local_eudest_enrols}
                         WHERE shortname LIKE '%$cod'
                           AND courseid != :courseid
                           AND userid = :userid";
                $recognizedcourses = $DB->get_records_sql($sql, array('courseid' => $enrol->courseid, 'userid' => $enrol->userid));
                // If he has enrolments in other modules we get the maximum grtade and update the new course grade.
                if ($recognizedcourses) {
                    $maxgrade = 0;
                    foreach ($recognizedcourses as $recognizedcourse) {
                        $coursegradeitem = $DB->get_record_sql($sqlitems,
                                array('type' => 'course', 'courseid' => $recognizedcourse->courseid));
                        if ($coursegradegrade = $DB->get_record('grade_grades',
                                array('itemid' => $coursegradeitem->id, 'userid' => $enrol->userid))) {
                            if (($coursegradegrade->finalgrade / $coursegradegrade->rawgrademax) > $maxgrade) {
                                $maxgrade = $coursegradegrade->finalgrade / $coursegradegrade->rawgrademax;
                            }
                        }
                    }
                    // If he passed any of the recognizable modules he cna validate the new module.
                    if ($maxgrade > 0.5) {
                        $maxgrade = $maxgrade * $record->grademax;
                        // Update grade value.
                        $this->eude_update_course_grade($record->id, $enrol->courseid, $enrol->userid, $maxgrade, "convalidation");
                    }
                }
            }
            $enrol->pend_convalidation = 0;
            $DB->update_record('local_eudest_enrols', $enrol);
        }
    }

    /**
     * Send scheduled messages.
     */
    private function eude_send_scheduled_messages () {
        global $DB;
        global $CFG;

        $msgnewstudent = $CFG->local_eudest_enroltext;
        $msgrmfinish = $CFG->local_eudest_rmfinishtext;
        $msgstfinish = $CFG->local_eudest_stfinishtext;
        $msginac6 = $CFG->local_eudest_inac6text;
        $msginac18 = $CFG->local_eudest_inac18text;
        $msginacrm24 = $CFG->local_eudest_inac24rmtext;
        $msginacst24 = $CFG->local_eudest_inac24sttext;

        $msginac18subject = new lang_string('inac18_subject', $this->pluginname);
        $msginac24subject = new lang_string('inac24_subject', $this->pluginname);

        $from = $this->get_admin();
        $todaydate = strtotime(date('Y-m-d', time()));
        $sql = "SELECT *
                      FROM {local_eudest_msgs}
                     WHERE sended = 0
                       AND msgdate = :todaydate";
        $records = $DB->get_records_sql($sql, array("todaydate" => $todaydate));
        foreach ($records as $record) {
            $categoryid = $record->categoryid;
            $target = $record->msgtarget;
            $msgtype = $record->msgtype;

            switch ($msgtype) {

                // Notice RM by each new user in category.
                // From=admin to=rm target=1,2,3.
                // One message with all the new users.
                case $this->msgtypenewstudent;
                    $category = $this->get_category($categoryid);
                    $subject = new lang_string('rmenrolnotice_subject', $this->pluginname, $category->name);
                    $to = $this->get_user($record->msgto);
                    $incourse = $record->shortname;
                    $messagetext = $msgnewstudent;
                    $userstext = "";
                    if (strrpos($messagetext, $this->userflag)) {
                        if (strrpos($target, ",")) {
                            $arrs = explode(',', $target);
                            foreach ($arrs as $arr) {
                                $arr = trim($arr);
                                $user = $this->get_user($arr);
                                if ($user) {
                                    $userstext .= $user->firstname . " " . $user->lastname . ". ";
                                }
                            }
                        } else {
                            $user = $this->get_user($target);
                            if ($user) {
                                $userstext .= $user->firstname . " " . $user->lastname . ". ";
                            }
                        }
                        $messagetext = str_replace($this->userflag, $userstext, $messagetext);
                    }
                    if ($incourse != null) {
                        $messagetext = "[" . $incourse . "]." . $messagetext;
                    }
                    $messagetext = $category->name . ". " . $messagetext;
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;

                // Notice user when finishes master.
                // From=admin to=35,48,57 target=null.
                // One message by each user finished.
                case $this->msgtypestfinishmaster:
                    $category = $this->get_category($categoryid);
                    $subject = new lang_string('stfinishnotice_subject', $this->pluginname, $category->name);
                    $messagetext = $msgstfinish;
                    $messagetext = $category->name . ". " . $messagetext;
                    if (strrpos($record->msgto, ",")) {
                        $arrs = explode(',', $record->msgto);
                        foreach ($arrs as $arr) {
                            $arr = trim($arr);
                            $to = $this->get_user($arr);
                            if ($to) {
                                $this->send_message($from, $to, $subject, $messagetext);
                            }
                        }
                    } else {
                        $to = $this->get_user($record->msgto);
                        if ($to) {
                            $this->send_message($from, $to, $subject, $messagetext);
                        }
                    }
                    break;

                // Notice RM that group has finished.
                // From=admin to=rm target=01/01/2016(Group start date).
                // One message by each user that has finished master.
                case $this->msgtypermfinishmaster:
                    $category = $this->get_category($categoryid);
                    $subject = new lang_string('rmfinishnotice_subject', $this->pluginname, $category->name);
                    $messagetext = $msgrmfinish;
                    $to = $this->get_user($record->msgto);
                    if (strrpos($messagetext, $this->groupflag)) {
                        $messagetext = str_replace($this->groupflag, $target, $messagetext);
                    }
                    $messagetext = $category->name . ". " . $messagetext;
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;

                // Notice RM when user inactive on 6 months.
                // From=admin to=rm target=37.
                // One message to RM by each inactive user.
                case $this->msgtyperminactivity6:
                    $category = $this->get_category($categoryid);
                    $subject = new lang_string('inac6_subject', $this->pluginname, $category->name);
                    $to = $this->get_user($record->msgto);
                    $messagetext = $msginac6;
                    if (strrpos($messagetext, $this->userflag)) {
                        $user = $this->get_user($target);
                        if ($user) {
                            $userstext = $user->firstname . " " . $user->lastname . ". ";
                            $messagetext = str_replace($this->userflag, $userstext, $messagetext);
                        }
                    }
                    $messagetext = $category->name . ". " . $messagetext;
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;

                // Notice RM when user inactive on 18months.
                // From=admin to=rm target=37.
                // One message to RM by each inactive user.
                case $this->msgtyperminactivity18:
                    $subject = $msginac18subject;
                    $to = $this->get_user($record->msgto);
                    $messagetext = $msginac18;
                    if (strrpos($messagetext, $this->userflag)) {
                        $user = $this->get_user($target);
                        if ($user) {
                            $userstext = $user->firstname . " " . $user->lastname . ". ";
                            $messagetext = str_replace($this->userflag, $userstext, $messagetext);
                        }
                    }
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;

                // Notice RM when user inactive on 24 months.
                // From=admin to=rm target=37.
                // One message to RM by each inactive user.
                case $this->msgtyperminactivity24:
                    $subject = $msginac24subject;
                    $to = $this->get_user($record->msgto);
                    $messagetext = $msginacrm24;
                    if (strrpos($messagetext, $this->userflag)) {
                        $user = $this->get_user($target);
                        if ($user) {
                            $userstext = $user->firstname . " " . $user->lastname . ". ";
                            $messagetext = str_replace($this->userflag, $userstext, $messagetext);
                        }
                    }
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;

                // Notice user on locked.
                // From=admin to=user target=null.
                // One message to user locked.
                case $this->msgtypeuserlocked:
                    $subject = $msginac24subject;
                    $to = $this->get_user($record->msgto);
                    $messagetext = $msginacst24;
                    $this->send_message($from, $to, $subject, $messagetext);
                    break;
            }

            // Delete the schedule message.
            $DB->delete_records("local_eudest_msgs", array("id" => $record->id));
        }
    }

    /**
     * Get the user administrator of the platform.
     * @return object User administrator
     */
    private function get_admin () {
        global $DB;
        $admin = $DB->get_record('user', array('id' => 2));
        return $admin;
    }

    /**
     * Find an user by id.
     * @param int $userid Id of the user
     * @return object User
     */
    private function get_user ($userid) {
        global $DB;
        $user = $DB->get_record('user', array('id' => $userid));
        return $user;
    }

    /**
     * Find a category by id.
     * @param int $catid Id of the category
     * @return object Course category
     */
    private function get_category ($catid) {
        global $DB;
        $category = $DB->get_record('course_categories', array('id' => $catid), '*', MUST_EXIST);
        return $category;
    }

    /**
     * Send a private message.
     * @param int $from Id of the user from
     * @param int $to Id of the target user
     * @param string $subject Title of the message
     * @param string $messagetext Body of the message
     */
    private function send_message ($from, $to, $subject, $messagetext) {
        global $PAGE;

        $PAGE->set_context(context_system::instance());
        $message = $subject . ': ' . $messagetext;
        message_post_message($from, $to, $message, FORMAT_PLAIN);
    }

}
