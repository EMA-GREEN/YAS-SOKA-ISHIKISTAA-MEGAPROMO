<?php
error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 .php'; */
require_once __DIR__ . "/vendor/autoload.php";

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require_once "config.php";
require_once "SokaLib.php";
require_once "SokaServices.php";
require_once "PredictAndWin.php";

try {
    $connection = new AMQPStreamConnection("localhost", 5672, "trivia_manage", "manage@CNu8Ut");
    $channel = $connection->channel();
} catch (Exception $ex) {
    $ex->getMessage();
    echo $ex;
}

$callback = function ($data) {
    $msg = json_decode($data->body, true);
    $msisdn = $msg["msisdn"];
    $message = $msg["message"];
    $smsType = $msg["requesttype"];
    $requestID = $msg["requestid"];
    $referenceID = $msg["referenceid"];
    $status = $msg["status"];
    $logID = $msg["id"];
    $deliveryState = processMessage($msisdn, $message, $logID, $requestID, $smsType, $referenceID, $status);
    if ($deliveryState) {
        $data->delivery_info["channel"]->basic_ack(
            $data->delivery_info["delivery_tag"]
        ); //acknowledge the message
    } else {
        $data->delivery_info["channel"]->basic_nack(
            $data->delivery_info["delivery_tag"]
        ); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_mo_router", false, true, false, false);
$channel->queue_bind(
    "tigo_soka_mo_router",
    "triviaExchange",
    "tigosoka.messages.key"
);
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_mo_router", "", false, false, false, false, $callback);

while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $message, $logID, $requestID, $requestType, $referenceID, $status) {
    //$moID = $logID;
    $sokaServicesObj = new SokaServices("consumer");
    $sms = trim($message);
    $sms = addslashes($sms);
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, $requestID, $referenceID); //create soka libe and define amount
    $isStaff = $sokaLib->staffSeries();

    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);

    $moData = [];
    $moData["MSISDN"] = $msisdn;
    $moData["TEXT"] = $sms;
    $moTable = "trivia_mo_" . date("Ymd");
    $moID = $sokaServicesObj->recordData($moTable, $moData);

    if($subDetails['CUSTOMER_TYPE']==="BOOM_CUSTOMER"){
       $paymentType = "BOOM_BILLING";
    }else{
        $paymentType = "NORMAL_BILLING";
    }

    if ($moID <= 0) {
        echo $sms;
        echo "Error | " . $sms . "|" . $msisdn;
        return false;
    }

    if (PredictAndWin::handleMo($msisdn, $sms, $moID, $sokaLib, $sokaServicesObj, $subDetails, $paymentType)) {
        $sokaServicesObj->closeDB();
        return true;
    }

    if ($requestType === "QUERY_POINTS") {
        //
        $points = $subDetails["TOTAL_POINTS"];
        $pointSMS = str_replace("%s", $points, POINT_MSG);
        $pointMSG = str_replace("%msisdn", $msisdn, $pointSMS);
        $sokaLib->QueryPoints($requestID, $pointSMS, $pointMSG);
        $sokaServicesObj->closeDB();
        return true;
    }
    if ($requestType === "SUBSCRIPTION") {
        ///
        $sokaServicesObj->confirmMpushSubsInitActivation($msisdn);
        if (!$isStaff) {
            sendRenewal($msisdn, "SUBSCRIPTION");
        }
        if (empty($subDetails)) {
            //if not an active customer or non existing customer
            /// if number is boom type
            $subscriptionArray = [];
            $userSms = $message;

            if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                $subscriptionArray["CUSTOMER_TYPE"] = "BOOM_CUSTOMER";
                $subscriptionArray['BOOM_CREDIT'] = 1;
            }

            $subscriptionArray["MSISDN"] = $msisdn;
            $subscriptionArray["_STATUS"] = "SUBSCRIBED";
            $subscriptionArray["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            if ($isStaff) {
                $subscriptionArray["DND_STATUS"] = "ON";
            }

            $sokaServicesObj->recordData("tbl_qn_subscribers", $subscriptionArray); 
            //send first sms

            // $welcomesms = $sokaLib->sendSMS(0, $moID, "WELCOME_SMS", WELCOME_SMS);
            // $termsms = $sokaLib->sendSMS(0, $moID, "WELCOME_TERMS", WELCOME_SMS_2);

            //            updateSMSStatus($sokaServicesObj, $welcomesms, $msisdn);
            //            updateSMSStatus($sokaServicesObj, $termsms, $msisdn);

            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
            $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
            $question = $questionData["QUESTION"];
            $firstAppend = str_replace("%s", 0, FIRST_APPEND);
            $questionSMS = $firstAppend . $question .". Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions";
            $smsstatus = $sokaLib->interactiveRequest($questionSMS, $moID, 0, "QUESTION");
            $subscriberInfo = [];
            $subscriberInfo["LAST_QN_ID"] = $nextQnID;
            $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");

            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
            if ($smsstatus["isResponse"]) {
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                $sokaServicesObj->closeDB();
            }

            if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                //notify subscriber on offered credit
                $boom_credit_notify_sms = BOOM_CREDIT_NOTIFY_SMS;
                $notify_sms_response = $sokaLib->interactiveRequest($boom_credit_notify_sms, 0, 0, "BOOM_CREDIT_NOTIFY");
                // $notify_sms_response = $sokaLib->sendSMS(0, 0, "BOOM_CREDIT_NOTIFY", $boom_credit_notify_sms);
                updateSMSStatus($sokaServicesObj, $notify_sms_response, $msisdn);
            }

            return true;
        } elseif ($subDetails["ACTIVITY_STATUS"] === "ACTIVE") {
            //send A question
            $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
            $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
            $question = $questionData["QUESTION"];
            $questionSMS = $question . ".";
            $smsstatus = $sokaLib->interactiveRequest(
                $questionSMS,
                $moID,
                0,
                "QUESTION"
            );

            $subscriberInfo = [];
            $subscriberInfo["LAST_QN_ID"] = $nextQnID;
            $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            $sokaServicesObj->_update(
                "tbl_qn_subscribers",
                "MSISDN",
                $msisdn,
                $subscriberInfo
            );
            if ($smsstatus["isResponse"]) {
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            } else {
                $sokaServicesObj->closeDB();

                return true;
            }
            $sokaServicesObj->closeDB();
            return true;
        }
        if (empty($subDetails) || $subDetails["ACTIVITY_STATUS"] === "INACTIVE") {
            if (empty($subDetails)) {
                $subscriptionArray = [];
                $userSms = $message;
                if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                    $subscriptionArray["CUSTOMER_TYPE"] = "BOOM_CUSTOMER";
                    $subscriptionArray['BOOM_CREDIT'] = 1;
                }

                $subscriptionArray["MSISDN"] = $msisdn;
                $subscriptionArray["_STATUS"] = "SUBSCRIBED";
                if ($isStaff) {
                    $subscriptionArray["DND_STATUS"] = "ON";
                }
                $sokaServicesObj->recordData(
                    "tbl_qn_subscribers",
                    $subscriptionArray
                );
                //send welcome sms

                //                $welcomesms = $sokaLib->sendSMS(0, $moID, "WELCOME_SMS", WELCOME_SMS);
                //                $termsms = $sokaLib->sendSMS(0, $moID, "WELCOME_TERMS", WELCOME_SMS_2);
                //                updateSMSStatus($sokaServicesObj, $termsms, $msisdn);
                //                updateSMSStatus($sokaServicesObj, $welcomesms, $msisdn);
                //

                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $firstAppend = str_replace("%s", 0, FIRST_APPEND);
                $questionSMS =
                    $firstAppend .
                    $question .
                    ". Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QUESTION"
                );
                $subscriberInfo = [];
                $subscriberInfo["LAST_QN_ID"] = $nextQnID;
                $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
                $sokaServicesObj->_update(
                    "tbl_qn_subscribers",
                    "MSISDN",
                    $msisdn,
                    $subscriberInfo
                );

                if ($smsstatus["isResponse"]) {
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                } else {
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                }

                if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                    //notify subscriber on offered credit
                    $boom_credit_notify_sms = BOOM_CREDIT_NOTIFY_SMS;
                    // $notify_sms_response = $sokaLib->sendSMS(0, 0, "BOOM_CREDIT_NOTIFY", $boom_credit_notify_sms);
                    $notify_sms_response = $sokaLib->interactiveRequest($boom_credit_notify_sms, 0, 0, "BOOM_CREDIT_NOTIFY");
                    updateSMSStatus($sokaServicesObj, $notify_sms_response, $msisdn);
                }

                $sokaServicesObj->closeDB();
                return true;
            } else {
                //re activate
                /*if (!$isStaff) {
                    sendRenewal($msisdn, "SUBSCRIPTION");
                }*/
                $subscriberInfo = [];

                $userSms = $message;

                if(strpos($userSms,"-")!==false && strtok($userSms,"-")==="BOOM_CUSTOMER"){
                   $subscriberInfo['CUSTOMER_TYPE'] = "BOOM_CUSTOMER";
                   $subscriberInfo['BOOM_CREDIT'] = 1;
                }else{
                    $subscriberInfo['CUSTOMER_TYPE'] = "";
                }

                $subscriberInfo["ACTIVITY_STATUS"] = "ACTIVE";
                $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
                $subscriberInfo["REACT_DATE"] = date("Y-m-d H:i:s");
                $sokaServicesObj->update(
                    "tbl_qn_subscribers",
                    "MSISDN",
                    $msisdn,
                    $subscriberInfo
                );

                //                $welcomesms = $sokaLib->sendSMS(0, $moID, "WELCOME_SMS", WELCOME_SMS);
                //                $termsms = $sokaLib->sendSMS(0, $moID, "WELCOME_TERMS", WELCOME_SMS_2);
                //                updateSMSStatus($sokaServicesObj, $termsms, $msisdn);
                //                updateSMSStatus($sokaServicesObj, $welcomesms, $msisdn);

                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $firstAppend = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    FIRST_APPEND
                );
                $questionSMS =
                    $firstAppend .
                    $question .
                    ". Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions";

                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QUESTION"
                );

                $subscriberInfo["LAST_QN_ID"] = $nextQnID;
                $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
                $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
                $sokaServicesObj->_update(
                    "tbl_qn_subscribers",
                    "MSISDN",
                    $msisdn,
                    $subscriberInfo
                );

                if ($smsstatus["isResponse"]) {
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                } else {
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                }

                if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                    //notify subscriber on offered credit
                    $boom_credit_notify_sms = BOOM_CREDIT_NOTIFY_SMS;
                    // $notify_sms_response = $sokaLib->sendSMS(0, 0, "BOOM_CREDIT_NOTIFY", $boom_credit_notify_sms);
                    $notify_sms_response = $sokaLib->interactiveRequest($boom_credit_notify_sms, 0, 0, "BOOM_CREDIT_NOTIFY");
                    updateSMSStatus($sokaServicesObj, $notify_sms_response, $msisdn);
                }
                
                $sokaServicesObj->closeDB();
                return true;
            }
        }
    } elseif ($requestType === "UN_SUBSCRIPTION") {
        sendRenewal($msisdn, "UNSUBSCRIPTION");
        if (
            empty($subDetails) ||
            $subDetails["ACTIVITY_STATUS"] === "INACTIVE"
        ) {
            $smsstatus = $sokaLib->interactiveRequest(
                NON_CUSTOMER,
                $moID,
                0,
                "NON_SUBSCRIBER"
            );

            if ($smsstatus["isResponse"]) {
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            } else {
                updateSMSStatus($sokaServicesObj, $smsstatus . $msisdn);
                $sokaServicesObj->closeDB();
                return true;
            }
            $sokaServicesObj->closeDB();
            return true;
        } else {
            $unsubArray = [];

            $unsubArray["MSISDN"] = $msisdn;
            $unsubArray["CHANNEL"] = "SMS";

            $sokaServicesObj->recordData("tbl_unsubs", $unsubArray);

            $subscriberInfo["ACTIVITY_STATUS"] = "INACTIVE";
            $subscriberInfo["USER_LEVEL"] = "0";
            $subscriberInfo["APPEND_ID"] = "1";
            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            $sokaServicesObj->_update(
                "tbl_qn_subscribers",
                "MSISDN",
                $msisdn,
                $subscriberInfo
            );
            $sokaServicesObj->closeDB();
            return true;
        }
    }

    if(strripos($status, NO_SUB_OPEN_QN_NOTIF) !== false || strripos($status, NO_SUB_INT_REQ_NOTIF) !== false){
        sendOpenQuestion($msisdn, $sokaServicesObj, $sokaLib);
        $sokaServicesObj->closeDB();
        return true;
    }

    if(isPointPurchaseKeyword($sms, POINT_PURCHASE_MAP) || similiarPointPurchaseKeyword($sms)){
        //do SUBSCRIPTION logic as above and then continue with other logics after this segment
        if (empty($subDetails)) {
            //if not an active customer or non existing customer
            /// if number is boom type
            $subscriptionArray = [];
            $userSms = $message;
            if (strpos($userSms, "-") !== false && strtok($userSms, "-") === "BOOM_CUSTOMER") {
                $subscriptionArray["CUSTOMER_TYPE"] = "BOOM_CUSTOMER";
            }
            $subscriptionArray["MSISDN"] = $msisdn;
            $subscriptionArray["_STATUS"] = "SUBSCRIBED";
            $subscriptionArray["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            if ($isStaff) {
                $subscriptionArray["DND_STATUS"] = "ON";
            }

            $sokaServicesObj->recordData(
                "tbl_qn_subscribers",
                $subscriptionArray
            );
            
            $termsms = "Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions";
            $smsstatus = $sokaLib->interactiveRequest(
                $termsms,
                $moID,
                0,
                "TERMS"
            );
    
            if ($smsstatus["isResponse"]) {
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            }
        }else if(!empty($subDetails) && $subDetails["ACTIVITY_STATUS"] === "INACTIVE"){
            $subscriberInfo = [];
            $subscriberInfo["ACTIVITY_STATUS"] = "ACTIVE";
            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            $subscriberInfo["REACT_DATE"] = date("Y-m-d H:i:s");
            $sokaServicesObj->update(
                "tbl_qn_subscribers",
                "MSISDN",
                $msisdn,
                $subscriberInfo
            );

            $termsms = "Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions";

            $smsstatus = $sokaLib->interactiveRequest(
                $termsms,
                $moID,
                0,
                "TERMS"
            );

            if ($smsstatus["isResponse"]) {
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            }
        }
    }

    if (empty($subDetails)) {
        //if not an active customer or non existing customer
        $subscriptionArray = [];
        $subscriptionArray["MSISDN"] = $msisdn;
        $subscriptionArray["_STATUS"] = "SUBSCRIBED";
        $subscriptionArray["LAST_INTERACTION"] = date("Y-m-d H:i:s");
        $sokaServicesObj->recordData("tbl_qn_subscribers", $subscriptionArray);
        $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
        $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
        $question = $questionData["QUESTION"];
        $questionSMS = FIRST_APPEND . $question . ".";
        $smsstatus = $sokaLib->interactiveRequest(
            $questionSMS,
            $moID,
            0,
            "QUESTION"
        );
        $subscriberInfo = [];
        $subscriberInfo["LAST_QN_ID"] = $nextQnID;
        $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
        $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
        $sokaServicesObj->_update(
            "tbl_qn_subscribers",
            "MSISDN",
            $msisdn,
            $subscriberInfo
        );

        //record it to suscription activation

        // $subreq = $sokaLib->sendSMS(0, $moID, "INACTIVE_SUB", INACTIVE_SUB);
        $subreq = $sokaLib->interactiveRequest(INACTIVE_SUB, $moID, 0, "INACTIVE_SUB");
        updateSMSStatus($sokaServicesObj, $subreq, $msisdn);

        $activationArray = [];
        $activationArray["MSISDN"] = $msisdn;

        $sokaServicesObj->recordData("soka_subs_activate", $activationArray);

        if ($smsstatus["isResponse"]) {
            updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            $sokaServicesObj->closeDB();
            return true;
        } else {
            updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            $sokaServicesObj->closeDB();
            return true;
        }
    } else {
        //check if active or inactive
        $answer = $sms;
        if (strtolower($sms) === "ondoa") {
            $smsstatus = $sokaLib->interactiveRequest(
                ONDOA,
                $moID,
                0,
                "ONDOA_UNSUB_SMS"
            );
            updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            return true;
        }
        if (
            strtolower($sms) === "pointi" ||
            strtolower($sms) === "points" ||
            strtolower($sms) === "point"
        ) {
            $pointSMS = str_replace(
                "%s",
                $subDetails["TOTAL_POINTS"],
                POINT_MSG
            );
            $pointSMS = str_replace("%msisdn", $msisdn, $pointSMS);
            $smsstatus = $sokaLib->interactiveRequest(
                $pointSMS,
                $moID,
                0,
                "POINT_MSG"
            );
            updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

            //$smsstatus = $sokaLib->sendSms(0, $moID, "POINT_MESSAGE", $pointSMS);
            //updateContentSMSStatus($sokaServicesObj, $smsstatus);
            $sokaServicesObj->closeDB();
            return true;
        }

        if (
            strtolower($sms) === "help" ||
            strtolower($sms) === "mzaada" ||
            strtolower($sms) === "zaidi" ||
            strtolower($sms) === "mzada" ||
            strtolower($sms) === "msaada" ||
            strtolower($sms) === "msada"
        ) {
            $smsstatus = $sokaLib->interactiveRequest(
                HELP_MSG,
                $moID,
                0,
                "HELP_MESSAGE"
            );
            updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
            $sokaServicesObj->closeDB();
            return true;
        }
        if (
            strtolower($sms) === "ondoa soka" ||
            strtolower($sms) === "ondoasoka" ||
            strtolower($sms) === "stop"
        ) {
            sendRenewal($msisdn, "UNSUBSCRIPTION");
            $subscriberInfo["ACTIVITY_STATUS"] = "INACTIVE";
            $subscriberInfo["USER_LEVEL"] = "0";
            $subscriberInfo["APPEND_ID"] = "1";
            $sokaServicesObj->_update(
                "tbl_qn_subscribers",
                "MSISDN",
                $msisdn,
                $subscriberInfo
            );
            $sokaServicesObj->closeDB();
            return true;
        }
        if ($subDetails["ACTIVITY_STATUS"] === "INACTIVE") {
            if (strtolower($sms) === "soka") {
                if (!$isStaff) {
                    sendRenewal($msisdn, "SUBSCRIPTION");
                }
                $subscriberInfo["REACT_DATE"] = date("Y-m-d H:i:s");
                $subscriberInfo["ACTIVITY_STATUS"] = "ACTIVE";
                $sokaServicesObj->_update(
                    "tbl_qn_subscribers",
                    "MSISDN",
                    $msisdn,
                    $subscriberInfo
                );
                //                $welcomSMS = $sokaLib->interactiveRequest(WELCOME_SMS, $moID, 0, "WELCOME_SMS");
                //                $termsms = $sokaLib->sendSMS(0, $moID, "WELCOME_TERMS", WELCOME_SMS_2);
                //                updateSMSStatus($sokaServicesObj, $termsms, $msisdn);
                //
                //                updateSMSStatus($sokaServicesObj, $welcomSMS, $msisdn);

                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = FIRST_APPEND . $question . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QUESTION"
                );
                $subscriberInfo = [];
                $subscriberInfo["LAST_QN_ID"] = $nextQnID;
                $subscriberInfo["_STATUS"] = "SUBSCRIBED_QN_SENT";
                $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
                $sokaServicesObj->_update(
                    "tbl_qn_subscribers",
                    "MSISDN",
                    $msisdn,
                    $subscriberInfo
                );
                if ($smsstatus["isResponse"]) {
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                } else {
                    $sokaServicesObj->closeDB();
                    return true;
                }
                return true;
            } else {
                $activationArray = [];
                $activationArray["MSISDN"] = $msisdn;

                $subreq = $sokaLib->interactiveRequest(INACTIVE_SUB, $moID, 0, "INACTIVE_SUB");
        
                updateSMSStatus($sokaServicesObj, $subreq, $msisdn);

                $sokaServicesObj->recordData(
                    "soka_subs_activate",
                    $activationArray
                );
                $sokaServicesObj->closeDB();
                return true;
            }

            $subscriberInfo["ACTIVITY_STATUS"] = "ACTIVE";
            $subscriberInfo["REACT_DATE"] = date("Y-m-d H:i:s");
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

            if ($subDetails["CREDIT"] > 0 || $subDetails["BOOM_CREDIT"] > 0) {//1

                if($subDetails['BOOM_CREDIT'] > 0){
                    $amount = 0;
                    $credittype = "BOOM";
                    $data = $sokaLib->sendCharging($moID, "BOOM", FALSE, "PLAY", $paymentType);
                    updatePaymentStatus($sokaServicesObj, $data);
                }else{
                    $credittype = "CREDIT";
                }

                $lastQnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastQnID);

                if (strtolower($answer) == strtolower($answerDetails["ANSWER"])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }

                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = 0;
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = $credittype."_CHARGING_SUCCESS";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $totalPoints = $subDetails["TOTAL_POINTS"] + $points;
                $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);

                $subscriberInfo = [];

                if($credittype ==="BOOM"){
                    $balanceCredit = $subDetails['BOOM_CREDIT'] - 1;
                    $subscriberInfo['BOOM_CREDIT'] = $subDetails['BOOM_CREDIT'] - 1;
                }else{
                    $balanceCredit = $subDetails['CREDIT'] - 1;
                    $subscriberInfo['CREDIT'] = $subDetails['CREDIT'] - 1;
                }

                if ($balanceCredit >= 1) {
                    $qnsms .= " KETE " . $balanceCredit . " Buree!";
                }

                $qnsms .= $question;
                $smsstatus = $sokaLib->interactiveRequest($qnsms, $moID, 0, "QUESTION");
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                
                $subscriberInfo["LAST_QN_ID"] = $nextQnID;
                $subscriberInfo["_STATUS"] = "QN_SENT";
                $subscriberInfo["CREDIT"] = $subDetails["CREDIT"] - 1;
                $subscriberInfo["TOTAL_POINTS"] = $totalPoints;
                $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
                $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                $sokaServicesObj->closeDB();
                return true;
            }

            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

            if(isPointPurchaseKeyword($sms, POINT_PURCHASE_MAP) || similiarPointPurchaseKeyword($sms)){
                if(isPointPurchaseKeyword($sms, POINT_PURCHASE_MAP)){
                    $transactionRequest = "PURCHASE";
                    $amount = getPointPurchaseAmount($sms, POINT_PURCHASE_MAP);
                }else{
                    // $pointpurchasemenu = $sokaLib->sendSMS(0, $moID, "POINT_PURCHASE_MENU", POINT_PURCHASE_MENU);
                    $pointpurchasemenu = $sokaLib->interactiveRequest(POINT_PURCHASE_MENU, $moID, 0, "POINT_PURCHASE_MENU");
                    updateSMSStatus($sokaServicesObj, $pointpurchasemenu, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                }
            } else {
                $transactionRequest = "PLAY";
                $amount = 99;
            }

            $data = $sokaLib->sendCharging($moID, $amount, false, $transactionRequest, $paymentType);

            if (!$sokaLib->is_json($data["response"])) {
                //check if the response is json
                updatePaymentStatus($sokaServicesObj, $data);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);

                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);

                $sokaServicesObj->closeDB();
                return true;
            }

            $ackResponse = json_decode($data["response"], true);

            if (!isset($ackResponse["responseParam"])) {
                updatePaymentStatus($sokaServicesObj, $data);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);

                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);

                $sokaServicesObj->closeDB();
                return true;
            }

            if ($data["isResponse"]) {
                //
                if (
                    strpos($data["response"], '"status":"1"') !== false ||
                    strpos($data["response"], "Trivia payment failed") !==
                        false ||
                    strpos($data["response"], "Failed") !== false
                ) {
                    //failed acklowldgement
                    updatePaymentStatus($sokaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData["QUESTION"];
                    $questionSMS = str_replace(
                        "%s",
                        $subDetails["TOTAL_POINTS"],
                        QN_ERROR_MSG
                    );
                    $questionSMS .= "" . $questionData["QUESTION"] . ".";
                    $smsstatus = $sokaLib->interactiveRequest(
                        $questionSMS,
                        $moID,
                        $data["txnID"],
                        "QN_ERROR_MSG"
                    );
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

                    $lastqnID = $subDetails["LAST_QN_ID"];
                    $answerDetails = $sokaServicesObj->getAnswerStatus(
                        $lastqnID
                    );

                    if (
                        strtolower($answer) ==
                        strtolower($answerDetails["ANSWER"])
                    ) {
                        if (
                            date("H") >= GOLDEN_HR_START &&
                            date("H") <= GOLDEN_HR_END
                        ) {
                            $points = $answerDetails["POINTS"] * 2;
                        } else {
                            $points = $answerDetails["POINTS"];
                        }
                        $answerStatus = "CORRECT";
                    } else {
                        if (
                            date("H") >= GOLDEN_HR_START &&
                            date("H") <= GOLDEN_HR_END
                        ) {
                            $points = 50;
                        } else {
                            $points = 25;
                        }
                        $answerStatus = "WRONG";
                    }
                    $answerData = [];
                    $answerData["MSISDN"] = $msisdn;
                    $answerData["ANSWER"] = $sms;
                    $answerData["ANSWER_STATUS"] = $answerStatus;
                    $answerData["TXN_ID"] = $data["txnID"];
                    $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                    $answerData["BILLING_STATUS"] = "SENT_BILLING";
                    $answerData["EARNED_POINTS"] = $points;
                    $sokaServicesObj->recordData("tbl_qn_answers", $answerData);

                    $sokaServicesObj->closeDB();
                    return true;
                }
                //if no ack error

                updatePaymentStatus($sokaServicesObj, $data);
                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);
                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
            } else {
                // transaction is timeout
                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);
                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
                $where = "ID";
                $value = $data["txnID"];
                $updateArray["TXN_STATUS"] = $data["response"];
                $table = "vas_transaction_trivia";
                $sokaServicesObj->update($table, $where, $value, $updateArray);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    $data["txnID"],
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                $sokaServicesObj->closeDB();
                return true;
            }
        }

        //user active
        else {
            ///user is active

            if ($subDetails["CREDIT"] > 0 || $subDetails["BOOM_CREDIT"] > 0) {

                if($subDetails['BOOM_CREDIT'] > 0){
                    $credittype = "BOOM";
                    $data = $sokaLib->sendCharging($moID, "BOOM", FALSE, "PLAY", $paymentType);
                    updatePaymentStatus($sokaServicesObj, $data);
                }else{
                    $credittype = "CREDIT";
                }

                $lastQnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastQnID);
                if (strtolower($answer) == strtolower($answerDetails["ANSWER"])) {
                    if (date("H") >= GOLDEN_HR_START && date("H") <= GOLDEN_HR_END) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
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
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = 0;
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = $credittype."_CHARGING_SUCCESS";

                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];

                $totalPoints = $subDetails["TOTAL_POINTS"] + $points;
                $qnsms = str_replace("%s", $totalPoints, POINT_APPEND);

                $subscriberInfo = [];

                if($credittype ==="BOOM"){   
                    $balanceCredit = $subDetails['BOOM_CREDIT'] - 1;
                    $subscriberInfo['BOOM_CREDIT'] = $subDetails['BOOM_CREDIT'] - 1;
                }else{
                    $balanceCredit = $subDetails['CREDIT'] - 1;
                    $subscriberInfo['CREDIT'] = $subDetails['CREDIT'] - 1;
                }

                if ($balanceCredit >= 1) {
                    $qnsms .= "KETE " . $balanceCredit . " Buree!";
                }

                $qnsms .= $question;
                $smsstatus = $sokaLib->interactiveRequest($qnsms, $moID, 0, "QUESTION");
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                
                $subscriberInfo["LAST_QN_ID"] = $nextQnID;
                $subscriberInfo["_STATUS"] = "QN_SENT";
                $subscriberInfo["CREDIT"] = $subDetails["CREDIT"] - 1;
                $subscriberInfo["TOTAL_POINTS"] = $totalPoints;
                $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
                $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);
                $sokaServicesObj->closeDB();
                return true;
            }

            $rewards = $sokaServicesObj->getRewards();

            if (!empty($rewards)) {
                $subscriberCurrentLevel = $subDetails["USER_LEVEL"];
                $subscriberNextLeveL = $subscriberCurrentLevel + 1;
                $isSublevel = $sokaServicesObj->getLevelDetails($subscriberNextLeveL);
            }

            $subscriberInfo["LAST_INTERACTION"] = date("Y-m-d H:i:s");
            $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

            if(isPointPurchaseKeyword($sms, POINT_PURCHASE_MAP) || similiarPointPurchaseKeyword($sms)){
                if(isPointPurchaseKeyword($sms, POINT_PURCHASE_MAP)){
                    $transactionRequest = "PURCHASE";
                    $amount = getPointPurchaseAmount($sms, POINT_PURCHASE_MAP); 
                }else{
                    // $pointpurchasemenu = $sokaLib->sendSMS(0, $moID, "POINT_PURCHASE_MENU", POINT_PURCHASE_MENU);
                    $pointpurchasemenu = $sokaLib->interactiveRequest(POINT_PURCHASE_MENU, $moID, 0, "POINT_PURCHASE_MENU");
                    updateSMSStatus($sokaServicesObj, $pointpurchasemenu, $msisdn);
                    $sokaServicesObj->closeDB();
                    return true;
                }
            } else {
                $transactionRequest = "PLAY";
                $amount = 99;
            }

            $data = $sokaLib->sendCharging($moID, $amount, false, $transactionRequest, $paymentType);

            if (!$sokaLib->is_json($data["response"])) {
                //check if the response is json
                updatePaymentStatus($sokaServicesObj, $data);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);

                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);

                //record to retry
                $errorData = [];
                $errorData["MSISDN"] = $msisdn;
                $errorData["MO_ID"] = $moID;
                $errorData["TXN_ID"] = $data["txnID"];
                $errorData["TEXT"] = $sms;
                $errorData["ANSWER_STATUS"] = $answerStatus;
                $errorData["EARNED_POINTS"] = $points;
                $errorData["COUNT"] = 1;
                $errorData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $errorData["REFERENCE_ID"] = $referenceID;
                $errorData["MO_DATE"] = date("Y-m-d H:i:s");
                $errorData["NEXT_RETRY"] = date(
                    "Y-m-d H:i:s",
                    strtotime("+30 minutes")
                );
                $errorData["RETRY_TYPE"] = "ACK_ERROR";
                $sokaServicesObj->recordData("tbl_mo_retry", $errorData);

                $sokaServicesObj->closeDB();
                return true;
            }

            $ackResponse = json_decode($data["response"], true);

            if (!isset($ackResponse["responseParam"])) {
                updatePaymentStatus($sokaServicesObj, $data);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    0,
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);

                $errorData = [];
                $errorData["MSISDN"] = $msisdn;
                $errorData["MO_ID"] = $moID;
                $errorData["TXN_ID"] = $data["txnID"];
                $errorData["TEXT"] = $sms;
                $errorData["ANSWER_STATUS"] = $answerStatus;
                $errorData["EARNED_POINTS"] = $points;
                $errorData["COUNT"] = 1;
                $errorData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $errorData["REFERENCE_ID"] = $referenceID;
                $errorData["MO_DATE"] = date("Y-m-d H:i:s");
                $errorData["NEXT_RETRY"] = date(
                    "Y-m-d H:i:s",
                    strtotime("+30 minutes")
                );
                $errorData["RETRY_TYPE"] = "ACK_ERROR";
                $sokaServicesObj->recordData("tbl_mo_retry", $errorData);

                $sokaServicesObj->closeDB();
                return true;
            }

            if ($data["isResponse"]) {
                //
                if (
                    strpos($data["response"], '"status":"1"') !== false ||
                    strpos($data["response"], "Trivia payment failed") !==
                        false ||
                    strpos($data["response"], "Failed") !== false
                ) {
                    //Ack error
                    updatePaymentStatus($sokaServicesObj, $data);
                    $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                    $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                    $question = $questionData["QUESTION"];
                    $questionSMS = str_replace(
                        "%s",
                        $subDetails["TOTAL_POINTS"],
                        QN_ERROR_MSG
                    );
                    $questionSMS .= "" . $questionData["QUESTION"] . ".";
                    $smsstatus = $sokaLib->interactiveRequest(
                        $questionSMS,
                        $moID,
                        $data["txnID"],
                        "QN_ERROR_MSG"
                    );
                    updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                    $sokaServicesObj->closeDB();

                    $errorData = [];
                    $errorData["MSISDN"] = $msisdn;
                    $errorData["MO_ID"] = $moID;
                    $errorData["TXN_ID"] = $data["txnID"];
                    $errorData["TEXT"] = $sms;
                    $errorData["ANSWER_STATUS"] = $answerStatus;
                    $errorData["EARNED_POINTS"] = $points;
                    $errorData["COUNT"] = 1;
                    $errorData["QN_ID"] = $subDetails["LAST_QN_ID"];
                    $errorData["REFERENCE_ID"] = $referenceID;
                    $errorData["MO_DATE"] = date("Y-m-d H:i:s");
                    $errorData["NEXT_RETRY"] = date(
                        "Y-m-d H:i:s",
                        strtotime("+30 minutes")
                    );
                    $errorData["RETRY_TYPE"] = "ACK_ERROR";
                    $sokaServicesObj->recordData("tbl_mo_retry", $errorData);

                    return true;
                }

                updatePaymentStatus($sokaServicesObj, $data);
                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);
                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                        if (!empty($isSublevel)) {
                            $points += $isSublevel["LEVEL_VALUE"];
                        }
                    } else {
                        $points = $answerDetails["POINTS"];
                        if (!empty($isSublevel)) {
                            $points += $isSublevel["LEVEL_VALUE"];
                        }
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                        if (!empty($isSublevel)) {
                            $points += $isSublevel["LEVEL_VALUE"];
                        }
                    } else {
                        $points = 25;
                        if (!empty($isSublevel)) {
                            $points += $isSublevel["LEVEL_VALUE"];
                        }
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
                $sokaServicesObj->closeDB();
                return true;
            } else {
                $lastqnID = $subDetails["LAST_QN_ID"];
                $answerDetails = $sokaServicesObj->getAnswerStatus($lastqnID);
                if (
                    strtolower($answer) == strtolower($answerDetails["ANSWER"])
                ) {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = $answerDetails["POINTS"] * 2;
                    } else {
                        $points = $answerDetails["POINTS"];
                    }
                    $answerStatus = "CORRECT";
                } else {
                    if (
                        date("H") >= GOLDEN_HR_START &&
                        date("H") <= GOLDEN_HR_END
                    ) {
                        $points = 50;
                    } else {
                        $points = 25;
                    }
                    $answerStatus = "WRONG";
                }
                $answerData = [];
                $answerData["MSISDN"] = $msisdn;
                $answerData["ANSWER"] = $sms;
                $answerData["ANSWER_STATUS"] = $answerStatus;
                $answerData["TXN_ID"] = $data["txnID"];
                $answerData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $answerData["BILLING_STATUS"] = "SENT_BILLING";
                $answerData["EARNED_POINTS"] = $points;
                $sokaServicesObj->recordData("tbl_qn_answers", $answerData);
                $where = "ID";
                $value = $data["txnID"];
                $updateArray["TXN_STATUS"] = $data["response"];
                $table = "vas_transaction_trivia";
                $sokaServicesObj->update($table, $where, $value, $updateArray);
                $nextQnID = mt_rand(MIN_QN_ID, MAX_QN_ID);
                $questionData = $sokaServicesObj->getQuestion($nextQnID); //fetch question
                $question = $questionData["QUESTION"];
                $questionSMS = str_replace(
                    "%s",
                    $subDetails["TOTAL_POINTS"],
                    QN_ERROR_MSG
                );
                $questionSMS .= "" . $questionData["QUESTION"] . ".";
                $smsstatus = $sokaLib->interactiveRequest(
                    $questionSMS,
                    $moID,
                    $data["txnID"],
                    "QN_ERROR_MSG"
                );
                updateSMSStatus($sokaServicesObj, $smsstatus, $msisdn);
                $sokaServicesObj->closeDB();

                $errorData = [];
                $errorData["MSISDN"] = $msisdn;
                $errorData["MO_ID"] = $moID;
                $errorData["TXN_ID"] = $data["txnID"];
                $errorData["TEXT"] = $sms;
                $errorData["ANSWER_STATUS"] = $answerStatus;
                $errorData["EARNED_POINTS"] = $points;
                $errorData["COUNT"] = 1;
                $errorData["QN_ID"] = $subDetails["LAST_QN_ID"];
                $errorData["REFERENCE_ID"] = $referenceID;
                $errorData["MO_DATE"] = date("Y-m-d H:i:s");
                $errorData["NEXT_RETRY"] = date(
                    "Y-m-d H:i:s",
                    strtotime("+30 minutes")
                );
                $errorData["RETRY_TYPE"] = "TIME_OUT";
                $sokaServicesObj->recordData("tbl_mo_retry", $errorData);

                return true;
            }
        }
    }
}

