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
require_once 'IshikistaaLib.php';
require_once 'IshikistaaServices.php';


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
    $billingSystem = $msg['billingSystem'];
    $deliveryState = processMessage($msisdn, $txnstatus, $requestID, $paymentDetails, $billingSystem);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_ishikistaa_payment_router", false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_payment_router', 'triviaExchange', 'tigoishikistaa.payments.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_ishikistaa_payment_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails, $billingSystem) {
    $ishikistaaServicesObj = new ishikistaaServices("consumer");
    $txndetails = $ishikistaaServicesObj->transactionDetails($requestID,$msisdn);
    $txnID = $txndetails['ID'];
    $referenceID = $txndetails['REFERENCE_ID'];
    $userAccessToken = $ishikistaaServicesObj->userAccessToken();
    $ishikistaaLib = new ishikistaaLib($msisdn, $userAccessToken, $requestID, $referenceID);
    $subDetails = $ishikistaaServicesObj->getSubscriberDetails($msisdn);
    $answerDetails = $ishikistaaServicesObj->answerDetails($txnID);
    $paymentArray = [];
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    if ($txnStatus === "SUCCESS") {//send question,updateanswer,updatetransaction

        if (strpos($requestID, 'PU') !== false) {///record  with point no answer, update subscriber point,update and record transaction
            $paymentArray['TXN_STATUS'] = 'SUCCESS';
            $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
            $paymentArray['RESPONSE_RECEIVED'] = "SUCCESS : " . $paymentDetails;
            $where = "ID";
            $value = $txnID;
            $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
            $amount = $txndetails['AMOUNT'];

            $purchaseKey = getPointPurchaseKeyword($amount, POINT_PURCHASE_MAP);
            if(!empty($purchaseKey)){
                //trim "P" then the rest is Points(eg. P300)
                $earnedPoints = substr($purchaseKey, 1);
            }else{
                $earnedPoints = "100";
            }
                
            $points = $earnedPoints;
            $answerArray = [];
            $answerArray['BILLING_STATUS'] = 'SUCCESS';
            $answerArray['EARNED_POINTS'] = $earnedPoints;
            $where = "TXN_ID";
            $value = $txnID;
            $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
            $subscriberArray = [];
            $subscriberArray['LAST_ANSWER_DATE'] = date("Y-m-d H:i:s");
            $subscriberArray['LAST_CHARGING'] = date("Y-m-d H:i:s");
            $subscriberArray['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $points;
            $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
            $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberArray);
            //send another sms
            //$message = "Una pointi ".$totalPoints. " Successfully Purchased ".$points;
            
            
            $message = PURCHASE;
            $message = str_replace("%totalpoints",$totalPoints,$message);
            $message = str_replace("%point",$points,$message);
            
            
            $purchase = $ishikistaaLib->interactiveRequest($message,$txndetails['MO_ID'],$txnID,"PURCHASE");
            updateSMSStatus($ishikistaaServicesObj, $purchase,$msisdn); 
            $txnData = [];
            $txnData['MO_ID'] = $txndetails['MO_ID'];
            $txnData['TXN_ID'] = $txnID;
            $txnData['MSISDN'] = $msisdn;
            $txnData['AMOUNT'] = $txndetails['AMOUNT'];
            $txnData['TXN_STATUS'] = "SUCCESS";
            $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
            $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
            $table = "vas_transaction_trivia_" . date("Ymd");
            $ishikistaaServicesObj->recordData($table, $txnData);

            //update point purchase table
            $pointPurchaseUpdate = [];
            $pointPurchaseUpdate['AMOUNT'] = $amount;
            $pointPurchaseUpdate['POINTS'] = $earnedPoints;
            $pointPurchaseUpdate['STATUS'] = "SUCCESS";
            $pptable = "tbl_point_purchase_" . date("Ymd");
            $ishikistaaServicesObj->_update($pptable, "REQUEST_ID", $requestID, $pointPurchaseUpdate);
            $ishikistaaServicesObj->closeDB();
            return true;
        }

        //user level;
        $paymentArray['TXN_STATUS'] = 'SUCCESS';
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
        $where = "ID";
        $value = $txnID;
        $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
        $points = $answerDetails['EARNED_POINTS'];
        $answerArray = [];
        $answerArray['BILLING_STATUS'] = 'SUCCESS';
        $where = "TXN_ID";
        $value = $txnID;
        $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
        $subscriberArray = [];
        $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
        $subscriberArray['LAST_ANSWER_DATE'] = date("Y-m-d H:i:s");
        $subscriberArray['LAST_CHARGING'] = date("Y-m-d H:i:s");
        $subscriberArray['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $points;
        if ($answerDetails['BILLING_STATUS'] === "SENT_BILLING" || $answerDetails['BILLING_STATUS'] === "LOW_BAANCE_FAILED") {
            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
            $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
            $question = $questionData['QUESTION'];
            $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);
            $qnsms .= " " . $question . ".";
            $txnID = $txndetails['ID'];
            $moID = $txndetails['MO_ID'];
            $smsstatus = $ishikistaaLib->interactiveRequest($qnsms, $moID, $txnID, "QUESTION");
            $subscriberArray['LAST_QN_ID'] = $nextQnID;
            $subscriberArray['_STATUS'] = "QN_SENT";
            $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberArray);
            if ($smsstatus['isResponse']) {
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            } else {
                $smsstatus = $ishikistaaLib->interactiveRequest($qnsms, $moID, $txnID, "QUESTION");
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            }
        }
        $ishikistaaServicesObj->update("tbl_qn_subscribers", "MSISDN", $msisdn);
        $txnData = [];
        $txnData['MO_ID'] = $txndetails['MO_ID'];
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txndetails['AMOUNT'];
        $txnData['TXN_STATUS'] = "SUCCESS";
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
        $table = "vas_transaction_trivia_" . date("Ymd");
        $ishikistaaServicesObj->recordData($table, $txnData);
        $ishikistaaServicesObj->closeDB();
        return TRUE;


    } else {//send error message
        $moID = $txndetails['MO_ID'];

        if (strpos($paymentDetails, 'low balance') !== false 
            || strpos(strtolower($paymentDetails), 'insufficient balance') !== false) {

            if (strpos($requestID, 'PU') !== false) {     
                $answerArray = [];
                $where = "TXN_ID";
                $value = $txnID;
                $answerArray['BILLING_STATUS'] = 'LOW_BALANCE_FAILED';
                $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                $paymentArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
                $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
                $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
                $where = "ID";
                $value = $txnID;
                $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
                $txnrecordDetails = $ishikistaaServicesObj->transactionDetails($requestID,$msisdn);
                $paymentUpdateArray = [];
                $paymentUpdateArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
                $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
                $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
                $ishikistaaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
                $moID = $txndetails['MO_ID'];
                $smsstatus = $ishikistaaLib->interactiveRequest(LOW_BALANCE_PURCHASE, $moID, $txnID, "LOW_BALANCE");
                updateSMSStatus($ishikistaaServicesObj, $smsstatus,$msisdn);

                //update point purchase table
                $pointPurchaseUpdate = [];
                $pointPurchaseUpdate['STATUS'] = "LOW_BALANCE_FAILED";
                $pptable = "tbl_point_purchase_" . date("Ymd");
                $ishikistaaServicesObj->_update($pptable, "REQUEST_ID", $requestID, $pointPurchaseUpdate);
                $ishikistaaServicesObj->closeDB();

                return true;    
            }

            $answerArray = [];
            $answerArray['BILLING_STATUS'] = 'LOW_BALANCE_FAILED';
            $where = "TXN_ID";
            $value = $txnID;
            $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);

            $paymentArray['TXN_STATUS'] = 'LOW_BALANCE';
            $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
            $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
            $where = "REQUEST_ID";
            $value = $requestID;
            $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
            $txnrecordDetails = $ishikistaaServicesObj->transactionDetails($requestID,$msisdn);
            if ($txnrecordDetails['AMOUNT'] === "99" && $billingSystem !== "WALLET_ACCOUNT") {//send for fallback
                $data = $ishikistaaLib->sendCharging($moID, 45, TRUE);
                $paymentResponse = updatePaymentStatus($ishikistaaServicesObj, $data);
                if (!$paymentResponse) {
                    $answerArray = [];
                    $answerArray['TXN_ID'] = $txnrecordDetails['TXN_ID'];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= " \n" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest(ERROR_MSG, $moID,$txnID, "ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                  else{
                    $answerArray = [];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $answerArray['TXN_ID'] = $data['txnID'];
                    $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                return TRUE;
            } else {
                 if ($txnrecordDetails['AMOUNT'] === "45" && $billingSystem !== "WALLET_ACCOUNT") {
                     
                 $data = $ishikistaaLib->sendCharging($moID,30,TRUE);
                $paymentResponse = updatePaymentStatus($ishikistaaServicesObj, $data);
                if (!$paymentResponse) {
                    $answerArray = [];
                    $answerArray['TXN_ID'] = $txnrecordDetails['TXN_ID'];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= " \n" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest(ERROR_MSG, $moID,$txnID, "ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                  else{
                    $answerArray = [];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $answerArray['TXN_ID'] = $data['txnID'];
                    $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }    
                     
                 }   
                 $answerArray = [];
            $answerArray['BILLING_STATUS'] = 'LOW_BALANCE_FAILED';
            $where = "TXN_ID";
            $value = $txnID;
            $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                $paymentArray['TXN_STATUS'] = 'FAILED';
                $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
                $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
                $where = "ID";
                $value = $txnID;
                $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
                $moID = $txndetails['MO_ID'];
                $smsstatus = $ishikistaaLib->interactiveRequest(LOW_BALANCE, $moID, $txnID, "LOW_BALANCE");
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                $ishikistaaServicesObj->closeDB();
                return true;
            }
        } else {
            $paymentArray['TXN_STATUS'] = 'FAILED';
             if(empty($paymentDetails)||!isset($paymentDetails)||$paymentDetails===""){
              $paymentArray['PAYMENT_DETAILS'] = "BILLING_ERROR";    
              $paymentDetails = "BILLING_ERROR";    
             }
            else{
                $paymentArray['PAYMENT_DETAILS'] = $paymentDetails; 
                
            }
            $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s"); 
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
        $where = "ID";
        $value = $txnID;
        $ishikistaaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
           $answerArray = [];
           $answerArray['BILLING_STATUS'] = $paymentDetails;
           $where = "TXN_ID";
           $value = $txnID;
           $ishikistaaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
           
           $smsstatus = $ishikistaaLib->interactiveRequest(ERROR_MSG, $moID, $txnID, "ERROR");
           updateSMSStatus($ishikistaaServicesObj, $smsstatus);
        }
    }
    $ishikistaaServicesObj->closeDB();
    return TRUE;
}

function updateSMSStatus($ishikistaaServicesObj, $smsstatus = []) {



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
        $return = FALSE;
    }
    if(strpos($responseReceived,'0: Accepted for delivery')!==false){
        $msgStatus = "SUCCESS";
    }
    $table = "trivia_sms_logs";
    $smsArray = [];
    $smsArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $smsArray['RESPONSE_RECEIVED'] = $responseReceived;
    $smsArray['STATUS_CODE'] = $msgStatus;
    $ishikistaaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}

function updatePaymentStatus($ishikistaaServicesObj, $data = []) {

    $txnID = $data['txnID'];
    $responseCode = json_decode($data['response'], TRUE);
    $responseReceived = $data['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] == 0) {
        $msgStatus = "ACKNOWLEDGED";
        $return = TRUE;
    } else {
        if (isset($responseCode['responseParam']['status'])) {
            $msgStatus = $responseCode['responseParam']['status'];
        } else {
            $msgStatus = $responseReceived;
        }
        $return = FALSE;
    }
    $table = "vas_transaction_trivia";
    $paymentArray = [];
    $paymentArray['RESPONSE_RECEIVED'] = $msgStatus;
    $paymentArray['TXN_STATUS'] = $msgStatus;
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $ishikistaaServicesObj->_update($table, "ID", $txnID, $paymentArray);
    return $return;
}

function getPointPurchaseKeyword($amount, $point_purchase_map) {
    $key = array_search($amount, $point_purchase_map);
    return ($key !== false) ? $key : "";
}
