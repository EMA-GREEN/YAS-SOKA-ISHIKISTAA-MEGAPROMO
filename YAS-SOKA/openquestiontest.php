<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once "config.php";
require_once "SokaLib.php";
require_once "SokaServices.php";

$msisdn = "255656692469";

sendOpenQuestion($msisdn);

function sendOpenQuestion($msisdn){
	$sokaServicesObj = new SokaServices("consumer");
	$userAccessToken = $sokaServicesObj->userAccessToken();
	$sokaLib = new SokaLib($msisdn, $userAccessToken, 0, 0);

	$subDetails = $sokaServicesObj->fetchLastQuestion($msisdn);

	$totalPoints  = $subDetails['TOTAL_POINTS'];

	$content = "Hongera sana umejikusanyia alama %s hadi sasa. Ongeza alama kidogo tu unaweza kubahatika na sehemu ya Tsh 5,500,000;";  
	$question = $subDetails['QUESTION'];
	$sms  = $content." ".$question;
	$qnsms = str_replace("%msisdn", $msisdn, $sms);
	$qnsms = str_replace("%s", $totalPoints, $qnsms);
	$sms = $sokaLib->convert(utf8_encode($qnsms));
	$smsstatus = $sokaLib->sendopenQuestion($sms);

	$subscriberInfo = [];
	$subscriberInfo['_STATUS'] = 'QN_SENT';
		$sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

	if ($smsstatus['isResponse']) {
	    updateSMSStatus($sokaServicesObj, $smsstatus);
	}

	$sokaServicesObj->closeDB();
}

function updateSMSStatus($sokaServicesObj, $smsstatus = []) {
    $smsID = $smsstatus['smsID'];
    $responseCode = json_decode($smsstatus['response'], TRUE);
    $responseReceived = $smsstatus['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] === 0) {
        $msgStatus = "SUCCESS";
        $responseReceived = "SUCCESS";
        $return = TRUE;
    } else {
        if (isset($responseCode['responseParam']['statusCode'])) {
            $msgStatus = $responseCode['responseParam']['statusCode'];
            if(isset($responseCode['responseParam']['description'])){
                $responseReceived = $responseCode['responseParam']['description'];
            }
            else{
                $responseReceived = $msgStatus;
            }
            
        } else {
            $msgStatus = 'FAILED';
        }
        $return = true;
    }
    $table = "tbl_openquestion_logs";
    $smsArray = [];
    $smsArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $smsArray['RESPONSE_RECEIVED'] = $responseReceived;
    $smsArray['STATUS_CODE'] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}