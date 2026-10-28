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
    $message = $msg['message'];
    $smsType = $msg['requesttype'];
    $requestID = $msg['requestid'];
    $referenceID = $msg['referenceid'];
    $logID = $msg['id'];
    $deliveryState = processMessage($msisdn, $message, $logID, $requestID, $smsType, $referenceID);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_ishikistaa_mo_router", false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_mo_router', 'triviaExchange', 'tigoishikistaa.messages.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_ishikistaa_mo_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $message, $logID, $requestID, $requestType, $referenceID) {
//    $moID = $logID;
    
    $ishikistaaServicesObj = new ishikistaaServices("consumer");
    $sms = trim($message);
    $sms = addslashes($sms);
    $userAccessToken = $ishikistaaServicesObj->userAccessToken();
    $ishikistaaLib = new ishikistaaLib($msisdn, $userAccessToken, $requestID, $referenceID); //create ishikistaa libe and define amount
    
    
    $isStaff = $ishikistaaLib->staffSeries();
    $subDetails = $ishikistaaServicesObj->getSubscriberDetails($msisdn);
    $moData = [];
    $moData['MSISDN'] = $msisdn;
    $moData['TEXT'] = $sms;
    $moTable  = "trivia_mo_".date("Ymd");
    $moID = $ishikistaaServicesObj->recordData($moTable,$moData);
    if($moID <= 0){
        return false;
    }
    if ($requestType === "QUERY_POINTS") {//
        $points = $subDetails['TOTAL_POINTS'];
        $pointSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], POINT_MSG);
        $pointSMS = str_replace("%msisdn", $msisdn, $pointSMS);
        $ishikistaaLib->QueryPoints($requestID, $pointSMS, $points);
        $ishikistaaServicesObj->closeDB();
        return true;
    }

