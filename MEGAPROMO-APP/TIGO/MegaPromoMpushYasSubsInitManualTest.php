<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once "TigoMegaPromoDatabaseServices.php";
require_once "MegaPromoLib.php";

use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;

$msisdn = "255656692469";

echo "Sending mpush YAS subscription activation test to {$msisdn}...\n";

$lib = new MegaPromoLibrary\MegaPromoLib($msisdn);
$responseData = $lib->sendMpushYasSubscriptionActivation();

echo "isResponse: " . (isset($responseData['isResponse']) && $responseData['isResponse'] ? "TRUE" : "FALSE") . "\n";
if (isset($responseData['requestId'])) {
    echo "requestId: " . $responseData['requestId'] . "\n";
}
echo "response:\n";
echo (isset($responseData['response']) ? $responseData['response'] : "NO RESPONSE") . "\n";
