<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');

// $msisdn = "255657172337";
$msisdn = "255656692469";

$boom_customer_status = "";

$boomCustomerInfo = getBoomCustomerInfo($msisdn); 

if (!empty($boomCustomerInfo) && isset($boomCustomerInfo["ResponseCode"])) {
    $response_code = $boomCustomerInfo["ResponseCode"];
    if ($response_code == "BOOM-10-1002-S") {
        $boom_customer_status = "BOOM_CUSTOMER";
    } else if ($response_code == "BOOM-10-10050-E") {
        $boom_customer_status = "NONE_BOOM_CUSTOMER";
    } else {
        $boom_customer_status = "UNKNOWN";
    }
} else {
    $boom_customer_status = "UNKNOWN";
}

echo $boom_customer_status;

function getBoomCustomerInfo($msisdn) {
    $boom_url  = "http://10.222.15.34:8065/api/Boom/CheckBoomID";
    $username = "greentelecom";
    $password = "green2025";
    $access_token = getBoomAccessToken($username, $password);
    $reference_id = date("YmdHis")."".$msisdn;

    $payload_arr = [
        "MSISDN" => $msisdn,
        "ReferenceID" => $reference_id
    ];

    $payload = json_encode($payload_arr);

    $headers = array("Method:POST", "Content-Type: application/json", "Cache-Control: no-cache", "Authorization: Bearer ".$access_token, "Username: ".$username);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $boom_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    $response_data = json_decode($response, true);

    $boomCustomerInfo = "";

    if (json_last_error() === JSON_ERROR_NONE && isset($response_data['ResponseCode'])) {
        $boomCustomerInfo = $response_data;
    }

    return $boomCustomerInfo;

}

function getBoomAccessToken($username, $password) {
    $accessToken = "";
    $token_url  = "http://10.222.15.34:8065/api/token/token";

    $headers = array("Method:POST", "Content-Type: application/json", "Cache-Control: no-cache");

    $payload_arr = [
        "UserName" => $username,
        "Password" => $password
    ];

    $payload = json_encode($payload_arr);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch); 

    $data = json_decode($response, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($data['access_token'])) {
        $accessToken = $data['access_token'];
    } 

    return $accessToken;  
}
