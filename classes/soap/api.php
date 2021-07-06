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

namespace mod_collaborate\soap;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../vendor/autoload.php');

use mod_collaborate\logging\constants,
    mod_collaborate\local,
    mod_collaborate\soap\fakeapi,
    mod_collaborate\soap\generated\HtmlSession,
    mod_collaborate\soap\generated\SetHtmlSession,
    mod_collaborate\soap\generated\HtmlAttendee,
    mod_collaborate\soap\generated\HtmlAttendeeCollection,
    mod_collaborate\soap\generated\UpdateHtmlSessionAttendee,
    mod_collaborate\soap\generated\UpdateHtmlSessionDetails,
    mod_collaborate\soap\generated\HtmlSessionRecording,
    mod_collaborate\soap\generated\RemoveHtmlSessionRecording,
    mod_collaborate\soap\generated\RemoveHtmlSession,
    mod_collaborate\soap\generated\SuccessResponse,
    mod_collaborate\soap\generated\BuildHtmlSessionUrl,
    mod_collaborate\traits\api as apitrait,
    mod_collaborate\iface\api_session,
    mod_collaborate\iface\api_attendee,
    mod_collaborate\iface\api_recordings,
    mod_collaborate\logging\constants as loggingconstants,
    mod_collaborate\renderables\recording,
    mod_collaborate\recording_counter,
    mod_collaborate\sessionlink,
    cm_info,
    stdClass;

