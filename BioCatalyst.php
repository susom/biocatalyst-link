<?php
namespace Stanford\BioCatalyst;

use REDCap;
use ExternalModules\ExternalModules;


/**
 * Class BioCatalyst
 *
 * @package Stanford\BioCatalyst
 */
class BioCatalyst extends \ExternalModules\AbstractExternalModule
{

    public $token;

    public $user_rights_to_export = array('data_export_tool', 'reports'); //, 'data_access_groups');
    private $http_code;

    public function __construct()
    {
        parent::__construct();
    }


    public function parseRequest() {

        // Verify Token
        $token = empty($_POST['token']) ? null : $_POST['token'];

        $this->token = $this->getSystemSetting('biocatalyst-api-token');

        $this->log("t". $token, "o". $this->token);
        if(empty($token) || $token != $this->token) {
            return array(
                "error"=>"Invalid API Token"
            );
        }

        // Verify IP Filter
        $ip_filter = $this->getSystemSetting('ip');

        //$this->log($ip_filter);
        //$this->log(empty($ip_filter));
        //$this->log(empty($ip_filter[0]));

        if (!empty($ip_filter) && !empty($ip_filter[0])) {
            $isValid = false;
            foreach ($ip_filter as $filter) {
                if (self::ipCIDRCheck($filter)) {
                    $isValid = true;
                    break;
                }
            }
            if (!$isValid) return array(
                "error"=> "invalid source IP"
            );
        }

        $request = empty($_POST['request']) ? null : $_POST['request'];
        if (!in_array($request, array("users", "reports"))) {
            return array(
                "error" => "invalid request"
            );
        }

        $user = empty($_POST['user']) ? null : strtolower( $_POST['user'] );
        if (empty($user)) {
            return array(
                "error" => "missing required user"
            );
        }

        $project_id = empty($_POST['project_id']) ? "" : intval($_POST['project_id']);
        if ($request == "reports" && empty($project_id)) {
            return array(
                "error" => "project_id required"
            );
        }

        $report_id = empty($_POST['report_id']) ? "" : intval($_POST['report_id']);
        $this->log("Request $request / User $user / Project_id $project_id / report_id $report_id");

        // Keep timestamp of start time
        $tsstart = microtime(true);

        if ($request == "users") {
            // Get all projects and the user's rights in those projects for reports
            $result = $this->getProjectUserRights($user);
        } elseif ($request == "reports") {
            if (empty($report_id)) {
                $result = $this->getProjectReports($user, $project_id);
            } else {
                $result = $this->getReport($user, $project_id, $report_id);
            }
            if ($result == false) {
                http_response_code($this->http_code);
                $this->log("Sending back http_code $this->http_code");
            }
        }

        $this->log($result, "RESULT");
        $duration = round(microtime(true) - $tsstart, 1);
        $this->log("Request took $duration microseconds to complete for user $user");

        return $result;
    }

    /**
     * Get all projects that are enabled for BioCatalyst AND the specified user and return project and user's export rights.
     * @param $user
     */
    function getProjectUserRights($user) {
        $projects = $this->getEnabledProjects();
        //$this->log($projects, "PROJECTS");

        $results = array();
        foreach ($projects as $project) {
            $project_id = $project['project_id'];
            $user_rights = \UserRights::getPrivileges($project_id,  $user);

            if (isset($user_rights[$project_id][$user])) {
                // User has rights - lets filter list to those we want
                $rights = array_intersect_key($user_rights[$project_id][$user], array_flip($this->user_rights_to_export));
                $proj_rights[] = array(
                        "project_id" => $project_id,
                        "rights" => $rights
                        );
                }
                $results = array(
                        "user" => $user,
                        "projects" => $proj_rights
            );
        }
        return json_encode($results);
    }


