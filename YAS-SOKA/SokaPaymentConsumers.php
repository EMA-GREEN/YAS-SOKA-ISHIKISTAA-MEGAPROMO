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
require_once 'PredictAndWin.php';


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
$channel->queue_declare("tigo_soka_payment_router", false, true, false, false);
$channel->queue_bind('tigo_soka_payment_router', 'triviaExchange', 'tigosoka.payments.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_payment_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails, $billingSystem) {
    $sokaServicesObj = new SokaServices("consumer");
    $txndetails = $sokaServicesObj->transactionDetails($requestID,$msisdn);
    if (empty($txndetails)) {
        $sokaServicesObj->closeDB();
        return true;
    }
    $txnID = $txndetails['ID'];
    $referenceID = $txndetails['REFERENCE_ID'];
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, $requestID, $referenceID);

    if (PredictAndWin::isPredictWinCharge($sokaServicesObj, $requestID, $msisdn, $txnID)) {
        $handled = PredictAndWin::handlePayment($msisdn, $txnStatus, $requestID, $paymentDetails, $txndetails, $sokaLib, $sokaServicesObj);
        $sokaServicesObj->closeDB();
        return $handled;
    }

    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);
    $answerDetails = $sokaServicesObj->answerDetails($txnID);
    $paymentArray = [];
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    if ($txnStatus === "SUCCESS") {//send question,updateanswer,updatetransaction
    
        
        
        if (strpos($requestID, 'PU') !== false) {///record  with point no answer, update subscriber point,update and record transaction
        
        $paymentArray['TXN_STATUS'] = 'SUCCESS';
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
        $paymentArray['RESPONSE_RECEIVED'] = "SUCCESS : " . $paymentDetails;
        $where = "ID";
        $value = $txnID;
        $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
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
        $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
        $subscriberArray = [];
        $currentLevel = $subDetails['USER_LEVEL'];
        $subscriberArray['LAST_ANSWER_DATE'] = date("Y-m-d H:i:s");
        $subscriberArray['LAST_CHARGING'] = date("Y-m-d H:i:s");
        $subscriberArray['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $points;
        $subscriberArray['USER_LEVEL'] = $subDetails['USER_LEVEL'] + 1;
        $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
        $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberArray);
        //send another sms
        //$message = "Una pointi ".$totalPoints. " Successfully Purchased ".$points;
        
        
        $message = PURCHASE;
        $message = str_replace("%totalpoints",$totalPoints,$message);
        $message = str_replace("%point",$points,$message);
        
        
        $purchase = $sokaLib->interactiveRequest($message,$txndetails['MO_ID'],$txnID,"PURCHASE");
        updateSMSStatus($sokaServicesObj, $purchase,$msisdn); 
        $txnData = [];
        $txnData['MO_ID'] = $txndetails['MO_ID'];
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txndetails['AMOUNT'];
        $txnData['TXN_STATUS'] = "SUCCESS";
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
        $table = "vas_transaction_trivia_" . date("Ymd");
        $sokaServicesObj->recordData($table, $txnData);

        //update point purchase table
        $pointPurchaseUpdate = [];
        $pointPurchaseUpdate['AMOUNT'] = $amount;
        $pointPurchaseUpdate['POINTS'] = $earnedPoints;
        $pointPurchaseUpdate['STATUS'] = "SUCCESS";
        $pptable = "tbl_point_purchase_" . date("Ymd");
        $sokaServicesObj->_update($pptable, "REQUEST_ID", $requestID, $pointPurchaseUpdate);
        return true;
        }

        //user level;
        $paymentArray['TXN_STATUS'] = 'SUCCESS';
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
        $paymentArray['RESPONSE_RECEIVED'] = "SUCCESS : " . $paymentDetails;
        $where = "ID";
        $value = $txnID;
        $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
        
        
       
        
        $points = $answerDetails['EARNED_POINTS'];
        $answerArray = [];
        $answerArray['BILLING_STATUS'] = 'SUCCESS';
        $where = "TXN_ID";
        $value = $txnID;
        $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
        $subscriberArray = [];
        $currentLevel = $subDetails['USER_LEVEL'];
        $subscriberArray['LAST_ANSWER_DATE'] = date("Y-m-d H:i:s");
        $subscriberArray['LAST_CHARGING'] = date("Y-m-d H:i:s");
        $subscriberArray['TOTAL_POINTS'] = $subDetails['TOTAL_POINTS'] + $points;
        $subscriberArray['USER_LEVEL'] = $subDetails['USER_LEVEL'] + 1;
        $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
        if ($answerDetails['BILLING_STATUS'] === "SENT_BILLING" || $answerDetails['BILLING_STATUS'] === "LOW_BALANCE_FAILED") {
            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
            $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
            $question = $questionData['QUESTION'];
            $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);
            $appendID = $subDetails['APPEND_ID'];
            $firsAppend = $sokaServicesObj->getAppendContent();
            if (!empty($firsAppend)) {
                $columnName = strtolower(date('l'));
                $qnsms .= "" . $firsAppend[$columnName] . "  ";
            }
            $appendContent = $sokaServicesObj->getStringAppend($appendID);
            if (!empty($appendContent)) {
                $qnsms .= "" . $appendContent['CONTENT'] . " " . $question . ".";
            } else {
                $qnsms .= "" . $question . ".";
            }
            $txnID = $txndetails['ID'];
            $moID = $txndetails['MO_ID'];
            $smsstatus = $sokaLib->interactiveRequest($qnsms, $moID, $txnID, "QUESTION");
            $subscriberArray['APPEND_ID'] = $appendID + 1;
            $subscriberArray['LAST_QN_ID'] = $nextQnID;
            $subscriberArray['_STATUS'] = "QN_SENT";
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberArray);
             $rewards = $sokaServicesObj->getRewards();
            if(!empty($rewards)){
            $subscriberCurrentLevel  = $subDetails['USER_LEVEL'];
            $subscriberNextLeveL = $subscriberCurrentLevel+1;
            $isSublevel = $sokaServicesObj->getLevelDetails($subscriberNextLeveL);
            
            
        }
             if(!empty($isSublevel)){
                $rewardsms = $sokaLib->sendSms($txnID, $moID, "REWARD_MSG", $isSublevel['LEVEL_MSG']);
                updateSMSStatus($sokaServicesObj, $rewardsms,$msisdn);
                
            }
                                
            
            if ($smsstatus['isResponse']) {
                updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
            } 
            else{
              updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
            }
        }
       
        $txnData = [];
        $txnData['MO_ID'] = $txndetails['MO_ID'];
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txndetails['AMOUNT'];
        $txnData['TXN_STATUS'] = "SUCCESS";
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date("Y-m-d H:i:s");
        $table = "vas_transaction_trivia_" . date("Ymd");
        $sokaServicesObj->recordData($table, $txnData);
        $paymentUpdateArray = [];
        $paymentUpdateArray['TXN_STATUS'] = 'SUCCESS';
        $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
        $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
        $sokaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
        
        
        $sokaServicesObj->closeDB();
        return true;
    } else {//send error message
        if (strpos(strtolower($paymentDetails), 'low balance') !== false 
            || strpos(strtolower($paymentDetails), 'insufficient balance') !== false) {
            
            
            
            if (strpos($requestID, 'PU') !== false) {
                
                
                
            $answerArray = [];
            $where = "TXN_ID";
            $value = $txnID;
            $answerArray['BILLING_STATUS'] = 'LOW_BALANCE_FAILED';
            $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
            $paymentArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
            $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
            $where = "ID";
            $value = $txnID;
            $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
            $txnrecordDetails = $sokaServicesObj->transactionDetails($requestID,$msisdn);
            $paymentUpdateArray = [];
            $paymentUpdateArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
            $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
            $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
            $sokaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
            $moID = $txndetails['MO_ID'];
            $smsstatus = $sokaLib->interactiveRequest(LOW_BALANCE_PURCHASE, $moID, $txnID, "LOW_BALANCE");
            updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);

            //update point purchase table
            $pointPurchaseUpdate = [];
            $pointPurchaseUpdate['STATUS'] = "LOW_BALANCE_FAILED";
            $pptable = "tbl_point_purchase_" . date("Ymd");
            $sokaServicesObj->_update($pptable, "REQUEST_ID", $requestID, $pointPurchaseUpdate);
                
            return true;    
            }
            
            
            $answerArray = [];
            $where = "TXN_ID";
            $value = $txnID;
            $answerArray['BILLING_STATUS'] = 'LOW_BALANCE_FAILED';
            $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
            $paymentArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
            $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
            $where = "ID";
            $value = $txnID;
            $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value, $paymentArray,$msisdn);
            $txnrecordDetails = $sokaServicesObj->transactionDetails($requestID,$msisdn);
            $paymentUpdateArray = [];
            $paymentUpdateArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
            $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
            $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
            $sokaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
            
            if ($txnrecordDetails['AMOUNT'] === "99" && $billingSystem !== "WALLET_ACCOUNT") {//send for fallback
                $moID = $txndetails['MO_ID'];
                $data = $sokaLib->sendCharging($moID, 45, TRUE,"PLAY");
                $paymentResponse = updatePaymentStatus($sokaServicesObj, $data);
                if (!$paymentResponse) {//errror
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .=  ". ". $questionData['QUESTION'] . ".";
                    $smsstatus = $sokaLib->interactiveRequest($questionSMS,$moID,$txnID,"QN_ERROR_MSG");
                    updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
                    $sokaServicesObj->closeDB();
                    return TRUE;
                } else {
                    $answerArray = [];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $answerArray['TXN_ID'] = $data['txnID'];
                    $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    
                    
                    
                    $sokaServicesObj->closeDB();
                    return true;
                }
                return TRUE;
            } else {
                
                ///send for 30tsh
              if ($txnrecordDetails['AMOUNT'] === "45" && $billingSystem !== "WALLET_ACCOUNT") {
                $moID = $txndetails['MO_ID'];
                $data = $sokaLib->sendCharging($moID, 30, TRUE,"PLAY");
                $paymentResponse = updatePaymentStatus($sokaServicesObj, $data);
                if (!$paymentResponse) {//errror
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .=  ". ". $questionData['QUESTION'] . ".";
                    $smsstatus = $sokaLib->interactiveRequest($questionSMS,$moID,$txnID,"QN_ERROR_MSG");
                    updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
                    $sokaServicesObj->closeDB();
                    return TRUE;
                } else {
                    $answerArray = [];
                    $where = "TXN_ID";
                    $value = $txnID;
                    $answerArray['TXN_ID'] = $data['txnID'];
                    $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
                    $sokaServicesObj->closeDB();
                    return true;
                }         
               }   
                else{
                $moID = $txndetails['MO_ID'];
                $smsstatus = $sokaLib->interactiveRequest(LOW_BALANCE, $moID, $txnID, "LOW_BALANCE");
                updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
                
                //adding to retry table
                
                $errorData = [];
                $errorData['MSISDN'] = $msisdn;
                $errorData['MO_ID'] = $moID;
                $errorData['TXN_ID'] = $answerDetails['TXN_ID'];
                $errorData['TEXT'] = $answerDetails['ANSWER'];
                $errorData['ANSWER_STATUS'] = $answerDetails['ANSWER_STATUS'];
                $errorData['EARNED_POINTS'] = $answerDetails['EARNED_POINTS'];
                
                    
                $errorData['COUNT'] = 1;
                    
                    
                $errorData['QN_ID'] = $answerDetails['QN_ID'];
                $errorData['REFERENCE_ID'] = $referenceID;
                $errorData['MO_DATE'] = date("Y-m-d H:i:s");
                $errorData['NEXT_RETRY'] = date("Y-m-d H:i:s",strtotime("+60 minutes"));
                $errorData['RETRY_TYPE'] = "LOW_BALANCE";
                $sokaServicesObj->recordData("tbl_mo_retry", $errorData);
            }
                $sokaServicesObj->closeDB();
                return TRUE;
            }
        } else {
            $answerArray = [];
            $where = "TXN_ID";
            $value = $txnID;
            $answerArray['BILLING_STATUS'] = 'FAILED';
            $sokaServicesObj->update("tbl_qn_answers", $where, $value, $answerArray);
            $paymentArray['TXN_STATUS'] = 'BILLING_ERROR';
            if(empty($paymentDetails)|| strlen($paymentDetails)<=0){    
                $paymentDetails = "No Reason parameter found for failure";
            }
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
            $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;
            $where = "ID";
            $value = $txnID;
            
            $sokaServicesObj->updatePayment("vas_transaction_trivia", $where, $value,$paymentArray,$msisdn);
            $smsstatus = $sokaLib->interactiveRequest(ERROR_MSG,0,$txnID,"ERROR");
            updateSMSStatus($sokaServicesObj, $smsstatus,$msisdn);
            $paymentUpdateArray = [];
            $paymentUpdateArray['TXN_STATUS'] = 'BILLING_ERROR';
            $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
            $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
            $sokaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
            
                $errorData = [];
                $errorData['MSISDN'] = $msisdn;
                $errorData['MO_ID'] = $txndetails['MO_ID'];
                $errorData['TXN_ID'] = $answerDetails['TXN_ID'];
                $errorData['TEXT'] = $answerDetails['ANSWER'];
                $errorData['ANSWER_STATUS'] = $answerDetails['ANSWER_STATUS'];
                $errorData['EARNED_POINTS'] = $answerDetails['EARNED_POINTS'];
                $errorData['COUNT'] = 3;
                $errorData['QN_ID'] = $answerDetails['QN_ID'];
                $errorData['REFERENCE_ID'] = $referenceID;
                $errorData['MO_DATE'] = date("Y-m-d H:i:s");
                $errorData['NEXT_RETRY'] = date("Y-m-d H:i:s",strtotime("+30 minutes"));
                $errorData['RETRY_TYPE'] = "BILLING_ERROR";
                $sokaServicesObj->recordData("tbl_mo_retry", $errorData);
                
            
            $sokaServicesObj->closeDB();
            return TRUE;
        }
    }

    $sokaServicesObj->closeDB();
    return TRUE;
}