function sendOpenQuestion($msisdn, $sokaServicesObj, $sokaLib){
    $subDetails = $sokaServicesObj->fetchLastQuestion($msisdn);

    $totalPoints  = $subDetails['TOTAL_POINTS'];

    $question = $subDetails['QUESTION'];
    $sms  = OPEN_QN_CONTENT." ".$question;
    $qnsms = str_replace("%msisdn", $msisdn, $sms);
    $qnsms = str_replace("%s", $totalPoints, $qnsms);
    $sms = $sokaLib->convert(utf8_encode($qnsms));
    $smsstatus = $sokaLib->sendopenQuestion($sms);

    $subscriberInfo = [];
    $subscriberInfo['_STATUS'] = 'QN_SENT';
        $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberInfo);

    if ($smsstatus['isResponse']) {
        updateOpenQnSMSStatus($sokaServicesObj, $smsstatus);
    }
}

function updateOpenQnSMSStatus($sokaServicesObj, $smsstatus = []) {
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

function updateContentSMS($sokaServicesObj, $smsstatus = [])
{
    $smsID = $smsstatus["smsID"];
    $responseCode = json_decode($smsstatus["response"], true);
    $responseReceived = $smsstatus["response"];
    if (isset($responseCode["code"]) && $responseCode["code"] == "SUCCESS") {
        $msgStatus = "SUCCESS";
    } else {
        if (isset($responseCode["code"])) {
            $msgStatus = $responseCode["code"];
        } else {
            $msgStatus = "FAILED";
        }
    }
    $sokaServicesObj->updateSMSStatus(
        $smsID,
        $msgStatus,
        $responseReceived,
        $msisdn
    );
    return true;
}

function updateSMSStatus($sokaServicesObj, $smsstatus = [], $msisdn){
    $smsID = $smsstatus["smsID"];
    $responseCode = json_decode($smsstatus["response"], true);
    $responseReceived = $smsstatus["response"];
    if (
        isset($responseCode["responseParam"]["status"]) &&
        $responseCode["responseParam"]["status"] === 0
    ) {
        $msgStatus = "SUCCESS";
        $return = true;
    } else {
        if (isset($responseCode["responseParam"]["status"])) {
            $msgStatus = $responseCode["responseParam"]["status"];
        } else {
            $msgStatus = "FAILED";
        }
        $return = true;
    }
    if (strpos($responseReceived, "0: Accepted for delivery") !== false) {
        $msgStatus = "SUCCESS";
    }

    if ($msgStatus !== "SUCCESS") {
        ///send it to reprocess table
        $reprocessArray = [];
        $reprocessArray["SMS_ID"] = $smsstatus["smsID"];
        $reprocessArray["MSISDN"] = $msisdn;
        $reprocessArray["RETRY_TIME"] = date(
            "Y-m-d H:i:s",
            strtotime("+5 seconds")
        );
        $sokaServicesObj->recordData("sms_reprocess", $reprocessArray);
    }

    $table = "trivia_interaction_mt_logs";
    $smsArray = [];
    $smsArray["RESPONSE_TIME"] = date("Y-m-d H:i:s");
    $smsArray["RESPONSE_RECEIVED"] = $responseReceived;
    $smsArray["STATUS_CODE"] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus["smsID"], $smsArray);
    return $return;
}

