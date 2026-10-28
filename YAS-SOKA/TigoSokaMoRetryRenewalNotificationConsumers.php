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
    $txnstatus = $msg['txnstatus'];
    $paymentDetails = $msg['paymentdetail'];
    $requestID = $msg['requestid'];
    $deliveryState = processMessage($msisdn, $txnstatus, $requestID, $paymentDetails);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_mo_retry_renewal_router", false, true, false, false);
$channel->queue_bind('tigo_soka_mo_retry_renewal_router', 'triviaExchange', 'tigo.soka.mo.retry.renewal.router');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_mo_retry_renewal_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails) {
    $sokaServicesObj = new SokaServices("consumer");
    
    $retryDetails = $sokaServicesObj->getRetryDetails($msisdn);
    if($txnStatus==="SUCCESS"){
    $retry = [];
    $retry['IS_OUTGOING'] = 0;
    $retry['COUNT'] = 0;
    
         $sokaServicesObj->_update("trivia_mo_retry_process", "MSISDN", $msisdn, $retry);
    $callbackArray = [];
    $callbackArray['TXN_STATUS'] = "SUCCESS";
    $callbackArray['PAYMENT_DETAILS'] = $paymentDetails;
    $callbackArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $where =  "REQUEST_ID";
    $value = $requestID;
    $txntable = "vas_transaction_trivia_mo_retry";
    $return  = $sokaServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
   }

   else{
    $callbackArray = [];
    $callbackArray['TXN_STATUS'] = "FAILED";
    $callbackArray['PAYMENT_DETAILS'] = $paymentDetails;
    $callbackArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $where =  "REQUEST_ID";
    $value = $requestID;
    $txntable = "vas_transaction_trivia_mo_retry";
    $return = $sokaServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
    if($retryDetails['COUNT']===3){
        $retryTime = date("Y-m-d 08:00:00");
    }
    else{
        $retryTime = date("Y-m-d 20:00:00");
    }
    $retry['IS_OUTGOING'] = 1;
    $retry['COUNT'] = $retryDetails['COUNT'] -1 ;
    $retry['RETRY_TIME'] = $retryTime;    
    $sokaServicesObj->_update("trivia_mo_retry_process", "MSISDN", $msisdn, $retry);
    
    }
    $sokaServicesObj->closeDB();
    return true;
}

