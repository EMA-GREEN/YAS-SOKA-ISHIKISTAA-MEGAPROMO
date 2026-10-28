<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
  .php'; */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require_once 'config.php';
require_once 'SokaLib.php';
require_once 'SokaServices.php';


try {
    $connection = new AMQPStreamConnection("localhost", 5672, "trivia_manage", "manage@CNu8Ut");
    $channel = $connection->channel();
} catch (Exception $ex) {

    $ex->getMessage();
    echo $ex;
}

$callback = function($data) {
    $msg = json_decode($data->body, TRUE);
    $msisdn = $msg['msisdn'];
    $scheduleID = $msg['schedule_id'];
    $deliveryState = processMessage($msisdn,$scheduleID);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_openquestion_router", false, true, false, false);
$channel->queue_bind('tigo_soka_openquestion_router', 'triviaExchange', 'tigosoka.openquestion.messages.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_openquestion_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn,$scheduleID) {
    $sokaServicesObj = new SokaServices("consumer");
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, 0,0); //create soka libe and define amount
    
    
    $subDetails = $sokaServicesObj->fetchLastQuestion($msisdn);
    $subscriberInfo = [];
    $totalPoints  = $subDetails['TOTAL_POINTS'];
    $openQuestionArray = $sokaServicesObj->getOpenQuestion($scheduleID);
    if(empty($openQuestionArray)){//removed
            return true;
        }
         $qnsms = $openQuestionArray['CONTENT'];  
         $question = $subDetails['QUESTION'];
         $sms  = $qnsms." ".$question;
         $qnsms = str_replace("%msisdn", $msisdn, $sms);
         $qnsms = str_replace("%s", $totalPoints, $qnsms);
         $sms = $sokaLib->convert(utf8_encode($qnsms));
         $smsstatus = $sokaLib->sendopenQuestion($sms);
         $subscriberInfo['_STATUS'] = 'QN_SENT';
         $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
        if ($smsstatus['isResponse']) {
            updateSMSStatus($sokaServicesObj, $smsstatus);
        } else {
            $sokaServicesObj->closeDB();
            return TRUE;
        }
        $sokaServicesObj->closeDB();
        return TRUE;
    
}

function updateContentSMS($sokaServicesObj, $smsstatus = []) {

    $smsID = $smsstatus['smsID'];
    $responseCode = json_decode($smsstatus['response'], TRUE);
    $responseReceived = $smsstatus['response'];
    if (isset($responseCode['code']) && $responseCode['code'] == "SUCCESS") {
        $msgStatus = "SUCCESS";
    } else {
        if (isset($responseCode['code'])) {
            $msgStatus = $responseCode['code'];
        } else {
            $msgStatus = 'FAILED';
        }
    }
    $sokaServicesObj->updateSMSStatus($smsID, $msgStatus, $responseReceived);
    return True;
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



