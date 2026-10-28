<?php

/**
 * Description of InteractionController
 * Purpose :  To  process all the consumed messages
 * @author Arun@GreenTelecom
 */
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';
require_once 'MegapromoLib.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class InteractionController {

    var $msisdn, $moID, $sms, $db, $lib, $userAccessToken;

    function __construct($msisdn, $sms, $moID) {
        $this->msisdn = $msisdn;
        $this->sms = $sms;
        $this->moID = $moID;
        $this->db = new db("consumer");
        $this->userAccessToken = $this->userAccessToken();
        $this->lib = new MegapromoLib($this->msisdn, $this->userAccessToken);
    }

    function processMessage() {
        $moID = $this->moID;
        $msisdn = $this->msisdn;
        $text = strtolower(ltrim(rtrim($this->sms)));
        $isExist = $this->getSubscriberDetails();
        if (strpos($text, "ondoa") !== false || strpos($text, "stop") !== false || strpos($text, "acha") !== false || strpos($text, "remove") !== false || strpos($text, "sitaki") !== false || strpos($text, "staki") !== false || strpos($text, "ondowa") !== false) {
            if (!empty($isExist)) {
                $subscriberUpdate = [];
                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $subscriberUpdate['ACTIVITY_STATUS'] = 'INACTIVE';
                $subscriberUpdate['BUNDLE_COUNT'] = 0;
                $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                $this->processMt($moID, 0, $msisdn, "UNSUB", UNSUB);
                $this->closeDb();
                return true;
            } else {
                $this->processMt($moID, 0, $msisdn, "NON_SUB", NON_SUB);
                $this->closeDb();
                return true;
            }
        }



        if (strpos($text, 'win') !== false) {
            $arr = explode('win', $text);
            if (!empty($arr[1])) {
                $noofToken = rtrim(ltrim($arr[1]));
                $result = $this->extract_numbers($noofToken);
                if (!empty($result)) {
                    $noofToken = $result[0];
                }
            }
        }
        if (strpos($text, 'zali') !== false) {
            $arr = explode('zali', $text);
            if (!empty($arr[1])) {
                $noofToken = rtrim(ltrim($arr[1]));
                $result = $this->extract_numbers($noofToken);
                if (!empty($result)) {
                    $noofToken = $result[0];
                }
            }
        }

        if ($text === "help" || $text === "mzada" || $text === "msada" || $text === "msaada") {
            $this->processMt($moID, 0, $msisdn, "HELP", HELP);
            return true;
        }
        if (strpos($text, "ondoa") === true || strpos($text, "stop") === true || strpos($text, "acha") === true || strpos($text, "remove") === true || strpos($text, "sitaki") === true || strpos($text, "staki") === true || strpos($text, "ondowa") === true) {
            if (!empty($isExist)) {
                $subscriberUpdate = [];
                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $subscriberUpdate['ACTIVITY_STATUS'] = 'INACTIVE';
                $subscriberUpdate['BUNDLE_COUNT'] = 0;
                $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                $this->processMt($moID, 0, $msisdn, "UNSUB", UNSUB);
                $this->closeDb();
                return true;
            } else {
                $this->processMt($moID, 0, $msisdn, "NON_SUB", NON_SUB);
                $this->closeDb();
                return true;
            }
        }
        if (strtolower($text) === "testizali" || strtolower($text) === "testi zali" || strtolower($text) === "win" || strtolower($text) === "zali") {
            if (empty($isExist) || $isExist['ACTIVITY_STATUS'] === "INACTIVE") {// no subscriber existing
                if ($isExist['ACTIVITY_STATUS'] === "INACTIVE") {
                    $subscriberUpdate = [];
                    $subscriberUpdate['ACTIVITY_STATUS'] = 'ACTIVE';
                    $subscriberUpdate['_STATUS'] = 'SUBSCRIBED';
                    $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", WELCOME_SMS);
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", SUB_SMS);


                    $transactionID = date("Ymdhis") . $msisdn;
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];
                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    } else {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'SUB_FAILED';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->closeDb();
                        return true;
                    }
                    return true;
                } else {//empty
                    $subscriberInfo['BUNDLE_COUNT'] = 0;
                    $subID = $this->addsubscriber();
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", WELCOME_SMS);
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", SUB_SMS);

                    $transactionID = date("Ymdhis") . $msisdn;
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];
                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    } else {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'SUB_FAILED';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->closeDb();
                        return true;
                    }
                    return true;
                }
            } else {//subscriber already existing
                if ($isExist['BUNDLE_COUNT'] <= 0) {
                    $transactionID = date("Ymdhis") . $msisdn;
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];
                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    } else {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'SUB_FAILED';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->closeDb();
                        return true;
                    }
                    return true;
                } else {//bundle is existing
                    $updateSubscriberInfo = [];
                    $updateSubscriberInfo['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                    $updateSubscriberInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    $this->updatesubscriberInfo($updateSubscriberInfo, $msisdn);
                    $date = date("Y-m-d H:i:s");
                    if (strtotime($isExist['BUNDLE_EXPIRY']) > strtotime($date)) {//bundle is valid
                        $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                        if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                            $draw = [];
                            $draw['PROCESS_STATUS'] = 'ENABLE';
                            $this->setForDraw($draw, 'POSITION', $recordID);
                            $this->closeDb();
                            return true;
                        } else {
                            $winnerResult = $this->checkifWinner($recordID);
                            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                                $smsType = 'LOST';
                                $sms = LOST;
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                $sms = str_replace("%availabletickets", $availableCredit, $sms);
                            } else {//win
                                $smsType = 'WIN';
                                $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                // record to winners
                                $winnerData = [];
                                $winnerData['MSISDN'] = $msisdn;
                                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                                $winnerData['SUBSCRIBER_ID'] = $isExist['ID'];
                                $winnerData['INTERACTION_ID'] = $recordID;
                                $this->recordWinner($winnerData);
                                $reason = 'WINNER PAYOUT';
                                //$this->lib->processPayment($winnerResult['TOTAL_WINNING_AMOUNT'], $reason);
                            }
                            $subscriberUpdate = [];
                            $subscriberUpdate['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                            $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                            $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                            $this->processMt($moID, 0, $msisdn, $smsType, $sms);
                            $this->closeDb();
                            return true;
                        }
                    } else { //bundle expired send for billing
                        $transactionID = date("YmdHis") . $this->msisdn;
                        $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", 300, "PLAY");
                        $istransactionProcessed = $paymentStatus['isResponse'];
                        $responseData = $paymentStatus['response'];
                        $txnID = $paymentStatus['txnID'];
                        if ($istransactionProcessed) {// this will check if we are able to send a request
                            $responseStatus = json_decode($responseData, TRUE);
                            if (strpos($responseData, 'Trivia payment failed') === false) {
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                //record it for winning
                                $subscriberInfo = [];
                                $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');
                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                                $this->updatesubscriberInfo($subscriberInfo, $msisdn);


                                $this->closeDb();
                                return true;
                            } else {
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                //record it for winning
                                $subscriberInfo = [];
                                $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                                $this->updatesubscriberInfo($subscriberInfo, $msisdn);


                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);

                                $this->closeDb();
                                return true;
                            }
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_TIMEOUT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            //record it for winning
                            $subscriberInfo = [];
                            $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                            $this->updatesubscriberInfo($subscriberInfo, $msisdn);

                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                    }
                }
            }
            return true;
        }

        if (strpos($text, 'win') !== false || strtolower($text) === "t" || strpos($text, 'zali') !== false) {/// check if the user send any win related keyword
            if (strtolower($text) === "win" || strtolower($text) === "t" || strtolower($text) === "zali") {
                if (empty($isExist) || $isExist['ACTIVITY_STATUS'] === "INACTIVE") {// no subscriber existing
                    if ($isExist['ACTIVITY_STATUS'] === "INACTIVE") {
                        $subscriberUpdate = [];
                        $subscriberUpdate['ACTIVITY_STATUS'] = 'ACTIVE';
                        $subscriberUpdate['_STATUS'] = 'SUBSCRIBED';
                        $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                        $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                        $transactionID = date("Ymdhis") . $msisdn;
                        $this->processMt($moID, 0, $msisdn, "SUB_SMS", WELCOME_SMS);
                        $this->processMt($moID, 0, $msisdn, "SUB_SMS", SUB_SMS);
                        $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                        $istransactionProcessed = $paymentStatus['isResponse'];
                        $responseData = $paymentStatus['response'];
                        $txnID = $paymentStatus['txnID'];
                        if ($istransactionProcessed) {// this will check if we are able to send a request
                            if (strpos($responseData, 'Trivia payment failed') === false) {
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                $this->closeDb();
                                return true;
                            } else {// this is failed transaction
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                                $this->closeDb();
                                return true;
                            }
                            return true;
                        } else {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'SUB_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                    }
                    ///subscriber doesnot exist
                    $subscriberInfo['BUNDLE_COUNT'] = 0;
                    $subID = $this->addsubscriber();
                    $transactionID = date("Ymdhis") . $msisdn;
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", WELCOME_SMS);
                    $this->processMt($moID, 0, $msisdn, "SUB_SMS", SUB_SMS);
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];
                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    } else {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'SUB_FAILED';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->closeDb();
                        return true;
                    }
                }


                ///subscription exist;
                else {
                    $subID = $isExist['ID'];
                    $transactionID = date("YmdHis") . $msisdn;
                    if ($isExist['BUNDLE_COUNT'] <= 0) {
                        $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                        $istransactionProcessed = $paymentStatus['isResponse'];
                        $responseData = $paymentStatus['response'];
                        $txnID = $paymentStatus['txnID'];
                        if ($istransactionProcessed) {
                            if (strpos($responseData, 'Trivia payment failed') === false) {
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                //wait for callback
                                $this->closeDb();
                                return true;
                            } else {

                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                //wait for callback
                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                                $this->closeDb();
                                return true;
                            }
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_TIMEOUT';
                            $paymentStatus['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            //wait for callback
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                    } else {//bundle is existing
                        $updateSubscriberInfo = [];

                        $updateSubscriberInfo['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                        $updateSubscriberInfo['LAST_INTERCATION'] = date("Y-m-d H:i:s");

                        $this->updatesubscriberInfo($updateSubscriberInfo, $msisdn);

                        $date = date("Y-m-d H:i:s");
                        if (strtotime($isExist['BUNDLE_EXPIRY']) > strtotime($date)) {//bundle is valid
                            $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                            if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                                $draw = [];
                                $draw['PROCESS_STATUS'] = 'ENABLE';
                                $this->setForDraw($draw, 'POSITION', $recordID);
                                $this->closeDb();
                                return true;
                            } else {
                                $winnerResult = $this->checkifWinner($recordID);
                                if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                                    $smsType = 'LOST';
                                    $sms = LOST;
                                    $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                    $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                } else {//win
                                    $smsType = 'WIN';
                                    $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                                    $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                    $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                    // record to winners
                                    $winnerData = [];
                                    $winnerData['MSISDN'] = $msisdn;
                                    $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                                    $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                                    $winnerData['SUBSCRIBER_ID'] = $subID;
                                    $winnerData['INTERACTION_ID'] = $recordID;
                                    $this->recordWinner($winnerData);
                                    $reason = 'WINNER PAYOUT';
                                    //$this->lib->processPayment($winnerResult['TOTAL_WINNING_AMOUNT'], $reason);
                                }
                                $subscriberUpdate = [];
                                $subscriberUpdate['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                                $this->processMt($moID, 0, $msisdn, $smsType, $sms);
                                $this->closeDb();
                                return true;
                            }
                        } else { //bundle expired send for billing
                            $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", 300, "PLAY");
                            $istransactionProcessed = $paymentStatus['isResponse'];
                            $responseData = $paymentStatus['response'];
                            $txnID = $paymentStatus['txnID'];
                            if ($istransactionProcessed) {// this will check if we are able to send a request
                                $responseStatus = json_decode($responseData, TRUE);
                                if (strpos($responseData, 'Trivia payment failed') === false) {
                                    $paymentStatusData = [];
                                    $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                                    $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                    $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                    //record it for winning
                                    $subscriberInfo = [];
                                    $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                                    $this->updatesubscriberInfo($subscriberInfo, $msisdn);


                                    $this->closeDb();
                                    return true;
                                } else {
                                    $paymentStatusData = [];
                                    $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                                    $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                    $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                    //record it for winning


                                    $subscriberInfo = [];
                                    $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                                    $this->updatesubscriberInfo($subscriberInfo, $msisdn);


                                    $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);

                                    $this->closeDb();
                                    return true;
                                }
                            } else {// this is failed transaction
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'ACK_TIMEOUT';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                //record it for winning
                                $subscriberInfo = [];
                                $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                                $this->updatesubscriberInfo($subscriberInfo, $msisdn);

                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                                $this->closeDb();
                                return true;
                            }
                        }
                    }
                }
                $this->closeDb();
                return true;
            } elseif (isset($noofToken)) {
                if ($noofToken === "7" || $noofToken === "30" || $noofToken === "1") {

                    if (empty($isExist)) {
                        $this->processMt($moID, 0, $msisdn, "NON_SUB", NON_SUB);
                        $this->closeDb();
                        return true;
                    }
                    $subscriberInfo = [];
                    if ($isExist['ACTIVITY_STATUS'] === "INACTIVE") {
                        $subscriberInfo['ACTIVITY_STATUS'] = "ACTIVE";
                    }
                    if ($noofToken === "7") {
                        $amount = 1800;
                    } elseif ($noofToken === "30") {
                        $amount = 6000;
                    } else {
                        $amount = 300;
                    }
                    $transactionID = date("Ymdhis") . $msisdn;
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", $amount, "PURCHASE");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];

                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');
                            $this->updatesubscriberInfo($subscriberInfo, $msisdn);
                            $this->closeDb();
                            return true;
                        } else {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            //record it for winning
                            $subscriberInfo = [];
                            $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');

                            $this->updatesubscriberInfo($subscriberInfo, $msisdn);
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                    } else {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'ACK_TIMEOUT';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        //record it for winning
                        $subscriberInfo = [];
                        $subscriberInfo['LAST_INTERCATION'] = date('Y-m-d H:i:s');
                        $this->updatesubscriberInfo($subscriberInfo, $msisdn);
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->closeDb();
                        return true;
                    }
                    $this->closeDb();
                    return true;
                } else {

                    if (empty($isExist)) {
                        $this->processMt($moID, 0, $msisdn, "WRONG_PURCHASE", WRONG_PRICEPOINTS);
                    } else {



                        if ($isExist['BUNDLE_COUNT'] > 0) {
                            $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                            if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                                $draw = [];
                                $draw['PROCESS_STATUS'] = 'ENABLE';
                                $this->setForDraw($draw, 'POSITION', $recordID);
                                $this->closeDb();
                                return true;
                            } else {
                                $winnerResult = $this->checkifWinner($recordID);
                                if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                                    $smsType = 'LOST';
                                    $sms = LOST;
                                    $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                    $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                } else {//win
                                    $smsType = 'WIN';
                                    $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                                    $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                    $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                    // record to winners
                                    $winnerData = [];
                                    $winnerData['MSISDN'] = $msisdn;
                                    $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                                    $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                                    $winnerData['SUBSCRIBER_ID'] = $subID;
                                    $winnerData['INTERACTION_ID'] = $recordID;
                                    $this->recordWinner($winnerData);
                                    $reason = 'WINNER PAYOUT';
                                }
                                $subscriberUpdate = [];
                                $subscriberUpdate['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                                $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                                $this->processMt($moID, 0, $msisdn, $smsType, $sms);
                                $this->closeDb();
                                return true;
                            }
                            return true;
                        }



                        $transactionID = date("YmdHis") . $this->msisdn;
                        $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                        $istransactionProcessed = $paymentStatus['isResponse'];
                        $responseData = $paymentStatus['response'];
                        $txnID = $paymentStatus['txnID'];
                        if ($istransactionProcessed) {// this will check if we are able to send a request
                            if (strpos($responseData, 'Trivia payment failed') === false) {
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                $this->closeDb();
                                return true;
                            } else {// this is failed transaction
                                $paymentStatusData = [];
                                $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                                $paymentStatusData['ACK_RESPONSE'] = $responseData;
                                $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                                $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                                $this->closeDb();
                                return true;
                            }
                            return true;
                        } else {

                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                    }
                }
            } else {// price point not configured or wrong ticket purchase
                if (empty($isExist)) {
                    $this->processMt($moID, 0, $msisdn, "WRONG_PURCHASE", WRONG_PRICEPOINTS);
                } else {

                    //check for kete
                    if ($isExist['BUNDLE_COUNT'] > 0) {
                        $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                        if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                            $draw = [];
                            $draw['PROCESS_STATUS'] = 'ENABLE';
                            $this->setForDraw($draw, 'POSITION', $recordID);
                            $this->closeDb();
                            return true;
                        } else {
                            $winnerResult = $this->checkifWinner($recordID);
                            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                                $smsType = 'LOST';
                                $sms = LOST;
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                $sms = str_replace("%availabletickets", $availableCredit, $sms);
                            } else {//win
                                $smsType = 'WIN';
                                $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                $sms = str_replace("%availabletickets", $availableCredit, $sms);
                                // record to winners
                                $winnerData = [];
                                $winnerData['MSISDN'] = $msisdn;
                                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                                $winnerData['SUBSCRIBER_ID'] = $subID;
                                $winnerData['INTERACTION_ID'] = $recordID;
                                $this->recordWinner($winnerData);
                                $reason = 'WINNER PAYOUT';
                            }
                            $subscriberUpdate = [];
                            $subscriberUpdate['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                            $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                            $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                            $this->processMt($moID, 0, $msisdn, $smsType, $sms);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    }


                    $transactionID = date("YmdHis").$this->msisdn;
                    $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                    $istransactionProcessed = $paymentStatus['isResponse'];
                    $responseData = $paymentStatus['response'];
                    $txnID = $paymentStatus['txnID'];
                    if ($istransactionProcessed) {// this will check if we are able to send a request
                        if (strpos($responseData, 'Trivia payment failed') === false) {
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->closeDb();
                            return true;
                        } else {// this is failed transaction
                            $paymentStatusData = [];
                            $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                            $paymentStatusData['ACK_RESPONSE'] = $responseData;
                            $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                            $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                            $this->closeDb();
                            return true;
                        }
                        return true;
                    } else {

                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->closeDb();
                        return true;
                    }
                }
            }
        } elseif (strtolower($text) === "balance" || strtolower($text) === "t" || strtolower($text) === "ondoa" || strtolower($text) === "kete") {
            if (empty($isExist)) {
                $this->processMt($moID, 0, $msisdn, "NON_SUB", NON_SUB);
                return true;
            } else {
                if (strtolower($text) === "balance" || strtolower($text) === "kete" || strtolower($text) === "check") {
                    $sms = str_replace("%tickets", $isExist['BUNDLE_COUNT'], BALANCE);
                    $this->processMt($moID, 0, $msisdn, "BALANCE", $sms);
                    return true;
                } elseif (strtolower($text) === "ondoa" || strtolower($text) === "stop" || strtolower($text) === "acha" || strtolower($text) === "remove" || strtolower($text) === "acha") {
                    $subscriberUpdate = [];
                    $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    $subscriberUpdate['ACTIVITY_STATUS'] = 'INACTIVE';
                    $subscriberUpdate['BUNDLE_COUNT'] = 0;
                    $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                    $this->processMt($moID, 0, $msisdn, "UNSUB", UNSUB);
                    $this->closeDb();
                    return true;
                } else {/// customer trying to redeem tickets
                    if ($isExist['BUNDLE_COUNT'] <= 0 || strtotime($isExist['BUNDLE_EXPIRY']) < strtotime($date)) {
                        $this->processMt($moID, $txnID, $msisdn, "INVALID_TICKET", TICKET_UNAVAILABLE);
                        return true;
                    } else {//valid ticket available
                        $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                        if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                            $draw = [];
                            $draw['PROCESS_STATUS'] = 'ENABLE';
                            $this->setForDraw($draw, 'POSITION', $recordID);
                            $this->closeDb();
                            return true;
                        } else {
                            $winnerResult = $this->checkifWinner($recordID);
                            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                                $smsType = 'LOST';
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                if ($availableCredit <= 0) {
                                    $availableCredit = 0;
                                }
                                $sms = str_replace("%availabletickets", $availableCredit, LOST);
                            } else {//win
                                $smsType = 'WIN';
                                $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                                $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                                $availableCredit = 0;
                                $sms = str_replace("%availabletickets", $availableCredit, $sms);


                                // record to winners
                                $winnerData = [];
                                $winnerData['MSISDN'] = $this->msisdn;
                                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                                $winnerData['SUBSCRIBER_ID'] = $subID;
                                $winnerData['INTERACTION_ID'] = $recordID;
                                $this->recordWinner($winnerData);
                                $reason = 'WINNER PAYOUT';
                                $subscriberUpdate = [];
                                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                                $subscriberUpdate['BUNDLE_COUNT'] = $availableCredit;
                                //$this->lib->processPayment($winnerResult['TOTAL_WINNING_AMOUNT'], $reason);
                            }
                            $subscriberUpdate = [];
                            $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                            $subscriberUpdate['BUNDLE_COUNT'] = $availableCredit;

                            $this->processMt($moID, $txnID, $msisdn, $smsType, $sms);
                            $this->closeDb();
                            return true;
                        }
                    }
                }
            }
        } else {///customer sending wrong sms
            if (empty($isExist)) {
                $this->processMt($moID, 0, $msisdn, "INVALID", WRONG_PRICEPOINTS);
                $this->closeDb();
            } else {






                if ($isExist['BUNDLE_COUNT'] > 0) {
                    $recordID = $this->recordInteraction($moID, 0,"CREDIT");
                    if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {// to check if it is ready for draw
                        $draw = [];
                        $draw['PROCESS_STATUS'] = 'ENABLE';
                        $this->setForDraw($draw, 'POSITION', $recordID);
                        $this->closeDb();
                        return true;
                    } else {
                        $winnerResult = $this->checkifWinner($recordID);
                        if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                            $smsType = 'LOST';
                            $sms = LOST;
                            $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                            $sms = str_replace("%availabletickets", $availableCredit, $sms);
                        } else {//win
                            $smsType = 'WIN';
                            $sms = str_replace("xxxxx", $winnerResult['TOTAL_WINNING_AMOUNT'], PLAY_WIN);
                            $availableCredit = $isExist['BUNDLE_COUNT'] - 1;
                            $sms = str_replace("%availabletickets", $availableCredit, $sms);
                            // record to winners
                            $winnerData = [];
                            $winnerData['MSISDN'] = $msisdn;
                            $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                            $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                            $winnerData['SUBSCRIBER_ID'] = $subID;
                            $winnerData['INTERACTION_ID'] = $recordID;
                            $this->recordWinner($winnerData);
                            $reason = 'WINNER PAYOUT';
                        }
                        $subscriberUpdate = [];
                        $subscriberUpdate['BUNDLE_COUNT'] = $isExist['BUNDLE_COUNT'] - 1;
                        $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                        $this->updatesubscriberInfo($subscriberUpdate, $msisdn);
                        $this->processMt($moID, 0, $msisdn, $smsType, $sms);
                        $this->closeDb();
                        return true;
                    }
                    return true;
                }















                $transactionID = date("YmdHis") . $this->msisdn;
                $paymentStatus = $this->lib->sendCharging($transactionID, $moID, "AIRTIME", "300", "PLAY");
                $istransactionProcessed = $paymentStatus['isResponse'];
                $responseData = $paymentStatus['response'];
                $txnID = $paymentStatus['txnID'];
                if ($istransactionProcessed) {// this will check if we are able to send a request
                    if (strpos($responseData, 'Trivia payment failed') === false) {
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'REQUEST_SENT';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->closeDb();
                        return true;
                    } else {// this is failed transaction
                        $paymentStatusData = [];
                        $paymentStatusData['STATUS_CODE'] = 'ACK_FAILED';
                        $paymentStatusData['ACK_RESPONSE'] = $responseData;
                        $this->updatesubscriberPaymentInfo($txnID, $paymentStatusData);
                        $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                        $this->closeDb();
                        return true;
                    }
                    return true;
                } else {

                    $this->processMt($moID, $txnID, $msisdn, "ERROR", ERROR);
                    $this->closeDb();
                    return true;
                }
            }
            return true;
        }
    }

