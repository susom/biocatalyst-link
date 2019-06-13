<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module */

require_once("emLoggerTrait.php");

use REDCap;
use UserRights;
use ExternalModules\AbstractExternalModule;

/**
 * Class BioCatalyst
 *
 * If raw data (not labels) is desired, the POST needs to have an entry 'raw_data' set to 1 (true).
 *
 * @package Stanford\BioCatalyst
 */
class BioCatalyst extends AbstractExternalModule
{
    use emLoggerTrait;


    // System settings
    private $api_token;

    // Request Parameters
    public $token, $project_id, $request, $users, $report_id, $raw_data;

    static $user_rights_to_export = array('data_export_tool', 'reports'); //, 'data_access_groups');

    // Performance
    private $ts_start;


    /**
     * This function wraps the handling of all API requests
     *
     * @return array|bool|false|string
     */
    public function parseRequest()
    {
        // LOG START TIME
        $this->ts_start = microtime(true);

        // CONVERT RAW POST TO PHP POST
        if (empty($_POST)) $_POST = json_decode(file_get_contents('php://input'), true);

        // FILTER BY IP
        $this->applyIpFilter();

        // PARSE POST PARAMETERS
        $this->token      = empty($_POST['token'])      ? null : $_POST['token'];
        $this->request    = empty($_POST['request'])    ? null : $_POST['request'];
        $this->users      = empty($_POST['user'])       ? null : array_filter(array_map('trim', explode(',', strtolower($_POST['user']))));
        $this->project_id = empty($_POST['project_id']) ? ""   : intval($_POST['project_id']);
        $this->report_id  = empty($_POST['report_id'])  ? ""   : intval($_POST['report_id']);

        // Check to see if raw data is desired: ['raw_data'] = 1
        // Default is to return field labels for radios, checkboxes, dropdowns, etc.
        $this->raw_data   = empty($_POST['raw_data']) ? false : (intval($_POST['raw_data']) === 1 ? true : false);

        // VERIFY TOKEN
        $this->api_token = $this->getSystemSetting('biocatalyst-api-token');
        if (empty($this->token) || $this->token != $this->api_token) $this->returnError("Invalid API Token");

        $this->performRequest();
    }


    /**
     * Perform the actual request
     * @return array|bool|false|string
     */
    public function performRequest() {
        $this->emDebug("performing Request $this->request", $this->users, $this->project_id, $this->report_id);

        $result = array();

        switch($this->request) {
            case "users":
                // Validate users
                if (empty($this->users)) $this->returnError("Missing required user");
                foreach ($this->users as $user) {
                    // Get all projects and the user's rights in those projects for reports
                    $result[] = $this->getProjectUserRights($user);
                }
                break;

            case "reports":
                // Validate
                if (empty($this->users)) $this->returnError("Missing required user");
                if (count($this->users) > 1) $this->returnError("Only one user at a time");
                if (empty($this->project_id)) $this->returnError("Missing required project_id");

                $user = $this->users[0];
                if (empty($this->report_id)) {
                    // GET ALL REPORTS FOR THE USER
                    $result = $this->getProjectReports($this->project_id, $user);
                } else {
                    // GET SPECIFIC REPORT FOR USER
                    $result = $this->getReport($this->project_id, $user, $this->report_id);
                }
                break;

            case "columns":
                // Validate
                if (empty($this->users)) $this->returnError("Missing required user");
                if (count($this->users) > 1) $this->returnError("Only one user at a time");
                $user = $this->users[0];

                if (empty($this->project_id)) $this->returnError("Missing required project_id");
                if (empty($this->report_id)) $this->returnError("Missing required report id");
                $result = $this->getReportColumns($this->project_id, $user, $this->report_id);
               break;


            default:
                $this->returnError("Invalid Request");
                break;
        }

        $duration = round((microtime(true) - $this->ts_start) * 1000, 1);

        $this->emDebug("Request Duration", $duration);

        // Output Results
        header("Content-type: application/json");
        echo json_encode($result);
    }


    /**
     * Apply the IP filter if set
     */
    function applyIpFilter() {

        $ip_addr = trim($_SERVER['REMOTE_ADDR']);
        $this->emDebug("Biocatalyst Report API - Incoming IP address: " . $ip_addr);

        // APPLY IP FILTER
        $ip_filter_list = $this->getSystemSetting('ip');
        $ip_filter = explode(',', $ip_filter_list[0]);
        if (!empty($ip_filter_list) && !empty($ip_filter[0]) && empty($_POST['magic_skip_cidr'])) {
            $isValid = false;
            foreach ($ip_filter as $filter) {
                if (self::ipCIDRCheck($filter, $ip_addr)) {
                    $isValid = true;
                    break;
                }
            }
            // Exit - invalid IP
            if (!$isValid) {

                // Send email to designated user if IP is invalid
                $emailTo = $this->getSystemSetting('alert-email');
                if (!empty($emailTo)) {
                    $emailFrom = "noreply@stanford.edu";
                    $subject = "Unauthorized IP trying to access Biocatalyst Reports";
                    $body = "IP address $ip_addr is trying to access Biocatalyst Reports and is not in the approved IP range.";
                    $status = REDCap::email($emailTo, $emailFrom, $subject, $body);
                }

                // Return error
                $this->emError($subject, $body);
                $this->returnError("Invalid source IP");
            }
        }
    }