    /**
     * Return array of report_id, report_name, report_fields or other data?
     * @param $user
     * @param $project_id
     */
    function getProjectReports($user,$project_id) {
        // Ugly hack of REDCap source functions but ensures that export is compliant with user's permissions
        global $Proj;
        $Proj = new \Project($project_id);

        $result = $this->getProjectUserRights($user);
        $result_proj = json_decode($result,true);
        $access = false;

        foreach($result_proj["projects"] as $proj) {
            if ($project_id == $proj["project_id"]) {
                //$this->log("User rights " . implode(',',$proj['rights']) . " for project_id $project_id for user $user");
                if ($proj["rights"]["data_export_tool"] == '1' && $proj["rights"]["reports"] == '1') {
                    $access = true;
                }
                break;
            }
        }

        if ($access == true) {
            $reports = array();
            $sql = "select rr.report_id, rr.title
                    from redcap_external_modules rem
                    left join redcap_external_module_settings rems on rem.external_module_id = rems.external_module_id
                    left join redcap_reports rr on rems.project_id = rr.project_id
                    where rem.directory_prefix = 'biocatalyst_link'
                    and rems.key = 'biocatalyst-enabled'
                    and rems.value = 'true'";
            $q = $this->query($sql);
            while ($row = db_fetch_assoc($q)) {
                $reports[] = $row;
            }
            $response = array("project_id" => $project_id,
                              "reports" => $reports);
            return json_encode($response);
        } else {
            $this->http_code = 403;
            $this->log("NOT AUTHORIZED: User $user trying to get report list for project $project_id");
            return false;
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
    function getReport($user, $project_id, $report_id) {
        // Ugly hack of REDCap source functions but ensures that export is compliant with user's permissions
        global $Proj;
        $Proj = new \Project($project_id);
//        define(USERID, $user);

        // The above hack for setting user doesn't seem to be working so check that this user
        // has the proper rights for retrieving this report
        $result = $this->getProjectUserRights($user);
        $result_proj = json_decode($result,true);
        $access = false;
        $valid_report = false;

        foreach($result_proj["projects"] as $proj) {
            if ($project_id == $proj["project_id"]) {
                //$this->log("User rights " . implode(',',$proj['rights']) . " for project_id $project_id for user $user");
                if ($proj["rights"]["data_export_tool"] == '1' && $proj["rights"]["reports"] == '1') {
                    $access = true;
                }
                break;
            }
        }

        // This user has the correct rights now check to make sure the given report_id belongs to this project_id
        if ($access == true) {
            $reports =  $this->getProjectReports($user,$project_id);
            $proj_reports = json_decode($reports, true);
            foreach($proj_reports["reports"] as $report) {
                if ($report["report_id"] == $report_id) {
                    $valid_report = true;
                    break;
                }
            }
        }

        if ($access == true && $valid_report == true) {
            $this->log("This is user $user retrieving report $report_id for project $project_id");
            $report =  REDCap::getReport($report_id, 'json');
            return $report;
        } else if ($access == false) {
            $this->log("NOT AUTHORIZED: User $user trying to get report $report_id for project $project_id");
            $this->http_code = 403;
            return false;
        } else if ($valid_report == false) {
            $this->log("THIS REPORT DOES NOT BELONG TO THIS PROJECT: User $user trying to get report $report_id for project $project_id");
            $this->http_code = 404;
            return false;
        } else {
            $this->log("UNKNOWN REPORT ERROR: User $user trying to get report $report_id for project $project_id");
            $this->http_code = 404;
            return false;
        }
    }


    /**
     * Return array of projects with all project metadata where biocatalyst is enabled
     */
    function getEnabledProjects() {
        $projects = array();
        $sql = "select rp.project_id, rp.project_name
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


    // Checks if the IP is valid given an IP or CIDR range
    // e.g. 192.168.123.1 = 192.168.123.1/30
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
    // Log Wrapper
    public static function log() {
        if (class_exists("\Plugin")) call_user_func_array("\Plugin::log", func_get_args());
    }
*/
    function log() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->log($this->PREFIX, func_get_args(), "INFO");
    }

    function debug() {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || $this->getProjectSetting('enable-project-debug-logging')) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->log($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function error() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->log($this->PREFIX, func_get_args(), "ERROR");
    }

}