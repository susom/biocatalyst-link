<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module */

use REDCap;
use UserRights;

/**
 * Class BioCatalyst
 *
 * @package Stanford\BioCatalyst
 */
class BioCatalyst extends \ExternalModules\AbstractExternalModule
{
    public $token;

    public $user_rights_to_export = array('data_export_tool', 'reports'); //, 'data_access_groups');
    private $http_code = null;
    private $error_msg = null;

    //public function __construct()
    //{
    //    parent::__construct();
    //}

    public function parseRequest() {

        // In case the request didn't come over directly in the POST
        if (empty($_POST)) {
            // Retrieve request from user
            $_POST = json_decode(file_get_contents('php://input'), true);
        }

        // Verify Token
        $token = empty($_POST['token']) ? null : $_POST['token'];
        $this->token = $this->getSystemSetting('biocatalyst-api-token');

        $this->emLog("t". $token, "o". $this->token);
        if(empty($token) || $token != $this->token) {
            return $this->packageError("Invalid API Token");
        }

        // Verify IP Filter
        $ip_filter = $this->getSystemSetting('ip');

        if (!empty($ip_filter) && !empty($ip_filter[0])) {
            $isValid = false;
            foreach ($ip_filter as $filter) {
                if (self::ipCIDRCheck($filter)) {
                    $isValid = true;
                    break;
                }
            }
            if (!$isValid) {
                 return $this->packageError("Invalid source IP");
            }
        }

        $request = empty($_POST['request']) ? null : $_POST['request'];
        if (!in_array($request, array("users", "reports"))) {
            return $this->packageError("Invalid request");
        }

        $users = empty($_POST['user']) ? null : strtolower( $_POST['user'] );
        if (empty($users)) {
            return $this->packageError("Missing required user");
        }

        $project_id = empty($_POST['project_id']) ? "" : intval($_POST['project_id']);
        if ($request == "reports" && empty($project_id)) {
            return $this->packageError("Project_id required");
        }

        $report_id = empty($_POST['report_id']) ? "" : intval($_POST['report_id']);
        $this->emLog("Request $request / Users $users / Project_id $project_id / report_id $report_id");

        // Keep timestamp of start time
        $tsstart = microtime(true);

        $result = array();
        if ($request == "users") {
            // There may be a comma separated list of users. Split the list and loop over each user
            $user_list = array_map('trim', explode(',', $users));
            $complete_list = array();
            foreach ($user_list as $user) {
                $this->emLog("user: " . $user);
                // Get all projects and the user's rights in those projects for reports
                $complete_list[] = $this->getProjectUserRights($user);
            }
            $result = json_encode($complete_list);
        } elseif ($request == "reports") {
            $user = $users; // assume there is only one user
            if (empty($report_id)) {
                $result = $this->getProjectReports($project_id, $user);
            } else {
                $result = $this->getReport($project_id, $user, $report_id);
            }
        }

        $duration = round((microtime(true) - $tsstart) * 1000, 1);
        $this->emLog(array(
            "duration" => $duration,
            "user" => $users
        ));

        if ($result == false) {
            return $this->packageError($this->error_msg);
        } else {
            header("Context-type: application/json");
            return $result;
        }
    }

    /**
     * Get all projects that are enabled for BioCatalyst AND
     * the specified user and return project and user's export rights.
     *
     * @param $user
     * @return array
     */
    function getProjectUserRights($user) {
        $projects = $this->getEnabledProjects();
        $this->emLog("Enabled projects: " . $projects . ", user " . $user);

        $proj_rights = array();
        foreach ($projects as $project) {
            $project_id = $project['project_id'];
            $project_title = $project['app_title'];
            $user_rights = UserRights::getPrivileges($project_id, $user);

            if (isset($user_rights[$project_id][$user])) {
                // User has rights - lets filter list to those we want
                $rights = array_intersect_key($user_rights[$project_id][$user], array_flip($this->user_rights_to_export));
                $proj_rights[] = array(
                    "project_id" => $project_id,
                    "project_title" => $project_title,
                    "rights" => $rights
                );
            }
        }

        $results = array(
            "user" => $user,
            "projects" => $proj_rights
        );

        return $results;
    }


    /**
     * Return array of report_id, report_name, report_fields or other data?
     * @param $user
     * @param $project_id
     * @return bool|string
     */
    function getProjectReports($project_id, $user) {

        // Retrieve user rights
        $user_rights = UserRights::getPrivileges($project_id,  $user);

        // Verify permissions
        if ($user_rights[$project_id][$user]["data_export_tool"] == '0' || $user_rights[$project_id][$user]["reports"] != '1') {
            $this->http_code = 403;
            $this->error_msg = "NOT AUTHORIZED: User $user trying to get report list for project $project_id";
            return false;
        } else {

            // If this person has export and reports rights, find the report ids for this project
            $reports = array();

            // Get all reports for the specified biocatalyst project
            $sql = "select rr.report_id, rr.title
                    from redcap_external_modules rem
                    left join redcap_external_module_settings rems on rem.external_module_id = rems.external_module_id
                    left join redcap_reports rr on rems.project_id = rr.project_id
                    where rem.directory_prefix = 'biocatalyst_link'
                    and rems.key = 'biocatalyst-enabled'
                    and rems.value = 'true'
                    and rr.project_id = " . intval($project_id);
            $q = $this->query($sql);
            while ($row = db_fetch_assoc($q)) {
                $reports[] = $row;
            }

            // Send back the list of report_ids that this person has access to.
            $response = array("project_id" => $project_id,
                              "reports" => $reports);
            return json_encode($response);
        }
    }