    /**
     * Return an error message and exit
     * @param string    $error_message
     * @param int       $http_code
     */
    function returnError($error_message, $http_code=404) {
        header("Content-type: application/json");
        http_response_code($http_code);
        echo json_encode(["error" => $error_message]);

        $this->emError($error_message);
        exit();
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
        $this->emDebug("Enabled projects: " . $projects . ", user " . $user);

        $proj_rights = array();
        foreach ($projects as $project) {
            $project_id = $project['project_id'];
            $project_title = $project['app_title'];
            $user_rights = UserRights::getPrivileges($project_id, $user);

            if (isset($user_rights[$project_id][$user])) {
                // User has rights - lets filter list to those we want
                $rights = array_intersect_key($user_rights[$project_id][$user], array_flip(self::$user_rights_to_export));
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
     * Verify user rights for data export
     * @param $project_id
     * @param $user
     * @return bool
     */
    function verifyExportRights($project_id, $user) {
        // Retrieve user rights
        $user_rights = UserRights::getPrivileges($project_id,  $user);

        // Error if insufficient permissions
        if ($user_rights[$project_id][$user]["data_export_tool"] == '0' || $user_rights[$project_id][$user]["reports"] != '1') {
            return false;
        }
        return true;
    }



    /**
     * Return array of report_id, report_name, report_fields or other data?
     * @param $user
     * @param $project_id
     * @return array
     */
    function getProjectReports($project_id, $user) {

        // Retrieve user rights
        if (! $this->verifyExportRights($project_id, $user) ) $this->returnError("NOT AUTHORIZED: User $user trying to get report list for project $project_id");

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
        $results = array("project_id" => $project_id,
                          "reports" => $reports);
        return $results;
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

        // Verify permissions
        if (! $this->verifyExportRights($project_id, $user) ) $this->returnError("NOT AUTHORIZED: User $user trying to get report $report_id for project $project_id");

        // Check to make sure this report belongs to this project
        if (! $this->checkReportInProject($project_id, $report_id) ) $this->returnError("NOT AUTHORIZED: Report $report_id is not part of project $project_id");

        if (isset($_GET['pid']) && $_GET['pid'] == $project_id) {
            // We are in project context so we can actually pull the report
            // This is actually a recursive call to this same php page from the server
            if ($this->raw_data) {
                $report = REDCap::getReport($this->report_id, 'json', false);
            } else {
                $report = REDCap::getReport($this->report_id, 'json', true);
            }
            //$report = json_decode($report,true);
        } else {
            // Because exporting a report must be done in project context, we are using a callback to another page to accomplish this
            $url = $this->getUrl('service.php', true, true) . "&pid=$project_id";

            $body = $_POST;
            $body['magic_skip_cidr'] = true;
            $this->emDebug("Resending to myself: " . json_encode($body));
            $report = http_post($url, $body, $timeout=10, 'application/json', "", null);
            if ($report == false) $this->returnError("COULD NOT RETRIEVE REPORT: User $user trying to get report $report_id for project $project_id");
        }
        //$this->emDebug("Got Report",$report);
        $report = json_decode($report,true);
        return $report;
    }


    /**
     * Gets report metadata or returns
     * @param $project_id
     * @param $user
     * @param $report_id
     * @return array
     */
    function getReportColumns($project_id, $user, $report_id) {

        // Verify permissions
        if (! $this->verifyExportRights($project_id, $user) ) $this->returnError("NOT AUTHORIZED: User $user trying to get report columns for report $report_id for project $project_id");

        // Check to make sure this report belongs to this project
        if (! $this->checkReportInProject($project_id, $report_id) ) $this->returnError("NOT AUTHORIZED: Report $report_id is not part of project $project_id");


        // GET COLUMNS FROM REPORT
        $sql = "
            select
               rm.form_name,
               rrf.field_name,
               rrf.field_order,
               rm.element_label as field_label,
               rm.element_type as field_type,
               rm.element_enum as field_options
            from
                 redcap_reports_fields rrf
            join redcap_reports rr on rr.report_id = rrf.report_id
            join redcap_metadata rm on rm.field_name = rrf.field_name and rm.project_id = rr.project_id
            where rrf.report_id = " . intval($report_id) . "
            and rrf.limiter_group_operator is null
            order by rrf.field_order";
        $q = db_query($sql);

        $columns = array();
        while ($row = db_fetch_assoc($q)) $columns[] = $row;

        if (empty($columns) || $columns == false) $this->returnError("COULD NOT RETRIEVE REPORT COLUMNS: User $user trying to get report columns for report $report_id for project $project_id");

        $result = array(
            'report_id' => $report_id,
            'project_id' => $project_id,
            'user' => $user,
            'columns' => $columns
        );

        return $result;
    }





    /**
     * Check to make sure this report belongs to the specified project before retrieving report
     * @return bool
     */
    function checkReportInProject($project_id, $report_id)
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
        while($row = db_fetch_assoc($q)) $projects[] = $row;

        return $projects;
    }


    /**
     * Utility function to verify IP is from valid range if specified
     *
     * e.g. 192.168.123.1 = 192.168.123.1/30
     * @param $CIDR
     * @return bool
     */
    public static function ipCIDRCheck ($CIDR, $ip) {

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

}