if($requestType === "UN_SUBSCRIPTION"){
            $subscriberInfo['ACTIVITY_STATUS'] = "INACTIVE";
            $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
            $smsstatus = $ishikistaaLib->interactiveRequest(UNSUB_SMS, $moID, 0, "UNSUB_SMS");
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            sendRenewal($msisdn,"UNSUB");
            $arrayData = array("MSISDN"=>$msisdn);
    $jsonData = json_encode($arrayData);
    $apiUrl = "http://localhost/TIGO-ISHIKISTAA/notification/unsub_manage/";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_exec($ch);

            return true;
}
    if ($requestType === "SUBSCRIPTION") {
        $userSms = $message;
        if(!$isStaff){
        sendRenewal($msisdn, "SUBSCRIPTION");
        }
        if (empty($subDetails)) {
                $subscriptionArray = [];
                if($isStaff){
                $subscriptionArray['DND_STATUS'] = 'ON';
                }
                 if(strpos($userSms,"-")!==false&&strtok($userSms,"-")==="BOOM_CUSTOMER"){
                $subscriptionArray['CUSTOMER_TYPE'] = "BOOM_CUSTOMER";
            }
                $subscriptionArray['MSISDN'] = $msisdn;
                $subscriptionArray['_STATUS'] = "SUBSCRIBED";
                $ishikistaaServicesObj->recordData("tbl_qn_subscribers", $subscriptionArray);
                //send welcome sms
//            $subSms = $ishikistaaLib->sendSms(0, $moID, "WELCOME_SMS", WELCOME_SMS);
//                updateSMSStatus($ishikistaaServicesObj, $subSms);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS = FIRST_APPEND . $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QUESTION");
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                $ishikistaaServicesObj->closeDB();
                return TRUE;
            }
        
        
           elseif($subDetails['ACTIVITY_STATUS']==="ACTIVE"){//send A question
            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS =  $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0,"QUESTION");
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                
                $ishikistaaServicesObj->closeDB();
                return TRUE;
            
        }
        else{
            
            
//            $subSms = $ishikistaaLib->sendSms(0, $moID, "WELCOME_SMS", WELCOME_SMS);
//            updateSMSStatus($ishikistaaServicesObj, $subSms);
            
            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS =  $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0,"QUESTION");
                $subscriberInfo = [];
                $subscriberInfo['ACTIVITY_STATUS'] = "ACTIVE"; 
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                $subscriberInfo['REACT_DATE'] = date("Y-m-d H:i:s");
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                } 
                $ishikistaaServicesObj->closeDB();
                return TRUE;
            }
        if (empty($subDetails) || $subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
            if (empty($subDetails)) {
                $subscriptionArray = [];
                $subscriptionArray['MSISDN'] = $msisdn;
                $subscriptionArray['_STATUS'] = "SUBSCRIBED";
                $ishikistaaServicesObj->recordData("tbl_qn_subscribers", $subscriptionArray);
                //send welcome sms
//            $subSms = $ishikistaaLib->sendSms(0, $moID, "WELCOME_SMS", WELCOME_SMS);
//                updateSMSStatus($ishikistaaServicesObj, $subSms);
                
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS = FIRST_APPEND . $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QUESTION");
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }

                $ishikistaaServicesObj->closeDB();
                return TRUE;
            } else {//re activate
                $subDetails['ACTIVITY_STATUS'] = "ACTIVE";
                $ishikistaaServicesObj->update("tbl_qn_subscribers", "MSISDN", $msisdn, $subDetails);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS = FIRST_APPEND . $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0,"QUESTION");

                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);


                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                $ishikistaaServicesObj->closeDB();
                return TRUE;
            }
        }
    } elseif ($requestType === "UNSUBSCRIPTION") {
        
        sendRenewal($msisdn, "SUBSCRIPTION");
        
        if (empty($subDetails) || $subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
            $smsstatus = $ishikistaaLib->interactiveRequest(NON_CUSTOMER, $moID, 0,"UNSUB");
            if ($smsstatus['isResponse']) {
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            } else {
                $ishikistaaServicesObj->closeDB();
                return true;
            }
            $ishikistaaServicesObj->closeDB();
            return TRUE;
        } else {
            $smsstatus = $ishikistaaLib->interactiveRequest(UNSUB_SMS, $moID, 0,"UNSUB");
            if ($smsstatus['isResponse']) {
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            } else {
                $ishikistaaServicesObj->closeDB();
                return true;
            }
            $ishikistaaServicesObj->closeDB();
            return TRUE;
        }
    }
    if (empty($subDetails)) {//if not an active customer or non existing customer
        //comehere
                $subreq = $ishikistaaLib->sendSMS(0, $moID, "NON_SUB", NON_CUSTOMER);
                updateSMSStatus($ishikistaaServicesObj, $subreq, $msisdn);
                $ishikistaaServicesObj->closeDB();
        return true;        
        $subscriptionArray = [];
        $subscriptionArray['MSISDN'] = $msisdn;
        $subscriptionArray['_STATUS'] = "SUBSCRIBED";
        $ishikistaaServicesObj->recordData("tbl_qn_subscribers", $subscriptionArray);
        $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
        $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
        $question = $questionData['QUESTION'];
        $questionSMS = FIRST_APPEND . $question . ".";
        $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0,"QUESTION");
        $subscriberInfo = [];
        $subscriberInfo['LAST_QN_ID'] = $nextQnID;
        $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
        $subscriberInfo['ACTIVITY_STATUS'] = 'ACTIVE';
        $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

        if ($smsstatus['isResponse']) {
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            $ishikistaaServicesObj->closeDB();
            return true;
        } else {
            return true;
        }
    } else {//check if active or inactive
        $answer = $sms;
        if (strtolower($sms) === "ondoa") {
            $smsstatus = $ishikistaaLib->interactiveRequest(ONDOA, $moID, 0, "ONDOA_UNSUB_SMS");
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            return true;
        }
        if (strtolower($sms) === "point" || strtolower($sms) === "pointi" || strtolower($sms) === "points") {
            $pointSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], POINT_MSG);
            $pointSMS = str_replace("%msisdn", $msisdn, $pointSMS);
            $smsstatus = $ishikistaaLib->interactiveRequest($pointSMS, $moID, 0, "POINT_MSG");
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            $ishikistaaServicesObj->closeDB();
            return true;
        }

        if (strtolower($sms) === "help" || strtolower($sms) === "mzaada" || strtolower($sms) === "zaidi" || strtolower($sms) === "mzada"||strtolower($sms) === "msada"||strtolower($sms) === "msaada") {
            $smsstatus = $ishikistaaLib->interactiveRequest(HELP_MSG, $moID, 0, "HELP_MESSAGE");
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            $ishikistaaServicesObj->closeDB();
            return true;
        }
        if (strtolower($sms) === "ondoa ishikistaa" || strtolower($sms) === "ondoaishikistaa" || strtolower($sms) === "stop"||strtolower($sms) === "ondoa ishikistaa") {
            $subscriberInfo['ACTIVITY_STATUS'] = "INACTIVE";
            $subscriberInfo['USER_LEVEL'] = "0";
            $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
            $smsstatus = $ishikistaaLib->interactiveRequest(UNSUB_SMS, $moID, 0, "UNSUB_SMS");
            updateSMSStatus($ishikistaaServicesObj, $smsstatus);
            sendRenewal($msisdn, "UNSUB");
            return true;
        }
        if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
            
            if(strtolower($sms)==="staa"||strtolower($sms)==="star"){
              sendRenewal($msisdn, "SUBSCRIPTION");
            //$subSms = $ishikistaaLib->interactiveRequest(SUB_SMS, $moID, $txnID, "SUB_SMS");
//                $welcomSMS = $ishikistaaLib->interactiveRequest(WELCOME_SMS, $moID, 0, "WELCOME_SMS");
//                
//                updateSMSStatus($ishikistaaServicesObj, $welcomSMS);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $questionSMS = FIRST_APPEND . $question . ".";
                $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QUESTION");
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'SUBSCRIBED_QN_SENT';
                    $subscriberInfo['ACTIVITY_STATUS'] = "ACTIVE";
                    $subscriberInfo['REACT_DATE'] = date("Y-m-d H:i:s");
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                if ($smsstatus['isResponse']) {
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
                } else {
                    $ishikistaaServicesObj->closeDB();
                    return true;
                }
                return true;
            


            $subscriberInfo['ACTIVITY_STATUS'] = "ACTIVE";
            $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

             if ($subDetails['CREDIT'] > 0) {//1
                $lastQnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = 0;
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'CREDIT_CHARGING_SUCCESS';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
                $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);

                $balanceCredit = $subDetails['CREDIT'] - 1;

                if ($balanceCredit >= 1) {
                    $qnsms .= "\n KETE " . $balanceCredit . " Buree!\n";
                }

                $qnsms .= $question;
                $smsstatus = $ishikistaaLib->interactiveRequest($qnsms, $moID, 0, "QUESTION");
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'QN_SENT';
                $subscriberInfo['CREDIT'] = $subDetails['CREDIT'] - 1;
                $subscriberInfo['TOTAL_POINTS'] = $totalPoints;
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                $ishikistaaServicesObj->closeDB();
                return true;
            }    


            $data = $ishikistaaLib->sendCharging($moID, 99,FALSE);
            
            if(!$ishikistaaLib->is_json($data['response'])){
                 updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    
                    
                    
                    $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                
                
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                
                
                    $ishikistaaServicesObj->closeDB();
                    
                    
                    return true;
            }
            
            $ackResponse = json_decode($data['response'], true);

            if (!isset($ackResponse['responseParam'])) {
                 updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    
                    
                    
                    $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                
                
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                    
                    
                    $ishikistaaServicesObj->closeDB();
                    return true;
            }
            
            
            if ($data['isResponse']) {//  
                
                if (strpos($data['response'],'"status":"1"')!==false||strpos($data['response'], 'Trivia payment failed') !== false || strpos($data['response'], 'Failed') !== false) {//failed acklowldgement
                     updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    
                    
                    $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                
                
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                    
                    
                    $ishikistaaServicesObj->closeDB();
                    return TRUE;
                    
                }
                
                
                updatePaymentStatus($ishikistaaServicesObj, $data);
                $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                $ishikistaaServicesObj->closeDB();
                return  true;
            } else {
                return true;
            }
            }
            else{
                $activationArray = [];
                $activationArray['MSISDN'] = $msisdn;
                $subreq = $ishikistaaLib->sendSMS(0, $moID, "NON_SUB", NON_CUSTOMER);
                updateSMSStatus($ishikistaaServicesObj, $subreq, $msisdn);
                $ishikistaaServicesObj->recordData('soka_subs_activate', $activationArray);
                $ishikistaaServicesObj->closeDB();
                return true;
                
            }
        } else {///user is active
          
            
            
             if ($subDetails['CREDIT'] > 0) {//1
                $lastQnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastQnID);
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = 0;
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'CREDIT_CHARGING_SUCCESS';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData['QUESTION'];
                $totalPoints = $subDetails['TOTAL_POINTS'] + $points;
                $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);

                $balanceCredit = $subDetails['CREDIT'] - 1;

                if ($balanceCredit >= 1) {
                    $qnsms .= "\n KETE " . $balanceCredit . " Buree!\n";
                }

                $qnsms .= $question;
                $smsstatus = $ishikistaaLib->interactiveRequest($qnsms, $moID, 0, "QUESTION");
                updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                $subscriberInfo = [];
                $subscriberInfo['LAST_QN_ID'] = $nextQnID;
                $subscriberInfo['_STATUS'] = 'QN_SENT';
                $subscriberInfo['CREDIT'] = $subDetails['CREDIT'] - 1;
                $subscriberInfo['TOTAL_POINTS'] = $totalPoints;
                $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                $ishikistaaServicesObj->closeDB();
                return true;
            }
            
            
            
            
            $data = $ishikistaaLib->sendCharging($moID, 99,FALSE);
            
            if(!$ishikistaaLib->is_json($data['response'])){
                 updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, 0, "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    
                    
                    $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                
                
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                    } else {
                        $points = $answerDetails['POINTS'];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                    
                    
                    $ishikistaaServicesObj->closeDB();
                    return true;
            }
            
            $ackResponse = json_decode($data['response'], true);

            if (!isset($ackResponse['responseParam'])) {
                 updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, $data['txnID'], "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return true;
            }
            
            
            
            
            if ($data['isResponse']) {//    
                updatePaymentStatus($ishikistaaServicesObj, $data);
                
                  if (strpos($data['response'],'"status":"1"')!==false||strpos($data['response'], 'Trivia payment failed') !== false || strpos($data['response'], 'Failed') !== false) {//failed acklowldgement
                     updatePaymentStatus($ishikistaaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $ishikistaaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData['QUESTION'];
                    $questionSMS = str_replace("%s", $subDetails['TOTAL_POINTS'], QN_ERROR_MSG);
                    $questionSMS .= "" . $questionData['QUESTION'] . ".";
                    $smsstatus = $ishikistaaLib->interactiveRequest($questionSMS, $moID, $data['txnID'], "QN_ERROR_MSG");
                    updateSMSStatus($ishikistaaServicesObj, $smsstatus);
                    $ishikistaaServicesObj->closeDB();
                    return TRUE;
                    
                }
                
                $lastqnID = $subDetails['LAST_QN_ID'];
                $answerDetails = $ishikistaaServicesObj->getAnswerStatus($lastqnID);
                if (strtolower($answer) == strtolower($answerDetails['ANSWER'])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails['POINTS'] * 2;
                        
                    } else {
                        $points = $answerDetails['POINTS'];
                        
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = 50;
                        
                    } else {
                        $points = 25;
                        
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData['MSISDN'] = $msisdn;
                $answerData['ANSWER'] = $sms;
                $answerData['ANSWER_STATUS'] = $answerStatus;
                $answerData['TXN_ID'] = $data['txnID'];
                $answerData['QN_ID'] = $subDetails['LAST_QN_ID'];
                $answerData['BILLING_STATUS'] = 'SENT_BILLING';
                $answerData['EARNED_POINTS'] = $points;
                $ishikistaaServicesObj->recordData("tbl_qn_answers", $answerData);
                $ishikistaaServicesObj->closeDB();
                return TRUE;
            } else {
                return true;
            }
        }
    }
}

