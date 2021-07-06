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
 * REST API lib.
 * @author    Guy Thomas
 * @copyright Copyright (c) 2017 Open LMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_collaborate\rest;

defined ('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../vendor/autoload.php');

use Horde\Socket\Client\Exception;
use mod_collaborate\local,
    mod_collaborate\logging\constants as loggingconstants,
    mod_collaborate\traits\api as apitrait,
    mod_collaborate\sessionlink,
    mod_collaborate\renderables\recording,
    mod_collaborate\recording_counter,
    mod_collaborate\task\soap_migrator_task,
    cm_info,
    stdClass;

class api {

    use apitrait;

    /**
     * @var stdClass {expires_in, access_token}
     */
    private $accesstoken = null;

    /**
     * @var int
     */
    private $accesstokenexpires = null;

    const DELETE = 'DELETE';
    const GET = 'GET';
    const PATCH = 'PATCH';
    const POST = 'POST';
    const PUT = 'PUT';

    /**
     * @var stdClass {expires_in, access_token}
     */
    private $accessmigrationtoken = null;

    /**
     * @var int
     */
    private $accessmigrationtokenexpires = null;

    private function __construct(stdClass $config) {
        $this->setup($config);
        if (isset($config->migrationstatus) && $config->migrationstatus != soap_migrator_task::STATUS_MIGRATED) {
            self::require_configured();
            $this->set_migration_accesstoken();
        } else if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            self::require_configured();
            $this->set_accesstoken();
        }
    }

    /**
     * Get API singleton instance.
     * @param bool $reset
     * @param bool $config
     * @return api
     */
    public static function instance($reset = false, $config = false) {
        static $instance;
        if ($reset) {
            $instance = null;
        }
        if (empty($instance)) {
            if (!$config) {
                $config = get_config('collaborate');
            }
            $instance = new api($config);
        }
        return $instance;
    }

    /**
     * Is REST API configured?
     * @param stdClass | bool $config
     * @return bool
     */
    public static function configured(stdClass $config = null) {
        if (!$config) {
            $config = get_config('collaborate');
        }
        return !empty($config) && !empty($config->restserver) && !empty($config->restkey) &&
            !empty($config->restsecret);
    }

    /**
     * @param string $methodparams method and param portion of url
     * @return string
     * @throws \Exception
     */
    private function api_url($methodparams) {
        $baseurl = trim($this->config->restserver);
        if (empty($baseurl)) {
            throw new \Exception('Error, baseurl not configured or invalid.');
        }
        if (substr($baseurl, -1) != '/') {
            $baseurl .= '/';
        }
        $url = $baseurl . $methodparams;
        return $url;
    }

    /**
     * Get access token.
     * @return mixed
     */
    public function get_accesstoken() {
        if ($this->accesstokenexpires < time()) {
            // Token has expired, get a new one!
            $this->set_accesstoken();
        }
        return $this->accesstoken->access_token;
    }

    /**
     * Set access token.
     */
    private function set_accesstoken() {
        $data = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => jwthelper::get_token($this->config->restkey, $this->config->restsecret)
        ];

        $this->logger->info('Getting access token with req data', $data);

        $reqopts = new requestoptions('', [], $data);

        try {
            $validationerr = new http_validation_code_error('error:restapifailedtocreateaccesstoken',
                loggingconstants::SEV_CRITICAL);
            $validation = new http_code_validation([200], [
                '400' => $validationerr,
                '401' => $validationerr,
            ]);
            $response = $this->rest_call(self::POST, 'token', $reqopts, $validation);
            $this->accesstoken = $response->object;
            if (!empty($this->accesstoken->access_token)) {
                $this->accesstokenexpires = time() + $this->accesstoken->expires_in;
                $this->usable = true;
            } else {
                $this->usable = false;
            }
        } catch (Exception $e) {
            $this->usable = false;
        }
    }

    public function is_usable() {
        return $this->usable;
    }

    protected function process_resource_path($resourcepath, array $pathparams) {
        $resourcepath = strtolower($resourcepath);
        if (substr($resourcepath, 0, 1) === '/') {
            $resourcepath = substr($resourcepath, 1);
        }
        if (empty($pathparams)) {
            return $resourcepath;
        }
        if (stripos($resourcepath, '{') !== false) {
            // Replace by keys.
            foreach ($pathparams as $key => $val) {
                $resourcepath = str_ireplace('{'.$key.'}', $val, $resourcepath);
            }
        } else {
            // Add params.
            foreach ($pathparams as $param) {
                $resourcepath .= '/' . $param;
            }
        }
        return $resourcepath;
    }

    protected function test_service_reachable($serviceuri) {
        $this->logger->info('Testing service availability: '.$serviceuri);

        $options = [
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0
        ];
        $curl = new \curl();
        $curl->setopt($options);
        $resp = $curl->get($serviceuri);
        $respobj = json_decode($resp);
        // We should get an error key if we hit the serviceuri without a path - e.g. /sessions.
        if (empty($respobj) || empty($respobj->errorKey)) {
            return false;
        }
        return true;

    }

    /**
     * @param string $verb
     * @param string $resourcepath
     * @param requestoptions $requestoptions
     * @param http_code_validation | null $validation
     * @return response
     */
    public function rest_call($verb, $resourcepath, requestoptions $requestoptions,
                              http_code_validation $validation = null) {

        global $CFG;

        if (empty($validation)) {
            $validation = new http_code_validation();
        }
        if ($resourcepath != 'token') {
            if (!$this->is_usable()) {
                if (!self::configured()) {
                    throw new \moodle_exception('error:noconfiguration', 'collaborate');
                } else {
                    if (!$this->test_service_reachable($this->config->restserver)) {
                        throw new \moodle_exception('error:restapiunreachable', 'collaborate');
                    } else {
                        throw new \moodle_exception('error:restapiunusable', 'collaborate');
                    }
                }
            }
        }
        $ch = curl_init();
        $headers = [];
        if ($resourcepath != 'token') {
            if (!self::configured()) {
                throw new \moodle_exception('error:noconfiguration', 'collaborate');
            }
            if ($this->accesstokenexpires < time()) {
                // Token has expired, get a new one!
                $this->set_accesstoken();
            }
            if (empty($this->accesstoken) || empty($this->accesstoken->access_token)) {
                throw new \moodle_exception('error:restapifailedtocreateaccesstoken', 'collaborate');
            }
            $headers[] = 'Authorization: Bearer '.$this->accesstoken->access_token;
        }
        $curloutput = null;
        if ($CFG->debugdeveloper) {
            $curloutput = fopen('php://temp', 'w+');
            if (is_resource($curloutput)) {
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_STDERR, $curloutput);
            }
        }
        $query = empty($requestoptions->queryparams) ? '' : '?' . http_build_query($requestoptions->queryparams, '', '&');
        $url = $this->api_url($this->process_resource_path($resourcepath, $requestoptions->pathparams) . $query);
        $this->logger->info('making curl call', ['verb' => $verb,  'url' => $url, 'json' => $requestoptions->bodyjson]);
        curl_setopt($ch, CURLOPT_URL, $url);
        switch ($verb) {
            case 'DELETE' :
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PATCH' :
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                break;
            case 'POST' :
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            // Note, for PUT we cannot use CURLOPT_PUT as it adds the header Expect: 100-continue.
            case 'PUT' :
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($verb == 'POST') {
            $postdata = !empty($requestoptions->postfields) ? $requestoptions->postfields : '';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        }
        if (!empty($requestoptions->bodyjson)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestoptions->bodyjson);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($requestoptions->bodyjson);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        // Add moodle proxy curlopts.
        if (!empty($CFG->proxyhost)) {
            if (empty($CFG->proxyport)) {
                $proxyhost = $CFG->proxyhost;
            } else {
                $proxyhost = $CFG->proxyhost.':'.$CFG->proxyport;
            }
            if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {
                $proxyauth = $CFG->proxyuser.':'.$CFG->proxypassword;
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyauth);
            }
            if (!empty($CFG->proxytype)) {
                if ($CFG->proxytype == 'SOCKS5') {
                    $proxytype = CURLPROXY_SOCKS5;
                } else {
                    $proxytype = CURLPROXY_HTTP;
                    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, false);
                }
                curl_setopt($ch, CURLOPT_PROXYTYPE, $proxytype);
            }
            curl_setopt($ch, CURLOPT_PROXY, $proxyhost);
        }
        $jsonstr = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->logger->info('response', ['httpcode' => $httpcode]);
        $this->logger->info('response', ['jsonstr' => $jsonstr]);

        if ($CFG->debugdeveloper && is_resource($curloutput)) {
            rewind($curloutput);
            $output = stream_get_contents($curloutput);
            if ($output !== false) {
                $this->logger->info('debug request and response', [$output]);
            }
            fclose($curloutput);
        }

        $response = new response($jsonstr, $httpcode);
        $validation->validate_response($response);
        return $response;
    }

    /**
     * @param stdClass $collaborate
     * @param int|null $groupid
     * @return object
     */
    private function make_session_request_object($collaborate, $groupid = null) {

        $collaborate->timeend = local::timeend_from_duration($collaborate->timestart, $collaborate->duration);
        $noenddate = local::timeend_open_ended($collaborate->timeend);
        list ($timestart, $timeend) = local::get_apitimes($collaborate->timestart, $collaborate->duration);

        $sessionname = $collaborate->name;
        if ($groupid !== null) {
            // Append sessionname with groupname.
            $groupname = groups_get_group_name($groupid);
            $sessionname .= ' ('.$groupname.')';
        }

        $description = isset($collaborate->introeditor['text']) ? $collaborate->introeditor['text'] : $collaborate->intro;

        $allowguests = !empty($collaborate->guestaccessenabled) && $collaborate->guestaccessenabled == 1;
        $guestrole = '';
        if ($allowguests) {
            switch ($collaborate->guestrole) {
                case 'pa' :
                    $guestrole = 'Participant';
                    break;
                case 'pr' :
                    $guestrole = 'Presenter';
                    break;
                case 'mo' :
                    $guestrole = 'Moderator';
                    break;
                default :
                    $guestrole = 'Participant';
            }
        }

        $cansharevideo = isset($collaborate->cansharevideo) && boolval($collaborate->cansharevideo) ? 1 : 0;
        $canpostmessages = isset($collaborate->canpostmessages) && boolval($collaborate->canpostmessages) ? 1 : 0;
        $canannotatewhiteboard = isset($collaborate->canannotatewhiteboard) && boolval($collaborate->canannotatewhiteboard) ? 1 : 0;
        $canshareaudio = isset($collaborate->canshareaudio) && boolval($collaborate->canshareaudio) ? 1 : 0;
        $candownloadrecordings = isset($collaborate->candownloadrecordings) && boolval($collaborate->candownloadrecordings) ? 1 : 0;
        $largesessionenable = isset($collaborate->largesessionenable) && boolval($collaborate->largesessionenable) ? 1 : 0;
        $now = time();

        $session = (object) [
            "allowInSessionInvitees" => true,
            "allowGuest" => $allowguests,
            "openChair" => false, // Hard coded.
            "mustBeSupervised" => true, // Hard coded.
            "noEndDate" => $noenddate,
            "description" => strip_tags($description),
            "name" => $sessionname,
            "occurrenceType" => "S", // Hard coded.
            "canPostMessage" => $canpostmessages,
            "participantCanUseTools" => true, // Hard coded.
            "courseRoomEnabled" => true, // Hard coded.
            "canAnnotateWhiteboard" => $canannotatewhiteboard,
            "canDownloadRecording" => $candownloadrecordings,
            "canShareVideo" => $cansharevideo,
            "raiseHandOnEnter" => false, // Hard coded.
            "boundaryTime" => local::boundary_time(),
            "showProfile" => false, // Hard coded.
            "canShareAudio" => $canshareaudio,
            "largeSessionEnable" => $largesessionenable,
            "startTime" => $timestart,
            "modified" => $now
        ];

        if (!empty($guestrole)) {
            $session->guestRole = $guestrole;
        }

        if (!isset($collaborate->sessionuid)) {
            $session->created = $now;
        }

        if (!$noenddate) {
            $session->endTime = $timeend;
        }

        return $session;
    }

    public function create_session(stdClass $collaborate, stdClass $sessionlink, stdClass $course = null) {

        // Note - Collaborate REST API does not allow for bulk enrollments on creation / update so we do not bother
        // doing it here (users get enrolled on the fly when they click "join").
        $groupid = $sessionlink->groupid;

        local::prepare_sessionids_for_query($collaborate);

        $session = $this->make_session_request_object($collaborate, $groupid);

        $response = $this->rest_call(self::POST, 'sessions', new requestoptions(json_encode($session)));
        $respobj = $response->object;

        if (!isset($respobj->id)) {
            $msg = 'Failed to create REST session';
            $errorarr = ['request_json' => json_encode($session), 'response' => var_export($respobj, true)];
            $this->process_error('error:apicallfailed', loggingconstants::SEV_CRITICAL, null, $msg, $errorarr);
        }
        $sessionuid = $respobj->id;

        if ($groupid === null) {
            // Update the main collaborate instance, this is not for a group.
            $this->update_collaborate_instance_record($collaborate, $respobj);
        }

        return ($sessionuid);
    }

    public function update_collaborate_instance_record(stdClass $collaborate, $respobj) {
        global $DB;

        $sessionid = $respobj->id;

        // Update the main collaborate instance, this is not for a group.
        $collaborate->sessionid = null;
        $collaborate->sessionuid = $sessionid;
        $collaborate->timemodified = time();
        $collaborate->timestart = strtotime($respobj->startTime);
        if ($collaborate->timeend != strtotime(local::TIMEDURATIONOFCOURSE)) {
            $collaborate->timeend = strtotime($respobj->endTime);
            $collaborate->duration = $collaborate->timeend - $collaborate->timestart;
        }

        if (empty($collaborate->guestaccessenabled)) {
            // This is necessary as an unchecked check box just removes the property instead of setting it to 0.
            $collaborate->guestaccessenabled = 0;
        }
        $collaborate->canenablelargesession = $respobj->canEnableLargeSession;

        $result = $DB->update_record('collaborate', $collaborate);

        if ($result) {
            collaborate_grade_item_update($collaborate);
            local::update_calendar($collaborate);
        }

        return $result;
    }

    public function update_session(stdClass $collaborate, stdClass $sessionlink, stdClass $course = null) {

        // Note - Collaborate REST API does not allow for bulk enrollments on creation / update so we do not bother
        // doing it here (users get enrolled on the fly when they click "join").

        if (empty($collaborate->sessionuid)) {
            throw new \coding_exception('Collaborate row must have a sessionuid property for an update to be possible');
        }

        if (empty($collaborate->sessionid)) {
            // We need to make sure that the old sessionid is null if it's passed via the form and it is 0.
            $collaborate->sessionid = null;
        }

        $sessionuid = $sessionlink->sessionuid;
        $session = $this->make_session_request_object($collaborate, $sessionlink->groupid);
        $response = $this->rest_call(self::PUT, 'sessions', new requestoptions(json_encode($session), [$sessionuid]));

        if (empty($sessionlink->groupid)) {
            // Update the main collaborate instance, this is not for a group.
            $this->update_collaborate_instance_record($collaborate, $response->object);
        }

        return ($sessionuid);
    }

    /**
     * Creates a user in Collaborate. Note - purposefully private scope.
     * @param int $userid - Moodle userid
     * @param string $avatarurl
     * @param string $displayname
     * @param string $firstname
     * @param string $lastname
     * @return mixed
     */
    private function create_user($userid, $avatarurl, $displayname, $firstname, $lastname) {
        $user = (object) [
            "firstName" => $firstname,
            "lastName" => $lastname,
            "avatarUrl" => $avatarurl,
            "displayName" => $displayname,
            "extId" => $userid,
            "created" => $this->api_datetime(time()),
            "modified" => $this->api_datetime(time())
        ];
        $reqopts = new requestoptions(json_encode($user));
        $this->rest_call(self::POST, '/users', $reqopts);
        return $this->get_user($userid);
    }

    /**
     * Get Collaborate user by moodle userid. Intentionally private scope.
     * @param int $userid - Moodle userid
     * @return stdClass|bool
     */
    private function get_user($userid) {
        $reqopts = new requestoptions('', [], ['extId' => $userid]);
        $validation = new http_code_validation([200, 404]);
        $response = $this->rest_call(self::GET, '/users', $reqopts, $validation);
        if (empty($response->object->results)) {
            return false;
        } else if (count($response->object->results) > 1) {
            $this->process_error('error:restapiduplicateusers',
                loggingconstants::SEV_CRITICAL, $userid);
        }
        return reset($response->object->results);
    }

    /**
     * Ensure user record in Collaborate and return it.
     * @param int $userid - Moodle userid
     * @param $avatarurl
     * @param $displayname
     * @param $firstname
     * @param $lastname
     * @return mixed
     */
    private function ensure_user($userid, $avatarurl, $displayname, $firstname, $lastname) {
        $user = $this->get_user($userid);
        if (!$user) {
            $user = $this->create_user($userid, $avatarurl, $displayname, $firstname, $lastname);
        }
        return $user;
    }

    /**
     * Update user record in Collaborate and return it.
     * @param int $userid
     * @param string $avatarurl
     * @param string $displayname
     * @param string $firstname
     * @param string $lastname
     * @return mixed
     */
    private function update_user($userid, $avatarurl, $displayname, $firstname, $lastname) {
        $user = $this->ensure_user($userid, $avatarurl, $displayname, $firstname, $lastname);
        $collaborateuserid = $user->id;
        $update = (object) [
            "firstName" => $firstname,
            "lastName" => $lastname,
            "avatarUrl" => $avatarurl,
            "displayName" => $displayname,
            "extId" => $userid,
            "modified" => $this->api_datetime(time())
        ];
        $reqops = new requestoptions(json_encode($update), ['userId' => $collaborateuserid]);
        $response = $this->rest_call(self::PUT, '/users/{userId}', $reqops);
        return $response->object;
    }

    public function update_attendee($sessionid, $userid, $avatarurl, $displayname, $role, $firstname, $lastname) {

        $user = $this->update_user($userid, $avatarurl, $displayname, $firstname, $lastname);
        $collabuserid = $user->id;

        $reqoptions = new requestoptions('', ['sessionId' => $sessionid], ['userId' => $collabuserid]);
        $validation = new http_code_validation([200, 404]);
        $response = $this->rest_call(self::GET, '/sessions/{sessionId}/enrollments', $reqoptions, $validation);
        $enrollment = $response->object;
        if (!isset($enrollment->results)) {
            $enrollment = false;
        } else {
            if (count($enrollment->results) > 1) {
                $this->process_error('error:restapimultpleenrollments',
                    loggingconstants::SEV_CRITICAL, (object) ['sessionid' => $sessionid, 'userid' => $collabuserid]);
            }
            $enrollment = reset($enrollment->results);
        }
        $enrollobj = (object) [
            "launchingRole" => $role,
            "editingPermission" => $role === 'moderator' ? 'writer' : 'reader',
            "userId" => $collabuserid
        ];
        if (!$enrollment) {
            // Enrolllment does not exist!
            // Just create one.
            $reqopts = new requestoptions(json_encode($enrollobj), ['sessionId' => $sessionid]);
            $enrollmentresponse = $this->rest_call(self::POST, '/sessions/{sessionId}/enrollments', $reqopts);
            if (empty($enrollmentresponse->object->userId)) {
                $this->process_error('error:restapifailedtoenroll',
                    loggingconstants::SEV_CRITICAL, (object) ['sessionid' => $sessionid, 'userid' => $collabuserid]);
            }
            return $enrollmentresponse->object->permanentUrl;
        }
        $reqopts = new requestoptions(json_encode($enrollobj),
                ['sessionId' => $sessionid, 'enrollmentId' => $enrollment->id]);
        $enrollmentresponse = $this->rest_call(self::PUT, '/sessions/{sessionId}/enrollments/{enrollmentId}', $reqopts);
        return $enrollmentresponse->object->permanentUrl;
    }

    public function delete_session($sessionid) {

        // API request deletion.
        $this->set_silent(true);

        $reqopts = new requestoptions(null, ['sessionId' => $sessionid]);

        // Deletion validation is taken care of by default check for 200 http code status.
        try {
            $this->rest_call(self::DELETE, '/sessions/{sessionId}', $reqopts);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public function guest_url($sessionid) {
        $reqopts = new requestoptions(null, ['sessionId' => $sessionid]);
        $response = $this->rest_call(self::GET, '/sessions/{sessionId}', $reqopts);

        if (!isset($response->object->guestUrl)) {
            $this->process_error('error:restapisessionguesturlmissing',
                loggingconstants::SEV_CRITICAL, (object) ['sessionid' => $sessionid]);
        }

        return $response->object->guestUrl;
    }

    /**
     * Get specific recording url by recid
     * @param string $recid
     * @param string $disposition launch | download
     * @return mixed
     */
    public function get_recording_url($recid, $disposition = 'launch') {
        $reqopts = new requestoptions('', ['recordingId' => $recid], ['validHours' => 1, 'disposition' => $disposition]);
        $response = $this->rest_call(self::GET, 'recordings/{recordingId}/url', $reqopts);

        return $response->object->url;
    }

    public function get_recordings(stdClass $collaborate, cm_info $cm, $canmoderate = false) {
        $sessionlinks = sessionlink::my_active_links($collaborate, $cm);

        $sessionrecordings = [];

        foreach ($sessionlinks as $sessionlink) {
            if (empty($sessionlink->sessionuid)) {
                continue;
            }

            $validation = new http_code_validation([200, 404]);
            $result = $this->rest_call(self::GET, 'recordings',
                new requestoptions('', [], ['sessionId' => $sessionlink->sessionuid]), $validation
            );

            $recordings = [];
            if (!empty($result->object->results)) {
                $recordings = $result->object->results;
            }
            $sessionrecordings[$sessionlink->sessionuid] = $recordings;
        }

        $modelsbysessionuid = [];

        $allrecordingmodels = [];

        foreach ($sessionrecordings as $sessionuid => $recordings) {

            if (empty($recordings)) {
                continue;
            }

            usort($recordings, function($a, $b) {
                return ($a->created > $b->created);
            });

            // Only segregate by titles if there are multiple sessions per this instance.
            foreach ($recordings as $recording) {

                $name = $recording->name;
                $recid = $recording->id;
                if (preg_match('/^recording_\d+$/', $name)) {
                    $name = str_replace('recording_', '', get_string('recording', 'collaborate', $name));
                }
                $datetimestart = new \DateTime($recording->created);
                $datetimestart = $datetimestart->getTimestamp();
                $duration = round($recording->duration / 1000);
                $datetimeend = $datetimestart + $duration;

                $viewurl = 'rest_launch';
                $downloadurl = null;
                if ($recording->canDownload) {
                    $downloadurl = 'rest_download';
                }

                $model = new recording();
                $model->id = $recid;
                $model->starttime = $datetimestart;
                $model->endtime = $datetimeend;
                $model->duration = $duration;
                $model->name = $name;
                $model->viewurl = $viewurl;
                $model->downloadurl = $downloadurl;

                $allrecordingmodels[$recid] = $model;

                if (!isset($modelsbysessionuid[$sessionuid])) {
                    $modelsbysessionuid[$sessionuid] = [];
                }
                $modelsbysessionuid[$sessionuid][] = $model;
            }
        }

        $recordingcounts = [];
        if ($canmoderate) {
            $recordingcounthelper = new recording_counter($cm, $allrecordingmodels);
            $recordingcounts = $recordingcounthelper->get_recording_counts();
        }

        foreach ($modelsbysessionuid as $sessionuid => $models) {
            foreach ($models as $model) {
                if (!empty($recordingcounts[$model->id])) {
                    $model->count = $recordingcounts[$model->id];
                }
            }
        }

        return $modelsbysessionuid;
    }

    public function delete_recording($recordingid) {
        $this->rest_call(self::DELETE, 'recordings/{recordingId}',
            new requestoptions('', ['recordingId' => $recordingid])
        );
    }

    /**
     * Set migration access token.
     * @param bool $testingpurpose If true, returns the token generated instead of setting it.
     * @return response access_token.
     */
    public function set_migration_accesstoken($testingpurpose = false) {
        $data = [
            'grant_type' => 'password',
            'username' => $this->config->restkey,
            'password' => $this->config->restsecret
        ];

        $this->logger->info('Getting access migration token with req data', $data);

        $reqopts = new requestoptions('', [], $data);

        try {
            $validationerr = new http_validation_code_error('error:restapifailedtocreateaccesstoken',
                loggingconstants::SEV_CRITICAL);
            $validation = new http_code_validation([200], [
                '400' => $validationerr,
                '401' => $validationerr,
            ]);
            $response = $this->rest_migration_call(self::POST, 'token', $reqopts, $validation);
            $this->accessmigrationtoken = $response->object;
            if (!empty($this->accessmigrationtoken->access_token)) {
                $this->accessmigrationtokenexpires = time() + $this->accessmigrationtoken->expires_in;
                $this->usable = true;
            } else {
                $this->usable = false;
            }
            if ($testingpurpose) {
                return $response->object;
            }
        } catch (Exception $e) {
            $this->usable = false;
        }
    }

    /**
     * @param string $verb
     * @param string $resourcepath
     * @param requestoptions $requestoptions
     * @param http_code_validation | null $validation
     * @return response
     */
    public function rest_migration_call($verb, $resourcepath, requestoptions $requestoptions,
                              http_code_validation $validation = null) {

        global $CFG;

        if (empty($validation)) {
            $validation = new http_code_validation();
        }
        if ($resourcepath != 'token') {
            if (!$this->is_usable()) {
                if (!self::configured()) {
                    throw new \moodle_exception('error:noconfiguration', 'collaborate');
                } else {
                    if (!$this->test_service_reachable($this->config->restserver)) {
                        throw new \moodle_exception('error:restapiunreachable', 'collaborate');
                    } else {
                        throw new \moodle_exception('error:restapiunusable', 'collaborate');
                    }
                }
            }
        }
        $ch = curl_init();
        $headers = [];
        if ($resourcepath != 'token') {
            if (!self::configured()) {
                throw new \moodle_exception('error:noconfiguration', 'collaborate');
            }
            if ($this->accessmigrationtokenexpires < time()) {
                // Token has expired, get a new one!
                $this->set_migration_accesstoken();
            }
            if (empty($this->accessmigrationtoken) || empty($this->accessmigrationtoken->access_token)) {
                throw new \moodle_exception('error:restapifailedtocreateaccesstoken', 'collaborate');
            }
            $headers[] = 'Authorization: Bearer '.$this->accessmigrationtoken->access_token;
        }
        $curloutput = null;
        if ($CFG->debugdeveloper) {
            $curloutput = fopen('php://temp', 'w+');
            if (is_resource($curloutput)) {
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_STDERR, $curloutput);
            }
        }
        $query = empty($requestoptions->queryparams) ? '' : '?' . http_build_query($requestoptions->queryparams, '', '&');
        $url = $this->api_url($this->process_resource_path($resourcepath, $requestoptions->pathparams) . $query);
        $this->logger->info('making curl call', ['verb' => $verb,  'url' => $url, 'json' => $requestoptions->bodyjson]);
        curl_setopt($ch, CURLOPT_URL, $url);
        switch ($verb) {
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            // Note, for PUT we cannot use CURLOPT_PUT as it adds the header Expect: 100-continue.
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($verb == 'POST') {
            $postdata = !empty($requestoptions->postfields) ? $requestoptions->postfields : '';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        }
        if (!empty($requestoptions->bodyjson)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestoptions->bodyjson);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($requestoptions->bodyjson);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $jsonstr = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->logger->info('response', ['httpcode' => $httpcode]);
        $this->logger->info('response', ['jsonstr' => $jsonstr]);

        if ($CFG->debugdeveloper && is_resource($curloutput)) {
            rewind($curloutput);
            $output = stream_get_contents($curloutput);
            if ($output !== false) {
                $this->logger->info('debug request and response', [$output]);
            }
            fclose($curloutput);
        }

        $response = new response($jsonstr, $httpcode);
        $validation->validate_response($response);
        return $response;
    }

    public function launch_soap_migration() {
        $requestobj = new requestoptions(''); // Docs says this call does not need params.
        $validation = new http_code_validation([202]); // Default validates code 200, need to create a custom for 202.
        $this->rest_migration_call(self::POST, '/migration', $requestobj, $validation);
    }

    public function check_soap_migration_status() {
        $requestobj = new requestoptions(''); // Docs says this call does not need params.
        $response = $this->rest_migration_call(self::GET, '/migration/status', $requestobj);

        if (!isset($response->object->status)) {
            $this->process_error('error:restapimigrationstatus', loggingconstants::SEV_CRITICAL);
        }

        return $response->object->status;
    }
}