    /**
     * Return the ACTUAL report data
     * @param $user
     * @param $project_id
     * @param $report_id
     * @return array|bool
     * @throws
     */
    function getReport($project_id, $user, $report_id) {

        // Get user rights for this project for this user
        $user_rights = UserRights::getPrivileges($project_id,  $user);

        // Make sure this user has at least export and report privileges
        if ($user_rights[$project_id][$user]["data_export_tool"] == '0' || $user_rights[$project_id][$user]["reports"] != '1') {
            $this->error_msg = "NOT AUTHORIZED: User $user trying to get report $report_id for project $project_id";
            $this->http_code = 403;
            $report = false;
        } else {

            // Check to make sure this report belongs to this project
            $valid_report = $this->checkReportInProject($project_id, $report_id);
            if ($valid_report == false) {
                return false;
            }

            $url = $this->getUrl('BioCatalystReports.php', true, true) . "&pid=$project_id";
            $this->emLog("Getting report url:" . $url);
            $header = array('Content-Type: application/json');

            $body = array("report_id"   => $report_id,
                          "token"       => $this->token);

            //$report = http_post($url, $body, $timeout=10, 'application/json', "", null);
            $report = $this->http_request("POST", $url, $header, json_encode($body));
            if ($report == false) {
                $this->error_msg = "COULD NOT RETRIEVE REPORT: User $user trying to get report $report_id for project $project_id";
                $this->http_code = 403;
            }
        }
        return $report;
    }


    /**
     * Check to make sure this report belongs to the specified project before retrieving report
     * @return bool
     */
    function checkReportInProject ($project_id, $report_id)
    {
        // Make sure this report_id belongs to this project_id otherwise we don't get
        // a nice message returned
        $sql = "select count(1) from redcap_reports
                  where project_id = " . intval($project_id) . "
                  and report_id = " . intval($report_id);

        $q = $this->query($sql);
        $num_reports = db_fetch_assoc($q);
        if ($num_reports["count(1)"] == 1) {
            return true;
        } else {
            $this->error_msg = "Report $report_id not valid for Project $project_id";
            $this->http_code = 404;
            return false;
        }
    }

    /**
     * Return array of projects with all project metadata where biocatalyst is enabled
     * regardless of user permissions
     *
     * @return array
     */
    function getEnabledProjects() {
        $projects = array();
        $sql = "select rp.project_id, rp.app_title
          from redcap_external_modules rem
          left join redcap_external_module_settings rems on rem.external_module_id = rems.external_module_id
          left join redcap_projects rp on rems.project_id = rp.project_id
          where rem.directory_prefix = 'biocatalyst_link'
          and rems.key = 'biocatalyst-enabled'
          and rems.value = 'true'";
        $q = $this->query($sql);
        while($row = db_fetch_assoc($q)){
            $projects[] = $row;
        }
        return $projects;
    }


    /**
     * Utility function to verify IP is from valid range if specified
     *
     * e.g. 192.168.123.1 = 192.168.123.1/30
     * @param $CIDR
     * @return bool
     */
    public static function ipCIDRCheck ($CIDR) {
        $ip = trim($_SERVER['REMOTE_ADDR']);

        // Convert IPV6 localhost into IPV4
        if ($ip == "::1") $ip = "127.0.0.1";

        if(strpos($CIDR, "/") === false) $CIDR .= "/32";
        list ($net, $mask) = explode("/", $CIDR);
        $ip_net  = ip2long($net);
        $ip_mask = ~((1 << (32 - $mask)) - 1);
        $ip_ip = ip2long($ip);
        $ip_ip_net = $ip_ip & $ip_mask;
        return ($ip_ip_net == $ip_net);
    }


    /*
     * This function gets called when an error occurs and we need to do cleanup
     * Set http_code to indicate an error.  404 will be used as a default unless another one is specified
     * Log the error and return
     */
    function packageError($errorString) {
        $jsonString = json_encode(array("error" => $errorString));
        if (is_null($this->http_code)) {
            http_response_code(404);
        } else {
            http_response_code($this->http_code);
        }

        $this->emError($errorString);
        return $jsonString;
    }



    function emLog() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug() {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || $this->getProjectSetting('enable-project-debug-logging')) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }


    function http_request($type, $url, $header, $body=null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($type == "GET") {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else if ($type == "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
        }
        if (!is_null($body) and !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (!is_null($header) and !empty($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }

        $this->emLog("This is the body: " . $body);
        $this->emLog("This is the header: " . $header);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $this->emLog("Curl returned output: " . $response);
        $this->emLog( "Curl returned error: " . $error);
        $this->emLog("Curl info: " . json_encode($info));

        if (!empty($error) or ($info["http_code"] !== 200)) {
            $this->emLog("Curl returned output: " . $response);
            $this->emLog( "Curl returned error: " . $error);
            $this->emLog("Curl info: " . json_encode($info));
            return false;
        } else {
            return $response;
        }
    }

}