<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once 'config.php';
require_once 'IshikistaaLib.php';
require_once 'IshikistaaServices.php';

$msisdn = "255656692469";

sendOpenQuestion($msisdn);

function sendOpenQuestion($msisdn){
    $ishikistaaServicesObj = new ishikistaaServices("consumer");

    $userAccessToken = $ishikistaaServicesObj->userAccessToken();
    $ishikistaaLib = new ishikistaaLib($msisdn, $userAccessToken, 0, 0);

    $subDetails = $ishikistaaServicesObj->fetchLastQuestion($msisdn);

    $totalPoints  = $subDetails['TOTAL_POINTS'];

    $question = $subDetails['QUESTION'];
    $sms  = OPEN_QN_CONTENT." ".$question;
    $qnsms = str_replace("%msisdn", $msisdn, $sms);
    $qnsms = str_replace("%s", $totalPoints, $qnsms);
    $sms = $ishikistaaLib->convert(utf8_encode($qnsms));
    $smsstatus = $ishikistaaLib->sendopenQuestion($sms);

    $subscriberInfo = [];
    $subscriberInfo['_STATUS'] = 'QN_SENT';
    $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

    if ($smsstatus['isResponse']) {
        updateOpenQnSMSStatus($ishikistaaServicesObj, $smsstatus);
    }
}

function updateOpenQnSMSStatus($ishikistaaServicesObj, $smsstatus = []) {
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
    $ishikistaaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}