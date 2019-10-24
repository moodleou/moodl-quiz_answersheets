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
 * Quiz answer sheet utils.
 *
 * @package   quiz_answersheets
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_answersheets;

use action_link;
use context;
use context_module;
use html_writer;
use moodle_url;
use question_display_options;
use quiz_attempt;
use ReflectionClass;
use stdClass;
use user_picture;

defined('MOODLE_INTERNAL') || die();

/**
 * Quiz answer sheet utils.
 *
 * @package   quiz_answersheets
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {

    const ATTEMPT_SHEET_CREATED = 'attempt_created';
    const ATTEMPT_SHEET_PRINTED = 'attempt_printed';
    const ATTEMPT_SHEET_VIEWED = 'attempt_viewed';
    const RIGHT_ANSWER_SHEET_PRINTED = 'right_answer_printed';
    const RIGHT_ANSWER_SHEET_VIEWED = 'right_answer_viewed';
    const RESPONSES_SUBMITTED = 'responses_submitted';

    /**
     * Calculate summary information of a particular quiz attempt.
     * The code was copied from mod/quiz/review.php.
     *
     * @param quiz_attempt $attemptobj Attempt object
     * @param moodle_url $baseurl Base url
     * @param boolean $minimal True to only show the student fullname
     * @return array List of summary information
     */
    public static function prepare_summary_attempt_information(quiz_attempt $attemptobj, moodle_url $baseurl,
            $minimal = true): array {
        global $DB, $USER;

        $sumdata = [];
        $attempt = $attemptobj->get_attempt();
        $quiz = $attemptobj->get_quiz();
        $options = $attemptobj->get_display_options(true);

        if (!$attemptobj->get_quiz()->showuserpicture && $attemptobj->get_userid() != $USER->id) {
            $student = $DB->get_record('user', ['id' => $attemptobj->get_userid()]);
            $userpicture = new user_picture($student);
            $userpicture->courseid = $attemptobj->get_courseid();
            $sumdata['user'] = [
                    'title' => $userpicture,
                    'content' => new action_link(new moodle_url('/user/view.php',
                            ['id' => $student->id, 'course' => $attemptobj->get_courseid()]), fullname($student, true))
            ];
        }

        if ($minimal) {
            return $sumdata;
        }

        if ($attemptobj->has_capability('mod/quiz:viewreports')) {
            $attemptlist = $attemptobj->links_to_other_attempts($baseurl);
            if ($attemptlist) {
                $sumdata['attemptlist'] = [
                        'title' => get_string('attempts', 'quiz'),
                        'content' => $attemptlist
                ];
            }
        }

        $sumdata['startedon'] = [
                'title' => get_string('startedon', 'quiz'),
                'content' => userdate($attempt->timestart),
        ];

        $sumdata['state'] = [
                'title' => get_string('attemptstate', 'quiz'),
                'content' => quiz_attempt::state_name($attempt->state),
        ];

        $grade = quiz_rescale_grade($attempt->sumgrades, $quiz, false);

        if ($options->marks >= question_display_options::MARK_AND_MAX && quiz_has_grades($quiz)) {
            if ($attempt->state != quiz_attempt::FINISHED) {
                // Cannot display grade.
            } else if (is_null($grade)) {
                $sumdata['grade'] = [
                        'title' => get_string('grade', 'quiz'),
                        'content' => quiz_format_grade($quiz, $grade),
                ];
            } else {
                // Show raw marks only if they are different from the grade (like on the view page).
                if ($quiz->grade != $quiz->sumgrades) {
                    $a = new stdClass();
                    $a->grade = quiz_format_grade($quiz, $attempt->sumgrades);
                    $a->maxgrade = quiz_format_grade($quiz, $quiz->sumgrades);
                    $sumdata['marks'] = [
                            'title' => get_string('marks', 'quiz'),
                            'content' => get_string('outofshort', 'quiz', $a),
                    ];
                }

                // Now the scaled grade.
                $a = new stdClass();
                $a->grade = html_writer::tag('b', quiz_format_grade($quiz, $grade));
                $a->maxgrade = quiz_format_grade($quiz, $quiz->grade);
                if ($quiz->grade != 100) {
                    $a->percent = html_writer::tag('b', format_float(
                            $attempt->sumgrades * 100 / $quiz->sumgrades, 0));
                    $formattedgrade = get_string('outofpercent', 'quiz', $a);
                } else {
                    $formattedgrade = get_string('outof', 'quiz', $a);
                }
                $sumdata['grade'] = [
                        'title' => get_string('grade', 'quiz'),
                        'content' => $formattedgrade,
                ];
            }
        }

        // Any additional summary data from the behaviour.
        $sumdata = array_merge($sumdata, $attemptobj->get_additional_summary_data($options));

        // Feedback if there is any, and the user is allowed to see it now.
        $feedback = $attemptobj->get_overall_feedback($grade);
        if ($options->overallfeedback && $feedback) {
            $sumdata['feedback'] = [
                    'title' => get_string('feedback', 'quiz'),
                    'content' => $feedback,
            ];
        }

        return $sumdata;
    }

    /**
     * Get user detail with identity fields
     *
     * @param stdClass $attemptuser User info
     * @param context $context Context module
     * @return string User detail string
     */
    public static function get_user_details(stdClass $attemptuser, context $context): string {
        $userinfo = '';

        $userinfo .= fullname($attemptuser);

        $extra = get_extra_user_fields($context);
        $data = [];
        foreach ($extra as $field) {
            $value = $attemptuser->{$field};
            if (!$value) {
                continue;
            }
            $data[] = $value;
        }

        if (count($data) > 0) {
            $userinfo .= get_string('user_identity_fields', 'quiz_answersheets', implode(', ', $data));
        }

        return $userinfo;
    }

    /**
     * Check if can create attempt
     *
     * @param \quiz $quizobj Quiz object
     * @param array $attempts Array of attempts
     * @return bool
     */
    public static function can_create_attempt($quizobj, $attempts): bool {
        // Check if quiz is unlimited.
        if (!$quizobj->get_quiz()->attempts) {
            return true;
        }
        $numprevattempts = count($attempts);
        if ($numprevattempts == 0) {
            return true;
        }
        $lastattempt = end($attempts);
        $state = $lastattempt->state;
        if ($state && $state == quiz_attempt::FINISHED) {
            // Check max attempts
            $rule = new \quizaccess_numattempts($quizobj, time());
            if (!$rule->prevent_new_attempt($numprevattempts, $lastattempt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get instruction text for given question type
     *
     * @param string $questiontype Question type
     * @param string $questionnameprefix Question name
     * @return string instruction text
     */
    public static function get_question_instruction(string $questiontype, string $questionnameprefix = ''): string {
        $instructionexists = get_string_manager()->string_exists($questiontype . '_instruction', 'quiz_answersheets');
        if (!$instructionexists) {
            return '';
        } else {
            if (empty($questionnameprefix)) {
                return get_string($questiontype . '_instruction', 'quiz_answersheets');
            } else {
                $questioninstruction = new stdClass();
                $questioninstruction->questionname = $questionnameprefix;
                $questioninstruction->instruction = get_string($questiontype . '_instruction', 'quiz_answersheets');
                return get_string('instruction_prefix', 'quiz_answersheets', $questioninstruction);
            }
        }
    }

    /**
     * Prepare event data.
     *
     * @param int $attemptid Attempt id
     * @param int $userid User id
     * @param int $courseid Course id
     * @param context_module $context Module context
     * @param int $quizid Quiz id
     * @return array Event data
     */
    private static function prepare_event_data(int $attemptid, int $userid, int $courseid, context_module $context,
            int $quizid): array {
        $params = [
                'relateduserid' => $userid,
                'courseid' => $courseid,
                'context' => $context,
                'other' => [
                        'quizid' => $quizid,
                        'attemptid' => $attemptid
                ]
        ];

        return $params;
    }

    /**
     * Fire events.
     *
     * @param string $eventtype Event type name
     * @param int $attemptid Attempt id
     * @param int $userid User id
     * @param int $courseid Course id
     * @param context_module $context Module context
     * @param int $quizid Quiz id
     */
    public static function create_events(string $eventtype, int $attemptid, int $userid, int $courseid, context_module $context,
            int $quizid): void {
        $params = self::prepare_event_data($attemptid, $userid, $courseid, $context, $quizid);
        $classname = '\quiz_answersheets\event\\' . $eventtype;
        $event = $classname::create($params);
        $event->trigger();
    }

    /**
     * Get the protected property of given class.
     *
     * @param $originalclass Class that contain the protected property
     * @param string $propertyname Protected property that need to get value
     * @return mixed Protected value
     */
    public static function get_reflection_property($originalclass, string $propertyname) {
        $reflectionclass = new ReflectionClass($originalclass);
        $reflectionproperty = $reflectionclass->getProperty($propertyname);
        $reflectionproperty->setAccessible(true);
        $returnvalue = $reflectionproperty->getValue($originalclass);
        $reflectionproperty->setAccessible(false);

        return $returnvalue;
    }

}
