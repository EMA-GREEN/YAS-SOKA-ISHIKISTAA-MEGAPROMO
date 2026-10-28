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
    $smsID = $msg['smsID'];
    $logsID = $msg['record_id'];
    $deliveryState = processMessage($msisdn, $smsID,$logsID);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_sms_reprocess", false, true, false, false);
$channel->queue_bind('tigo_soka_sms_reprocess', 'triviaExchange', 'tigosoka.sms.reprocess.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_sms_reprocess", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn,$smsID,$logsID) {
  
    $sokaServicesObj  = new SokaServices("consumer");
    $smsDetails = $sokaServicesObj->getSmsDetails($smsID);

     if($smsDetails['STATUS_CODE']==="SUCCESS"||$smsDetails['STATUS_CODE']==="0"){   
        return true;
    }



    $text = $smsDetails['TEXT'];
    $userAccessToken = $sokaServicesObj->userAccessToken();
	
	
	
    $sokaLib = new SokaLib($msisdn,$userAccessToken,0,0);
    $moID = $smsDetails['MO_ID'];
    $smsType = "RETRY_".$smsDetails['SMS_TYPE'];
    $txnID = $smsDetails['TXN_ID'];
   
    $smsstatus = $sokaLib->interactiveRequest($text, $moID, $txnID, $smsType);
    updateSMSStatus($sokaServicesObj,$smsstatus);
    return true;
    
}



function updateSMSStatus($sokaServicesObj, $smsstatus = []) {



    $smsID = $smsstatus['smsID'];
    $responseCode = json_decode($smsstatus['response'], TRUE);
    $responseReceived = $smsstatus['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] == 0) {
        $msgStatus = "SUCCESS";
        $return = TRUE;
    } else {
        if (isset($responseCode['status'])) {
            $msgStatus = $responseCode['status'];
        } else {
            $msgStatus = 'FAILED';
        }
        $return = TRUE;
    }
     if(strpos($responseReceived,'0: Accepted for delivery')!==false){
        
        $msgStatus = "SUCCESS";
    }
    
    
    
    $table = "trivia_interaction_mt_logs";
    $smsArray = [];
    $smsArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $smsArray['RESPONSE_RECEIVED'] = $responseReceived;
    $smsArray['STATUS_CODE'] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}


