<?php
namespace Stanford\EmailRelay;
/** @var \Stanford\EmailRelay\EmailRelay $module */

require APP_PATH_DOCROOT . "ControlCenter/header.php";


if (!SUPER_USER) {
    ?>
    <div class="jumbotron text-center">
        <h3><span class="glyphicon glyphicon-exclamation-sign"></span> This utility is only available for REDCap Administrators</h3>
    </div>
    <?php
    exit();
}

?>

<h3>BioCatalyst Link API Instructions</h3>
    <p>
    This module allows you to create a system-specific API url.
        TODO
    </p>
    <p>
    </p>
<br>

<h4>Endpoint</h4>
<p>
    You must send a 'POST' request to the following url to initiate an email:
</p>
<pre>
<?php echo $module->getUrl('service.php', true, true) ?>
</pre>
<br>

<h4>API Example</h4>
<?php
if (empty($module->token)) {
    echo "<div class='alert alert-danger'>No API token has been defined.  This service will not work until you enter a shared secret in the External Modules configuration page.</div>";
} else {
    ?>
    <p>
        The following parameters are valid in the body of the POST
    </p>
    <pre>
    token:       <?php echo $module->token; ?> (this token is a shared secret and can only be reset by Super Users)
    request:     users | projects | reports
    user:        SUNETID (e.g. jdoe)
    project_id:  (optional) REDCap Project ID (e.g. 12345)
    report_id:   (optional) REDCap Report ID (e.g. 1234)

    If projects or reports are requested without a corresponding project_id or report_id a list of all projects/reports will be returned.
    </pre>
    <br>

    <?php
}
?>


<br>
<h4>IP Filters</h4>
<?php
    $ip_filters = $module->getSystemSetting('ip');
    if (empty($ip_filters)) {
        echo "<div class='alert alert-danger'>No IP Filters are defined.  This is strongly suggested for improved security.</div>";
    } else {
        echo "<pre>" . implode("\n", $ip_filters) . "</pre>";
    }
?>
