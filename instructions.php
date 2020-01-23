<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module */

/**

    Copyright 2020 Stanford University

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

        http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.

 **/


require APP_PATH_DOCROOT . "ControlCenter/header.php";

if (!SUPER_USER) {
    ?>
    <div class="jumbotron text-center">
        <h3><span class="glyphicon glyphicon-exclamation-sign"></span> This utility is only available for all projects.</h3>
    </div>
    <?php
    exit();
}

?>

<h3>BioCatalyst Link API Instructions</h3>
    <p>
        Enable this External Module for each project you are interested in making available to query from the BioCatalyst API.
        Once this EM is enabled, configure the module by checking the 'Enable Stanford Biocatalyst to access reports in this project'
        checkbox in the 'Configure' setup.
    </p>
    <p>
    </p>
<br>

<h4>Endpoint</h4>
<p>
    You must send a 'POST' request to the following url to initiate a request:
</p>
<pre>
<?php echo $module->getUrl('service.php', true, true) ?>
</pre>
<br>

<h4>API Example</h4>
<?php
if (empty($module->getSystemSetting("biocatalyst-api-token"))) {
    echo "<div class='alert alert-danger'>No API token has been defined.  This service will not work until you enter a shared secret in the External Modules configuration page.</div>";
} else {
    ?>
    <p>
        The following parameters are valid in the body of the POST
    </p>
    <pre>
    token:       <?php echo $module->getSystemSetting("biocatalyst-api-token"); ?> (this token is a shared secret and can only be reset by Super Users)
    request:     users | reports | columns
    user:        SUNETID (e.g. jdoe)
    project_id:  (optional) REDCap Project ID (e.g. 12345)
    report_id:   (optional) REDCap Report ID (e.g. 1234)

    See the gitlab README.md file for more detailed API instructions.

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
