<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module **/


use REDCap;
use ExternalModules\ExternalModules;

/**
 * BioCatalystReports
 *
 * @package Stanford\BioCatalyst
 */

// In case the request didn't come over directly in the POST
if (empty($_POST)) {
    // Retrieve request from user
    $_POST = json_decode(file_get_contents('php://input'), true);
}

// Verify Token
$token = empty($_POST['token']) ? null : $_POST['token'];
$report_id = empty($_POST['report_id']) ? "" : intval($_POST['report_id']);

$bio = new BioCatalyst();

$check_token = $module->getSystemSetting('biocatalyst-api-token');
if ($token <> $check_token) {
    $error = array("error" => "Invalid token");
    return json_encode($error);
}

// Keep timestamp of start time
$tsstart = microtime(true);

// Retrieve the report
$result =  REDCap::getReport($report_id, 'json');

$duration = round((microtime(true) - $tsstart) * 1000, 1);
$bio->log("Report retrieval time: " . json_encode(array(
    "duration" => $duration,
    "report_id" => $report_id))
);

if ($result == false) {
    $bio->log("Error returned from REDCap::getReport: " . $result);
    $error = array("error" => "$result");
    $result = json_encode($error);
}

header("Context-type: application/json");
print $result;

?>