function updatePaymentStatus($sokaServicesObj, $data = [])
{
    $txnID = $data["txnID"];
    $responseCode = json_decode($data["response"], true);
    $responseReceived = $data["response"];
    if (
        isset($responseCode["responseParam"]["status"]) &&
        $responseCode["responseParam"]["status"] == 0
    ) {
        $msgStatus = "ACKNOWLEDGED";
        $return = true;
    } else {
        if (isset($responseCode["responseParam"]["statusCode"])) {
            $msgStatus = $responseCode["responseParam"]["statusCode"];
        } else {
            $msgStatus = "FAILED";
        }
        $return = false;
    }
    $table = "vas_transaction_trivia";
    $paymentArray = [];
    $paymentArray["RESPONSE_RECEIVED"] = $msgStatus;
    $paymentArray["TXN_STATUS"] = $msgStatus;
    $paymentArray["RESP_TIME"] = date("Y-m-d H:i:s");
    $sokaServicesObj->_update($table, "ID", $txnID, $paymentArray);
    return $return;
}

function isPointPurchaseKeyword($input, $point_purchase_map) {
    $input = strtoupper(trim($input));
    return array_key_exists($input, $point_purchase_map);
}

function similiarPointPurchaseKeyword($input) {
    $stringValue = trim(strval($input));
    return (bool) preg_match('/^[pP]\d+/', $stringValue);
}