function updateSMSStatus($ishikistaaServicesObj, $smsstatus = []) {
    $smsID = $smsstatus['smsID'];
    $responseCode = json_decode($smsstatus['response'], TRUE);
    $responseReceived = $smsstatus['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] === 0) {
        $msgStatus = "SUCCESS";
        $return = TRUE;
    } else {
        if (isset($responseCode['responseParam']['status'])) {
            $msgStatus = $responseCode['responseParam']['status'];
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
            $msgStatus = 'FAILED';
        }
        $return = FALSE;
    }
    $table = "vas_transaction_trivia";
    $paymentArray = [];
    $paymentArray['RESPONSE_RECEIVED'] = $responseReceived;
    $paymentArray['STATUS_CODE'] = $msgStatus;
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $ishikistaaServicesObj->_update($table, "ID", $txnID, $paymentArray);
    return $return;
}


function sendRenewal($msisdn, $type) {
    $requestArray = [];
    $requestArray['msisdn'] = $msisdn;
    $requestArray['serviceID'] = "ISHIKISTAA";
    
    if ($type === "SUBSCRIPTION") {

        $requestType = "SUBSCRIPTION";
    } else {

        $requestType = "UNSUBSCRIPTION";
    }
    $requestArray['activityType'] = $requestType;
    $jsonRequest = json_encode($requestArray);
    $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($jsonRequest), "Cache-Control: no-cache", "Pragma: no-cache");
    $url = "http://192.168.168.3/TigoRenewalServices/ManageSubscription.php";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
}


