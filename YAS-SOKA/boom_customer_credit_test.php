<?php
error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once "config.php";
require_once "SokaLib.php";
require_once "SokaServices.php";

$msisdn = "255656692469";

processMessage($msisdn);

function processMessage($msisdn){
	$sokaServicesObj = new SokaServices("consumer");
	$userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, 0, 0);

	$subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);

	if(!empty($subDetails)){
		$subscriberUpdate = [];
        $subscriberUpdate['BOOM_CREDIT'] = 1; //offer 1 free credit
        $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberUpdate);

        //notify subscriber on offered credit
        $boom_credit_notify_sms = "Ndugu mteja, Umezawadiwa nafasi 1 ya kujibu swali la SOKA buree. Itumie fursa hii kujibu na uingie kwenye Droo ya sehemu ya Tsh 10,000,000 sasa.";
        $notify_sms_response = $sokaLib->sendSMS(0, 0, "BOOM_CREDIT_NOTIFY", $boom_credit_notify_sms);
        updateSMSStatus($sokaServicesObj, $notify_sms_response, $msisdn);
	}

	$sokaServicesObj->closeDB();
}

function updateSMSStatus($sokaServicesObj, $smsstatus = [], $msisdn){
    $smsID = $smsstatus["smsID"];
    $responseCode = json_decode($smsstatus["response"], true);
    $responseReceived = $smsstatus["response"];
    if (isset($responseCode["responseParam"]["status"]) && $responseCode["responseParam"]["status"] === 0) {
        $msgStatus = "SUCCESS";
        $return = true;
    } else {
        if (isset($responseCode["responseParam"]["status"])) {
            $msgStatus = $responseCode["responseParam"]["status"];
        } else {
            $msgStatus = "FAILED";
        }
        $return = true;
    }

    if (strpos($responseReceived, "0: Accepted for delivery") !== false) {
        $msgStatus = "SUCCESS";
    }

    $table = "trivia_interaction_mt_logs";
    $smsArray = [];
    $smsArray["RESPONSE_TIME"] = date("Y-m-d H:i:s");
    $smsArray["RESPONSE_RECEIVED"] = $responseReceived;
    $smsArray["STATUS_CODE"] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus["smsID"], $smsArray);
    return $return;
}