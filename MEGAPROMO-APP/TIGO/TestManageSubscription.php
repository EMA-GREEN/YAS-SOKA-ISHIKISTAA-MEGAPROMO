<?php

$msisdn = "255656692469";
$type = "UNSUBSCRIPTION";

sendRenewal($msisdn, $type);

function sendRenewal($msisdn, $type) {
    $requestArray = [];
    $requestArray['msisdn'] = $msisdn;
    $requestArray['serviceID'] = "ZALI";
    if ($type === "SUBSCRIPTION") {
        $requestType = "SUBSCRIPTION";
    } else {
        $requestType = "UNSUBSCRIPTION";
    }
    $requestArray['activityType'] = $requestType;
    $jsonRequest = json_encode($requestArray);
    $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($jsonRequest), "Cache-Control: no-cache", "Pragma: no-cache");
    $url = "http://192.168.168.10/TigoRenewalServices/ManageSubscription.php";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
}