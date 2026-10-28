<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once "config.php";
require_once "SokaLib.php";
require_once "SokaServices.php";

$msisdn = "255656692469";

echo "Sending mpush YAS subscription activation test to {$msisdn}...\n";

$sokaServicesObj = new SokaServices("consumer");
$userAccessToken = $sokaServicesObj->userAccessToken();
$sokaLib = new SokaLib($msisdn, $userAccessToken, 0, 0);
$responseData = $sokaLib->sendMpushYasSubscriptionActivation();
$sokaServicesObj->closeDB();

echo "isResponse: " . (isset($responseData['isResponse']) && $responseData['isResponse'] ? "TRUE" : "FALSE") . "\n";
if (isset($responseData['requestId'])) {
    echo "requestId: " . $responseData['requestId'] . "\n";
}
echo "response:\n";
echo (isset($responseData['response']) ? $responseData['response'] : "NO RESPONSE") . "\n";
