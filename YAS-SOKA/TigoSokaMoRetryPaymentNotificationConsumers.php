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
$channel->queue_declare("tigo_soka_mo_retry_payment_router", false, true, false, false);
$channel->queue_bind('tigo_soka_mo_retry_payment_router', 'triviaExchange', 'tigo.soka.mo.retry.payment.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_mo_retry_payment_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails) {
    $sokaServicesObj = new SokaServices("consumer");
    $txnDetails = $sokaServicesObj->getPaymentDetails($requestID);
    $txnID = $txnDetails['ID'];
    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);
    $retryDetails = $sokaServicesObj->getRetryAnswerDetails($txnID);
    if($txnStatus==="SUCCESS"){
    $retry = [];
    $retry['IS_OUTGOING'] = 0;
    $retry['COUNT'] = 0;
    
    $sokaServicesObj->_update("tbl_mo_retry", "MSISDN", $msisdn, $retry);
    $callbackArray = [];
    $callbackArray['TXN_STATUS'] = "SUCCESS";
    $callbackArray['PAYMENT_DETAILS'] = $paymentDetails;
    $callbackArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $where =  "REQUEST_ID";
    $value = $requestID;
    $txntable = "vas_transaction_trivia";
    
    
    $txnID = $txnDetails['ID'];
    $answerDetails = $sokaServicesObj->getRetryAnswerDetails($txnID);
    
    
    $answerArray = [];
    $answerArray['ANSWER_STATUS'] = $answerDetails['ANSWER_STATUS'];
    $answerArray['EARNED_POINTS'] = $answerDetails['EARNED_POINTS'];
    $answerArray['TXN_ID'] = $answerDetails['RETRY_TXN_ID'];
    
    $answerArray['BILLING_STATUS'] = 'SUCCESS';
    $answerArray['QN_ID'] = $answerDetails['QN_ID'];
    $answerArray['CHANNEL'] = 'RETRY';
    $answerArray['MSISDN'] = $msisdn;
    $answerArray['ANSWER'] = $answerDetails['TEXT'];
    $sokaServicesObj->recordData("tbl_qn_answers", $answerArray);
    //record to transaction table
    
    
    //update subscriber table
    
      $subUpdate = [];
      $subUpdate['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $answerDetails['EARNED_POINTS'];
       $sokaServicesObj->_update("tbl_qn_subscribers","MSISDN", $msisdn, $subUpdate);
    
    
        $txnData = [];
        $txnData['MO_ID'] = $answerDetails['MO_ID'];
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txnDetails['AMOUNT'];
        $txnData['TXN_STATUS'] = "SUCCESS";
        $txnData['CHANNEL'] = "RETRY";
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
        $table = "vas_transaction_trivia_" . date("Ymd");
        $sokaServicesObj->recordData($table, $txnData);
    
    
    
    $return  = $sokaServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
    
    
    //record to answer table
    
    
   }

   else{
    $callbackArray = [];
    $callbackArray['TXN_STATUS'] = "FAILED";
    $callbackArray['PAYMENT_DETAILS'] = $paymentDetails;
    $callbackArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $where =  "REQUEST_ID";
    $value = $requestID;
    $txntable = "vas_transaction_trivia";
    $return = $sokaServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
    
    $retry = [];
    $retry['COUNT'] = 0;
    if($retryDetails['COUNT']===3){
        $retryTime = date("Y-m-d 08:00:00");
    }
    else{
        $retryTime = date("Y-m-d 20:00:00");
    }
    
    $sokaServicesObj->_update("tbl_mo_retry", "ID", $retryDetails['ID'], $retry);
    
    }
    $sokaServicesObj->closeDB();
    return true;
}