function updateSMSStatus($sokaServicesObj, $smsstatus = [],$msisdn) {



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
    
      if($msgStatus !=="SUCCESS"){///send it to reprocess table
        
        $reprocessArray = [];
        
        $reprocessArray['SMS_ID'] = $smsstatus['smsID'];
        $reprocessArray['MSISDN'] = $msisdn;
        $reprocessArray['RETRY_TIME'] = date("Y-m-d H:i:s",strtotime("+5 seconds"));
        $sokaServicesObj->recordData("sms_reprocess",$reprocessArray);
        
    }
    
    
    $table = "trivia_interaction_mt_logs";
    $smsArray = [];
    $smsArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $smsArray['RESPONSE_RECEIVED'] = $responseReceived;
    $smsArray['STATUS_CODE'] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}

function updatePaymentStatus($sokaServicesObj, $data = []) {

    $txnID = $data['txnID'];
    $responseCode = json_decode($data['response'], TRUE);
    $responseReceived = $data['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] == 0) {
        $msgStatus = "ACKNOWLEDGED";
        $return = TRUE;
    } else {
        if (isset($responseCode['responseParam']['statusCode'])) {
            $msgStatus = $responseCode['responseParam']['statusCode'];
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
    $sokaServicesObj->_update($table, "ID", $txnID, $paymentArray);
    
        $paymentUpdateArray = [];
        $paymentUpdateArray['TXN_STATUS'] = $msgStatus;
        $paymentUpdateArray['RESP_TIME'] = date("Y-m-d H:i:s");
        $sokaServicesObj->_update("vas_transaction_trivia_new", "TXN_ID", $txnID,$paymentUpdateArray);
    
    return $return;
}

function getPointPurchaseKeyword($amount, $point_purchase_map) {
    $key = array_search($amount, $point_purchase_map);
    return ($key !== false) ? $key : "";
}