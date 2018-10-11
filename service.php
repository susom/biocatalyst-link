<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module */

echo $module->emDebug("Incoming Request", $_REQUEST, file_get_contents("php://input"));

$module->parseRequest();