/// functions
    function processMt($moID, $txnID, $msisdn, $smsType, $sms) {
        try {
            $connection = new AMQPStreamConnection("localhost", 5672, "megapromo_manage", "manage@CNu8Ut");
            $channel = $connection->channel();
            $channel->exchange_declare("megaPromoExchange", "direct", false, true, false);
            $channel->queue_declare("mt_queue", false, true, false, false);
            $channel->queue_bind('mt_queue', 'megaPromoExchange', 'mt.messages.key');
            $jsonArray = array("msisdn" => $msisdn, "message" => $sms, "moid" => $moID, "smstype" => $smsType, "txnID" => $txnID);
            $jsonMessage = new AMQPMessage(json_encode($jsonArray));
            $channel->basic_publish($jsonMessage, "megaPromoExchange", "mt.messages.key");
            $connection->close();
            $channel->close();
        } catch (Exception $ex) {

            $ex->getMessage();
            echo $ex;
        }
        return true;
    }

    function getSubscriberDetails() {
        $result = $this->db->getSubscriberDetails($this->msisdn);
        return $result;
    }

    function addsubscriber($subscriberInfo = []) {

        $subscriberInfo['MSISDN'] = $this->msisdn;
        $subscriberID = $this->db->_recordData('tbl_promo_subscribers', $subscriberInfo);
        return $subscriberID;
    }

    function updatesubscriberInfo($subscriberData, $msisdn): int {
        $result = $this->db->update('tbl_promo_subscribers', $subscriberData, 'MSISDN', $msisdn);
        return $result;
    }

    function updatesubscriberPaymentInfo($paymentID, $paymentData = array()) {
        $result = $this->db->update('tbl_promo_billing', $paymentData, 'ID', $paymentID);
        return $result;
    }

    function recordPayment($paymentData = []) {

        $id = $this->db->_recordData('tbl_promo_billing', $paymentData);
        return $id;
    }

    function updateSMSStatus($smsID, $data = array()) {
        $result = $this->db->update('tbl_promo_mt', $data, 'ID', $smsID);
        return $result;
    }

    function recordInteraction($moID, $billingID,$type): int {
        $interactionArray = [];
        $interactionArray['MSISDN'] = $this->msisdn;
        $interactionArray['MO_ID'] = $moID;
        $interactionArray['BILLING_ID'] = $billingID;
        $interactionArray['BILLING_STATUS'] = 'SUCCESS';
         $interactionArray['PLAY_TYPE'] = $type;
	
        $id = $this->db->_recordData('tbl_promo_entries', $interactionArray);
        if (empty($id) || $id <= 0) {
            return 0;
        } else {
            return $id;
        }
    }

    function recordwinner($winnerData) {
        $this->db->_recordData('tbl_promo_fixed_winners', $winnerData);
    }

    function checkifWinner($interactionID) {
        $result = $this->db->selectWinningpostion($interactionID);
        if (empty($result)) {
            return array();
        } else {


            return $result;
        }
    }

    function recordBundleLogs($bundleInf = []) {

        $id = $this->db->_recordData('tbl_promo_bundle_logs', $bundleInf);
        return $id;
    }

    function getDrawDetails() {

        $result = $this->db->getDrawDetails();
        return $result;
    }

    function getWinnerID($winnerID) {

        $result = $this->db->selectWinner($winnerID);
        return $result;
    }

    function setForDraw($drawArray, $where, $whereValue) {

        $this->db->update('tbl_promo_draw', $drawArray, $where, $whereValue);
    }

    function getBundleDetails($amount) {

        $bundleDetails = $this->db->getBundleDetails($amount);
        return $bundleDetails;
    }

    function userAccessToken() {

        $userAccessToken = $this->db->userAccessToken();
        return $userAccessToken;
    }

    function closeDb() {
        unset($this->msisdn);
        $this->db->close_db();
        unset($this->db);
    }

    function ProcessData($txnID, $moID) {
        $interactionDetails = $this->db->fetchInteractionDetails($txnID);
        $subDetail = $this->getSubscriberDetails();
        $subID = $subDetail['ID'];
        
        
        $recordID = $interactionDetails['ID'];
        if ($recordID == 600000 || $recordID == 1500000 || $recordID == 2500000 || $recordID == 20000000) {
            $draw = [];
            $draw['PROCESS_STATUS'] = 'ENABLE';
            $this->setForDraw($draw, 'POSITION', $recordID);
            $this->closeDb();
            return true;
        } else {
            $winnerResult = $this->checkifWinner($recordID);
            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                $smsType = 'LOST';
                $sms = LOST;
                if ($availableCredit <= 0) {
                    $availableCredit = 0;
                }
                $sms = str_replace("%availabletickets", $availableCredit, LOST);
            } else {//win
                $smsType = 'WIN';
                $sms = str_replace("XXXX", $winnerResult['TOTAL_WINNING_AMOUNT'], WIN);
                if ($availableCredit <= 0) {
                    $availableCredit = 0;
                }
                $sms = str_replace("%availabletickets", $availableCredit, WIN);
                //record to winners

                $winnerData = [];
                $winnerData['MSISDN'] = $this->msisdn;
                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                $winnerData['SUBSCRIBER_ID'] = $subID;
                $winnerData['INTERACTION_ID'] = $recordID;
                //$this->lib->processPayment($winnerResult['TOTAL_WINNING_AMOUNT'], "WINNER_PAYOUT");
                $this->recordWinner($winnerData);
            }
            $this->processMt($moID, $txnID, $this->msisdn, $smsType, $sms);

            return true;
        }
        return true;
    }

    function extract_numbers($string) {
        preg_match_all('/([\d]+)/', $string, $match);

        return $match[0];
    }

}