function getPointPurchaseAmount($input, $point_purchase_map) {
    $input = strtoupper(trim($input));
    if (array_key_exists($input, $point_purchase_map)) {
        return $point_purchase_map[$input];
    } else {
        return 0;
    }
}

function sendRenewal($msisdn, $type){

    $data = subsData($msisdn, $type);
    logSubsData($data);

    $requestArray = [];
    $requestArray["msisdn"] = $msisdn;
    $requestArray["serviceID"] = "SOKA";

    if ($type === "SUBSCRIPTION") {
        $requestType = "SUBSCRIPTION";
    } else {
        $requestType = "UNSUBSCRIPTION";
    }
    $requestArray["activityType"] = $requestType;
    $jsonRequest = json_encode($requestArray);
    $headers = [
        "Method:POST",
        "Content-Type: application/json",
        "Content-length: " . strlen($jsonRequest),
        "Cache-Control: no-cache",
        "Pragma: no-cache",
    ];

    $urls = [
        "http://192.168.168.10/TigoRenewalServices/ManageSubscription.php",
        "http://102.218.75.35:7979/api/subs-manage/notification",
    ];

    foreach ($urls as $url) {
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

        $data = subsDataResp($msisdn, $response);
        logSubsData($data);

        curl_close($ch);
    }
}

function subsData($msisdn, $type){
    $subData = [];
    $subData['MSISDN'] = $msisdn;
    $subData['REQUEST_TYPE'] = $type;
    return json_encode($subData);
}

function subsDataResp($msisdn, $response){
    $subData = [];
    $subData['MSISDN'] = $msisdn;
    $subData['RESPONSE'] = $response;
    return json_encode($subData);
}

function logSubsData($data){
    $time = date("Y-m-d H:i:s");
    $data = $time." ".$data;
    $logFile = date("Ymd") . ".txt";
    file_put_contents("logs/subscriptions/SendRenewalSubRequest-" . $logFile, $data . " \n", FILE_APPEND);
}
