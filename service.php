<?php
namespace Stanford\BioCatalyst;
/** @var \Stanford\BioCatalyst\BioCatalyst $module */

echo $module->emLog($_REQUEST, "Incoming Request");

$result = $module->parseRequest(); //"foo";


header("Content-Type: application/json");
echo $result;

// echo json_encode($result);

// define(USERID, "andy123");
//
// echo defined("USERID") ? "DEFINED AS " . USERID : "NOT DFINED";
//