/**
 * The collab api.
 *
 * @package   mod_collaborate
 * @copyright Copyright (c) 2015 Open LMS (https://www.openlms.net)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api extends generated\SASDefaultAdapter implements api_session, api_attendee, api_recordings {

    use apitrait;

    /**
     * If true, do not output any error messages.
     * @var bool
     */
    protected $silent = false;

    /**
     * Constructor
     *
     * @param array $options
     * @param string $wsdl - just here to match base class.
     * @param stdClass $config - custom config passed in on construct.
     */
    public function __construct(array $options = array(), $wsdl = null, stdClass $config = null) {
        global $CFG;

        $this->setup($config);

        $config = $this->config;

        self::require_configured();

        // Set wsdl to local version.
        $wsdl = __DIR__ . '/../../wsdl.xml';

        // Set service end point if populated.
        if (!empty($config->server)) {
            $options['location'] = $config->server;
        }

        $options['login'] = $config->username;
        $options['password'] = $config->password;

        if (!empty($config->wsdebug)) {
            ini_set('soap.wsdl_cache_enabled', '0');
            ini_set('soap.wsdl_cache_ttl', '0');
            $options['trace'] = 1;
        }

        // Add moodle proxy soap options.
        if (!empty($CFG->proxyhost)) {
            $options['proxy_host'] = $CFG->proxyhost;
            if (!empty($CFG->proxyport)) {
                $options['proxy_port'] = $CFG->proxyport;
            }
            if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {
                $options['proxy_login'] = $CFG->proxyuser;
                $options['proxy_password'] = $CFG->proxypassword;
            }
        }

        $serviceok = $this->test_service_reachable($options['location']);
        if (!$serviceok) {
            $this->usable = false;
            return;
        }
        try {
            parent::__construct($options, $wsdl);
            $this->usable = true;
        } catch (\Exception $e) {
            $this->usable = false;
        }
    }

    /**
     * Is SOAP API configured?
     * @param stdClass | bool $config
     * @return bool
     */
    public static function configured(stdClass $config = null) {
        if (!$config) {
            $config = get_config('collaborate');
        }
        return !empty($config) && !empty($config->server) && !empty($config->username) &&
            !empty($config->password);
    }

    /**
     * Get api.
     * @param bool $reset
     * @param array $options
     * @param string $wsdl
     * @param stdClass $config
     * @return api
     */
    public static function get_api($reset = false, $options = [], $wsdl = null, stdClass $config = null) {
        static $api;
        if ($api && !$reset) {
            return $api;
        }
        if (local::duringtesting()) {
            $api = new fakeapi($options, $wsdl, $config);
        } else {
            $api = new api($options, $wsdl, $config);
        }
        return $api;
    }

    /**
     * Set usable status.
     *
     * @param $usable
     */
    public function set_usable($usable) {
        $this->usable = $usable;
    }

    public function is_usable() {
        return $this->usable;
    }

    /**
     * Quickly test service is reachable
     *
     * @param $serviceuri
     * @return bool
     */
    protected function test_service_reachable($serviceuri) {
        global $CFG;
        $ch = curl_init();
        $this->logger->info('Testing service availability: '.$serviceuri);
        curl_setopt($ch, CURLOPT_URL, $serviceuri);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
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
        $body = curl_exec($ch);
        if (stripos($body, '<soap:Envelope') === false) {
            // Body does not contain expected text, service is bad.
            $body = false;
        }
        if (!$body) {
            $this->logger->critical(get_string('error:serviceunreachable', 'mod_collaborate'));
            return false;
        } else {
            $this->logger->info('Service accessible');
            return true;
        }
    }

    /**
     * Gets last soap request and response - strips out credentials.
     *
     * @return array
     */
    public function get_soap_req_resp() {

        $debug = get_config('collaborate', 'wsdebug');
        if (!$debug) {
            return [
                'Service debugging not enabled in config',
                'Service debugging not enabled in config',
                'Service debugging not enabled in config',
                'Service debugging not enabled in config'
            ];
        }

        // Get last request xml but remove soap api password.
        libxml_use_internal_errors(true);
        $lastrequest = $this->__getLastRequest();
        $lastrequestheaders = $this->__getLastRequestHeaders();

        $reqx = simplexml_load_string($lastrequest);
        if ($reqx) {
            $passwords = $reqx->xpath('//ns1:Password');
            for ($c = 0; $c < count($passwords); $c++) {
                $passwords[$c][0] = '****';
            }
            $lastrequest = $reqx->asXML();
        }
        $lastresponse = $this->__getLastResponse();
        $lastresponseheaders = $this->__getLastResponseHeaders();

        return [
            $lastrequestheaders,
            $lastrequest,
            $lastresponseheaders,
            $lastresponse
        ];
    }

    /**
     * Override parent __soapCall method
     *
     * @param $function_name
     * @param $arguments
     * @param null $options
     * @param null $input_headers
     * @param null $output_headers
     * @return mixed
     * @throws \moodle_exception
     */
    public function __soapCall($function_name, $arguments, $options = null, $input_headers = null, &$output_headers = null) {

        $start = microtime(true);

        if (!$this->usable) {
            $key = 'error:apifailure';
            $this->process_error($key, constants::SEV_CRITICAL);
            if ($this->silent) {
                return false;
            }
        }

        $config = $this->config;

        $headerbody = array('Name' => $config->username,
        'Password' => $config->password);
        $ns = 'http://sas.elluminate.com/';
        $header = new \SOAPHeader($ns, 'BasicAuth', $headerbody);
        $this->__setSoapHeaders($header);

        try {
            $result = parent::__soapCall($function_name, $arguments, $options, $input_headers, $output_headers);
        } catch (\SoapFault $fault) {
            $soapfault = "SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})";

            list ($lreqheaders, $lastreq, $lrespheaders, $lastresp) = $this->get_soap_req_resp();
            $errorarr = [
                'request_headers' => $lreqheaders,
                'request' => $lastreq,
                'response_headers' => $lrespheaders,
                'response' => $lastresp
            ];

            $this->process_error('error:apifailure', constants::SEV_CRITICAL, $soapfault, $errorarr);

            if ($this->silent) {
                return false;
            }
        }

        // OK there were no faults.
        // This soap call will already have been logged if there was an error ubt the script will have died so we wont
        // get duplicate calls logged.
        $duration = round(microtime(true) - $start, 2);
        list ($lreqheaders, $lastreq, $lrespheaders, $lastresp) = $this->get_soap_req_resp();
        $this->logger->debug('SOAP CALL SUCCESS ('.$duration.' S)', [
            'request_headers' => $lreqheaders,
            'request' => $lastreq,
            'response_headers' => $lrespheaders,
            'response' => $lastresp]);

        return ($result);
    }

    /**
     * Create appropriate session param element for new session or existing session.
     *
     * @param stdClass $data
     * @param stdClass $course
     * @param null|int $sessionid
     * @param null|int $groupid
     * @return SetHtmlSession|UpdateHtmlSessionDetails
     */
    private function el_html_session($data, $course, $sessionid = null, $groupid = null) {
        global $USER;

        $sessionname = $data->name;
        if ($groupid !== null) {
            // Append sessionname with groupname.
            $groupname = groups_get_group_name($groupid);
            $sessionname .= ' ('.$groupname.')';
        }

        // Main variables for session.
        list ($timestart, $timeend) = local::get_apitimes($data->timestart, $data->duration, 'soap');
        $description = isset($data->introeditor['text']) ? $data->introeditor['text'] : $data->intro;

        // Setup appropriate session - set or update.
        if (empty($sessionid)) {
            // New session.
            $htmlsession = new SetHtmlSession($sessionname, $timestart, $timeend, $USER->id);
            $data->guesturl = '';
        } else {
            // Update existing session.
            $htmlsession = new UpdateHtmlSessionDetails($sessionid);
            $htmlsession->setName($sessionname);
            $htmlsession->setStartTime($timestart);
            $htmlsession->setEndTime($timeend);
        }
        $htmlsession->setDescription(strip_tags($description));
        $htmlsession->setBoundaryTime(local::boundary_time());
        $htmlsession->setMustBeSupervised(true);
        $allowguests = !empty($data->guestaccessenabled) && $data->guestaccessenabled == 1;
        $htmlsession->setAllowGuest($allowguests);
        if ($allowguests) {
            switch ($data->guestrole) {
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
            $htmlsession->setGuestRole($guestrole);
        }

        // Add attendees to html session.
        $attendees = new HtmlAttendeeCollection();
        $participantgroupid = empty($groupid) ? 0 : $groupid;
        $moderators = local::moderator_enrolees($course, $participantgroupid);
        $participants = local::participant_enrolees($course, $participantgroupid);
        $attarr = [];
        foreach ($moderators as $moderatorid) {
            $attarr[] = new HtmlAttendee($moderatorid, 'moderator');
        }
        foreach ($participants as $participantid) {
            $attarr[] = new HtmlAttendee($participantid, 'participant');
        }
        $attendees->setHtmlAttendee($attarr);
        $htmlsession->setHtmlAttendees([$attendees]);

        return $htmlsession;
    }

    /**
     * Build SetHtmlSession element
     *
     * @param stdClass $data
     * @param stdClass $course
     * @param null|int $groupid
     * @return SetHtmlSession
     */
    public function el_set_html_session($data, $course, $groupid = null) {
        return $this->el_html_session($data, $course, null, $groupid);
    }

    /**
     * Build UpdateHtmlSession element
     *
     * @param $data
     * @param stdClass $course
     * @param stdClass $sessionlink
     * @return SetHtmlSession
     */
    public function el_update_html_session($data, $course, $sessionlink) {
        return $this->el_html_session($data, $course, $sessionlink->sessionid, $sessionlink->groupid);
    }

    public function create_session(stdClass $collaborate, stdClass $sessionlink, stdClass $course = null) {
        $groupid = $sessionlink->groupid;

        $config = get_config('collaborate');

        $collaborate->timeend = local::timeend_from_duration($collaborate->timestart, $collaborate->duration);
        $htmlsession = $this->el_set_html_session($collaborate, $course, $groupid);

        $result = $this->SetHtmlSession($htmlsession);
        if (!$result) {
            $msg = 'SetHtmlSession';
            if (!empty($config->wsdebug)) {
                $msg .= ' - returned: '.var_export($result, true);
            }
            $this->process_error('error:apicallfailed', loggingconstants::SEV_CRITICAL, null, $msg);
        }
        $respobjs = $result->getHtmlSession();
        if (!is_array($respobjs) || empty($respobjs)) {
            $this->process_error(
                'error:apicallfailed', loggingconstants::SEV_CRITICAL,
                'SetHtmlSession - failed on $result->getApolloSessionDto()'
            );
        }
        $respobj = $respobjs[0];
        $sessionid = $respobj->getSessionId();

        if ($groupid === null) {
            // Update the main collaborate instance, this is not for a group.
            $this->update_collaborate_instance_record($collaborate, $respobj);
        }

        return ($sessionid);
    }

    public function update_collaborate_instance_record(stdClass $collaborate, $htmlsession) {
        global $DB;

        $sessionid = $htmlsession->getSessionId();

        // Update the main collaborate instance, this is not for a group.
        local::prepare_sessionids_for_query($collaborate);
        $collaborate->sessionid = $sessionid;
        $collaborate->timemodified = time();
        $collaborate->timestart = $htmlsession->getStartTime()->getTimestamp();
        if ($collaborate->timeend != strtotime(local::TIMEDURATIONOFCOURSE)) {
            $collaborate->timeend = $htmlsession->getEndTime()->getTimestamp();
            $collaborate->duration = $collaborate->timeend - $collaborate->timestart;
        }

        if (empty($collaborate->guestaccessenabled)) {
            // This is necessary as an unchecked check box just removes the property instead of setting it to 0.
            $collaborate->guestaccessenabled = 0;
        }

        $result = $DB->update_record('collaborate', $collaborate);

        if ($result) {
            collaborate_grade_item_update($collaborate);
            local::update_calendar($collaborate);
        }

        return $result;
    }

    public function update_session(stdClass $collaborate, stdClass $sessionlink, stdClass $course = null) {
        $config = get_config('collaborate');

        $collaborate->timeend = local::timeend_from_duration($collaborate->timestart, $collaborate->duration);
        $htmlsession = $this->el_update_html_session($collaborate, $course, $sessionlink);

        if ($htmlsession instanceof SetHtmlSession) {
            $collaborate->sessionid = $this->create_session($collaborate, $sessionlink, $course);
            return ($collaborate->sessionid);
        } else if ($htmlsession instanceof UpdateHtmlSessionDetails) {
            $result = $this->UpdateHtmlSession($htmlsession);
        } else {
            $msg = 'el_update_html_session returned an unexpected object. ';
            $msg .= 'Should have been either mod_collaborate\\soap\\generated\\SetHtmlSession OR ';
            $msg .= 'mod_collaborate\\soap\\generated\\UpdateHtmlSessionDetails. ';
            $msg .= 'Returned: '.var_export($htmlsession, true);
            throw new coding_exception($msg);
        }

        if (!$result) {
            $msg = 'SetHtmlSession';
            if (!empty($config->wsdebug)) {
                $msg .= ' - returned: '.var_export($result, true);
            }
            $this->process_error('error:apicallfailed', loggingconstants::SEV_CRITICAL, null, $msg);
        }
        $respobjs = $result->getHtmlSession();
        if (!is_array($respobjs) || empty($respobjs)) {
            $this->process_error(
                'error:apicallfailed', loggingconstants::SEV_CRITICAL, null,
                'SetHtmlSession - failed on $result->getApolloSessionDto()'
            );
        }
        $respobj = $respobjs[0];
        $sessionid = $respobj->getSessionId();

        if (empty($sessionlink->groupid)) {
            // Update the main collaborate instance, this is not for a group.
            $this->update_collaborate_instance_record($collaborate, $respobj);
        }

        return ($sessionid);
    }

    public function update_attendee($sessionid, $userid, $avatarurl, $displayname, $role) {

        $attendee = new HtmlAttendee($userid, $role);
        $attendee->setDisplayName($displayname, 0, 80);
        $attendee->setAvatarUrl(new \SoapVar('<ns1:avatarUrl><![CDATA['.$avatarurl.']]></ns1:avatarUrl>', XSD_ANYXML));

        $satts = new UpdateHtmlSessionAttendee($sessionid, $attendee);
        $satts->setLocale(current_language());

        $result = $this->UpdateHtmlSessionAttendee($satts);

        if (!$result || !method_exists($result, 'getUrl')) {
            return false;
        }
        $url = $result->getUrl();
        return ($url);
    }

    /**
     * Return a date suitable for the API.
     *
     * NOTE: date('c', $data->timestart) doesn't work with the API as it treates any time date with a + symbol in it as
     * invalid. Therefore, this function expects the date passed in to already be a UTC date WITHOUT an offset.
     * @param int $uts unix time stamp
     * @param boolean $converttoutc - adjust server time to be a UTC time.
     *
     * @return DateTime
     */
    public function api_datetime($uts, $converttoutc = false) {
        if ($converttoutc) {
            $uts = local::servertime_to_utc($uts);
        }
        $dt = new \DateTime(date('Y-m-d H:i:s', $uts), new \DateTimeZone('UTC'));
        $dt->format('Y-m-d\TH:i:s\Z');
        return $dt;
    }

    public function delete_session($sessionid) {

        // API request deletion.
        $this->set_silent(true);

        $params = new RemoveHtmlSession($sessionid);
        try {
            $result = $this->RemoveHtmlSession($params);
        } catch (Exception $e) {
            $result = false;
        }
        if ($result === null) {
            // TODO: Warning - this is a bodge fix! - the wsdl2phpgenerator has set up this class so that it is expecting
            // a Success Response object but we are actually getting back a RemoveSessionSuccessResponse element in the
            // xml and as a result of that we end up with a 'null' object.
            $xml = $this->__getLastResponse();
            if (preg_match('/<success[^>]*>true<\/success>/', $xml)) {
                // Manually create the response object!
                $result = new SuccessResponse(true);
            } else {
                $result = false;
            }
        }

        if (!$result || !$result->getSuccess()) {
            $this->process_error(
                'error:failedtodeletesession', constants::SEV_WARNING
            );
            return false;
        } else {
            return true;
        }
    }

    public function guest_url($sessionid) {
        $param = new BuildHtmlSessionUrl($sessionid);
        $sessionurl = $this->BuildHtmlSessionUrl($param);
        return $sessionurl->getUrl();
    }

    public function get_recordings(stdClass $collaborate, cm_info $cm, $canmoderate = false) {
        $sessionlinks = sessionlink::my_active_links($collaborate, $cm);

        $sessionrecordings = [];

        foreach ($sessionlinks as $sessionlink) {
            if (empty($sessionlink->sessionid)) {
                continue;
            }
            $session = new HtmlSessionRecording();
            $session->setSessionId($sessionlink->sessionid);
            $result = $this->ListHtmlSessionRecording($session);
            $recordings = [];
            if ($result) {
                $respobjs = $result->getHtmlSessionRecordingResponse();
                if (is_array($respobjs) && !empty($respobjs)) {
                    $recordings = $respobjs;
                }
            }
            $sessionrecordings[$sessionlink->sessionid] = $recordings;
        }

        $modelsbysessionid = [];

        $allrecordingmodels = [];

        foreach ($sessionrecordings as $sessionid => $recordings) {

            if (empty($recordings)) {
                continue;
            }

            usort($recordings, function($a, $b) {
                return ($a->getStartTs() > $b->getStartTs());
            });

            // Only segregate by titles if there are multiple sessions per this instance.
            foreach ($recordings as $recording) {

                $recurl = $recording->getRecordingUrl();

                $name = $recording->getDisplayName();
                if (preg_match('/^recording_\d+$/', $name)) {
                    $name = str_replace('recording_', '', get_string('recording', 'collaborate', $name));
                }
                $datetimestart = new \DateTime($recording->getStartTs());
                $datetimestart = $datetimestart->getTimestamp();
                $datetimeend = new \DateTime($recording->getEndTs());
                $datetimeend = $datetimeend->getTimestamp();
                $duration = round($recording->getDurationMillis() / 1000);

                $model = new recording();
                $model->id = $recording->getRecordingId();
                $model->starttime = $datetimestart;
                $model->endtime = $datetimeend;
                $model->duration =$duration;
                $model->name = $name;
                $model->viewurl = ($recurl);

                $allrecordingmodels[$model->id] = $model;

                if (!isset($modelsbysessionid[$sessionid])) {
                    $modelsbysessionid[$sessionid] = [];
                }
                $modelsbysessionid[$sessionid][] = $model;
            }
        }

        $recordingcounts = [];

        if ($canmoderate) {
            $recordingcounthelper = new recording_counter($cm, $allrecordingmodels);
            $recordingcounts = $recordingcounthelper->get_recording_counts();
        }

        foreach ($modelsbysessionid as $sessionid => $models) {
            foreach ($models as $model) {
                if (!empty($recordingcounts[$model->id])) {
                    $model->count = $recordingcounts[$model->id];
                }
            }
        }

        return $modelsbysessionid;
    }

    public function delete_recording($recordingid) {
        $delrec = new RemoveHtmlSessionRecording($recordingid);
        // Note, this is returning 'null' at the moment, so no way to return success boolean.
        $this->RemoveHtmlSessionRecording($delrec);
    }
}
