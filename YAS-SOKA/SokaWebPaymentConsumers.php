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
    $deliveryState = processMessage($msisdn, $txnstatus, $requestID,$paymentDetails);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_webpayment_router", false, true, false, false);
$channel->queue_bind('tigo_soka_webpayment_router', 'triviaExchange', 'tigosoka.webpayments.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_webpayment_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID,$paymentDetails) {
    $sokaServicesObj = new SokaServices("consumer");
    $txndetails = $sokaServicesObj->transactionDetails($requestID,$msisdn);
    $txnID = $txndetails['ID'];
    $referenceID = $txndetails['REFERENCE_ID'];
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);
    $answerDetails = $sokaServicesObj->answerDetails($txnID);
    $paymentArray = [];
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    if ($txnStatus === "SUCCESS") {//send question,updateanswer,updatetransaction
        
        $paymentArray['TXN_STATUS'] = 'SUCCESS';
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
        $where = "REQUEST_ID";
        $value = $requestID;
        $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value,$paymentArray,$msisdn);
        $sokaServicesObj->updatePayment("vas_transaction_trivia_new", $where, $value,$paymentArray,$msisdn);
        
        
        $txnData = [];
        $txnData['MO_ID'] = 0;
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txndetails['AMOUNT'];
        $txnData['TXN_STATUS'] = "SUCCESS";
        $txnData['CHANNEL'] = 'WEB';
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
        $table = "vas_transaction_trivia_" . date("Ymd");
        $sokaServicesObj->recordData($table, $txnData);
       
        
        
        
        $points = $answerDetails['EARNED_POINTS'];
        $answerArray = [];
        $answerArray['BILLING_STATUS'] = 'SUCCESS';
        $where = "TXN_ID";
        $value = $txnID;
        $sokaServicesObj->update("tbl_qn_answers", $where, $value,$answerArray);
        $subscriberArray = [];
        $subscriberArray['LAST_ANSWER_DATE'] = date("Y-m-d H:i:s");
        $subscriberArray['LAST_CHARGING'] = date("Y-m-d H:i:s");
        $subscriberArray['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $points;
        $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
        ///send next question
        if ($answerDetails['BILLING_STATUS'] === "SENT_BILLING") {
            $txnID = $txndetails['ID'];
            $moID = $txndetails['MO_ID'];           
            $subscriberArray['_STATUS'] = "BILLED";
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn,$subscriberArray);
            $sokaServicesObj->closeDB();
        }
       return true; 
    }
else {//send error message
         $paymentArray['TXN_STATUS'] = 'FAILED';
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
        $where = "REQUEST_ID";
        $value = $requestID;
        $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value,$paymentArray,$msisdn);
        $sokaServicesObj->updatePayment("vas_transaction_trivia_new", $where, $value,$paymentArray,$msisdn);
        $answerArray = [];
        $answerArray['BILLING_STATUS'] = 'FAILED';
        $sokaServicesObj->update("tbl_qn_answers", $where, $value);
        if(strpos(strtolower($paymentDetails), 'low balance') !== false 
            || strpos(strtolower($paymentDetails), 'insufficient balance') !== false){
            $subscriberArray['_STATUS'] = "LOW_BALANCE";
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn,$subscriberArray);
            $sokaServicesObj->closeDB();
            return true;
        }
        else{
            $subscriberArray['_STATUS'] = "ERROR_BILLING";
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn,$subscriberArray);
            $sokaServicesObj->closeDB();
            return true;
        }
    }
    
    $sokaServicesObj->closeDB();
}

function updatePaymentStatus($sokaServicesObj, $data = []) {
    $smsID = $data['txnID'];
    $responseCode = json_decode($smsstatus['response'], TRUE);
    $responseReceived = $smsstatus['response'];
    if (isset($responseCode['status']) && $responseCode['status'] == 0) {
        $msgStatus = "ACKNOWLEDGED";
        $return = TRUE;
    } else {
        if (isset($responseCode['status'])) {
            $msgStatus = $responseCode['status'];
        } else {
            $msgStatus = 'FAILED';
        }
        $return = FALSE;
    }
    $table = "vas_transaction_trivia";
    $paymentArray = [];
    $paymentArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $paymentArray['RESPONSE_RECEIVED'] = $responseReceived;
    $paymentArray['STATUS_CODE'] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}
