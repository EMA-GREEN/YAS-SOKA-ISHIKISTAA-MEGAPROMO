<?php

namespace MegaPromo\MoInteractionController;

require_once 'TigoMegaPromoDatabaseServices.php';
require_once 'MegaPromoLib.php';

use MegaPromo\DatabaseServices AS DatabaseServices;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;

class TigoMegaPromoMoInteractionController {

    var $msisdn, $moID, $moText, $db, $lib, $userAccessToken, $requestType, $noofToken, $appConfig, $isStaff, $dbfile;

    function __construct($msisdn, $mosms, $moID) {
        $this->moID = $moID;
        $this->moText = ltrim(rtrim(strtolower($mosms)));
        $this->msisdn = $msisdn;
        $this->lib = new MegaPromoLibrary\MegaPromoLib($this->msisdn);
        $this->config = $this->lib->loadfile('keywords.config');
        $this->dbfile = $this->lib->loadfile('db.config');
        $this->appConfig = $this->lib->loadfile('app.config');
        $this->isStaff = $this->lib->staffSeries();
        $this->db = new DatabaseServices\TigoMeaPromoDatabaseServices($this->dbfile['PUBLISHER_USER'], $this->dbfile['DB_PASSWORD'], $this->dbfile['DB_NAME'], $this->dbfile['DB_HOST']);
    }

    function processRetryMessage() {
        $text = $this->moText;
        $moID = $this->moID;
        $msisdn = $this->msisdn;
        $result = $this->requestType();
        $subDetails = $this->db->getSubscriberDetails($this->msisdn);
        $subInfo = $this->db->getSubscriberInformation($this->msisdn);
        if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || empty($subDetails)) {
            return true;
        } else {
            $requestID = "RMPR" . $this->moID . date("YmdHis");
            $amount = $this->appConfig['DIRECT_PLAY_AMOUNT'];
            $responseData = $this->lib->sendCharging($this->moID, $requestID, $amount, "AIRTIME", "RETRY");
            $paymentArray = [];
            $txnID = $responseData['txnID'];
            $paymentArray['ACK_RESPONSE'] = $responseData['response'];
            $this->updatePaymentStatus($responseData['txnID'], $paymentArray);

            if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                $result = $this->db->getMessageTemplates("ERROR");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                $this->db->__close();
                return true;
            }


            $ackResponse = json_decode($responseData['response'], true);

            if (!isset($ackResponse['responseParam'])) {
                $result = $this->db->getMessageTemplates("ERROR");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                $this->db->__close();
                return true;
            }

            if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                $result = $this->db->getMessageTemplates("ERROR");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
            }
            if (!$responseData['isResponse']) {//if timeout
                $result = $this->db->getMessageTemplates("ERROR");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
            }
            $this->db->__close();
            return true;
        }
    }

    function processMessage() {
        $text = $this->moText;
        $moID = $this->moID;
        $msisdn = $this->msisdn;
        $result = $this->requestType();
        $subDetails = $this->db->getSubscriberDetails($this->msisdn);
        $subInfo = $this->db->getSubscriberInformation($this->msisdn);       

        if ($this->requestType === "UNSUB") {//check if the request is for unsubscription
            if (empty($subDetails)) {
                $result = $this->db->getMessageTemplates("NON_SUB");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
                $this->db->__close();
                return true;
            } else {//do unsubscribe from the service
                $this->unsubscribe($subDetails);
                $this->db->__close();
                return true;
            }
        } elseif ($this->requestType === "PLAY" || $this->requestType === "SUBSCRIBE") {// if the request is to play
            $this->play($subDetails, $subInfo);
            $this->db->__close();
            return true;
        } elseif ($this->requestType === "PURCHASE") {//if the request is to purhcase tickets
            if (empty($subDetails)) {//if subscription not found
                if ($this->appConfig['NONSUB_PURCHASE']) {//allow nonsubscriber to purchase
                    //add to subscriber table
                    $subscriberArray = [];
                    $subscriberArray['MSISDN'] = $this->msisdn;
                    $subscriberArray['_STATUS'] = "SUBSCRIBED";
                    $subscriberArray['ACTIVITY_STATUS'] = 'ACTIVE';
                    $subscriberArray['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    $subscriberArray['LOST_LEVEL_ID'] = 1;
                    if ($this->isStaff) {
                        $subscriberArray['DND_STATUS'] = 'ON';
                        $subscriberArray['ENTICEMENT_STATUS'] = 'OFF';
                    }
                    $this->addSubscribers($subscriberArray);

                    $this->sendRenewal($this->msisdn, "SUBSCRIPTION");

                    //add to subscriber details table 
                    $subscriberDetailsArray = [];
                    $subscriberDetailsArray['MSISDN'] = $this->msisdn;
                    $this->db->__insert("subscriber_details", $subscriberDetailsArray); // add data to subscriber table
                    $welcome = $this->db->getMessageTemplates("WELCOME_SMS");
                    $templateID = $welcome['ID'];
                    $sms = $welcome['MESSAGE'];
                    $smsType = $welcome['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
                    $subsms = $this->db->getMessageTemplates("SUB_SMS");
                    $templateID = $subsms['ID'];
                    $sms = $subsms['MESSAGE'];
                    $smsType = $subsms['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
                    $billingEnabled = FALSE;
                    $pricepoints = explode(",", $this->config['pricepoints']);
                    foreach ($pricepoints as $key => $value) {
                        $price = $pricepoints[$key];
                        if (strpos($this->noofToken, $price) !== false) {//if the configured pricepoint is found in the request
                            $billingEnabled = TRUE;
                        }
                    }
                    if ($billingEnabled) {//billing is enabled
                        $subscriberUpdate = [];
                        if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                            $subscriberUpdate['ACTIVITY_STATUS'] = 'ACTIVE';
                            $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                        }
                        $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                        $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
                        $subscriberInfoUpdate = [];
                        $subscriberInfoUpdate['UNSUB_DATE'] = date("Y-m-d H:i:s");
                        $subscriberInfoUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                        $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberInfoUpdate);
                        $requestID = "MPR" . $this->moID . date("YmdHis");
                        if (!isset($this->config['amount_' . $this->noofToken])) {//if the amount is not configured
                            $requestID = "MPR" . $this->moID . date("YmdHis");
                            $amount = $this->appConfig['DIRECT_PLAY_AMOUNT'];
                            $responseData = $this->lib->sendCharging($this->moID, $requestID, $amount, "AIRTIME", "PLAY");
                            $paymentArray = [];
                            $txnID = $responseData['txnID'];
                            $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                            $this->updatePaymentStatus($txnID, $paymentArray);

                            if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                                $result = $this->db->getMessageTemplates("ERROR");
                                $templateID = $result['ID'];
                                $sms = $result['MESSAGE'];
                                $smsType = $result['SMS_TYPE'];
                                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                                $this->db->__close();
                                return true;
                            }


                            $ackResponse = json_decode($responseData['response'], true);

                            if (!isset($ackResponse['responseParam'])) {
                                $result = $this->db->getMessageTemplates("ERROR");
                                $templateID = $result['ID'];
                                $sms = $result['MESSAGE'];
                                $smsType = $result['SMS_TYPE'];
                                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                                $this->db->__close();
                                return true;
                            }

                            if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                                $result = $this->db->getMessageTemplates("ERROR");
                                $templateID = $result['ID'];
                                $sms = $result['MESSAGE'];
                                $smsType = $result['SMS_TYPE'];
                                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            }
                            if (!$responseData['isResponse']) {//if timeout
                                $result = $this->db->getMessageTemplates("ERROR");
                                $templateID = $result['ID'];
                                $sms = $result['MESSAGE'];
                                $smsType = $result['SMS_TYPE'];
                                $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            }

                            $this->db->__close();
                            return true;
                        } else {
                            $amount = $this->config['amount_' . $this->noofToken];
                            $reqType = "PURCHASE";
                        }
                        $responseData = $this->lib->sendCharging($moID, $requestID, $amount, "AIRTIME", $reqType);
                        $paymentArray = [];
                        $txnID = $responseData['txnID'];
                        $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                        $this->updatePaymentStatus($txnID, $paymentArray);

                        if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            $this->db->__close();
                            return true;
                        }


                        $ackResponse = json_decode($responseData['response'], true);

                        if (!isset($ackResponse['responseParam'])) {
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            $this->db->__close();
                            return true;
                        }


                        if (strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        }
                        if (!$responseData['isResponse']) {//if timeout
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        }

                        $this->db->__close();
                        return true;
                    } else {
                        $requestID = "MPR" . $this->moID . date("YmdHis");
                        $amount = $this->appConfig['DIRECT_PLAY_AMOUNT'];
                        $responseData = $this->lib->sendCharging($this->moID, $requestID, $amount, "AIRTIME", "PLAY");
                        $paymentArray = [];
                        $txnID = $responseData['txnID'];
                        $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                        $this->updatePaymentStatus($txnID, $paymentArray);

                        if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            $this->db->__close();
                            return true;
                        }


                        $ackResponse = json_decode($responseData['response'], true);

                        if (!isset($ackResponse['responseParam'])) {
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                            $this->db->__close();
                            return true;
                        }

                        if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        }
                        if (!$responseData['isResponse']) {//if timeout
                            $result = $this->db->getMessageTemplates("ERROR");
                            $templateID = $result['ID'];
                            $sms = $result['MESSAGE'];
                            $smsType = $result['SMS_TYPE'];
                            $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        }
                        $this->db->__close();
                        return true;
                    }
                } else {
                    $result = $this->db->getMessageTemplates("NON_SUB");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
                }
                $this->db->__close();
                return true;
            } else {//subscriber is existing
                $billingEnabled = FALSE;
                $pricepoints = explode(",", $this->config['pricepoints']);
                foreach ($pricepoints as $key => $value) {
                    $price = $pricepoints[$key];
                    if (strpos($this->noofToken, $price) !== false) {//if the configured pricepoint is found in the request
                        $billingEnabled = TRUE;
                    }
                }
                if ($billingEnabled) {//billing is enabled
                    $subscriberUpdate = [];
                    if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                        $subscriberUpdate['ACTIVITY_STATUS'] = 'ACTIVE';
                        $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                    }
                    $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);

                    $subscriberInfoUpdate = [];
                    $subscriberInfoUpdate['UNSUB_DATE'] = date("Y-m-d H:i:s");
                    $subscriberInfoUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                    $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberInfoUpdate);

                    $requestID = "MPR" . $this->moID . date("YmdHis");
                    if (!isset($this->config['amount_' . $this->noofToken])) {//if the amount is not configured
                        $this->play($subDetails, $subInfo);
                        return true;
                    } else {
                        $amount = $this->config['amount_' . $this->noofToken];
                        $reqType = "PURCHASE";
                    }
                    $responseData = $this->lib->sendCharging($moID, $requestID, $amount, "AIRTIME", $reqType);
                    $paymentArray = [];
                    $txnID = $responseData['txnID'];
                    $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                    $this->updatePaymentStatus($txnID, $paymentArray);

                    if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                        $result = $this->db->getMessageTemplates("ERROR");
                        $templateID = $result['ID'];
                        $sms = $result['MESSAGE'];
                        $smsType = $result['SMS_TYPE'];
                        $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        $this->db->__close();
                        return true;
                    }


                    $ackResponse = json_decode($responseData['response'], true);

                    if (!isset($ackResponse['responseParam'])) {
                        $result = $this->db->getMessageTemplates("ERROR");
                        $templateID = $result['ID'];
                        $sms = $result['MESSAGE'];
                        $smsType = $result['SMS_TYPE'];
                        $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                        $this->db->__close();
                        return true;
                    }


                    if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                        $result = $this->db->getMessageTemplates("ERROR");
                        $templateID = $result['ID'];
                        $sms = $result['MESSAGE'];
                        $smsType = $result['SMS_TYPE'];
                        $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    }
                    if (!$responseData['isResponse']) {//if timeout
                        $result = $this->db->getMessageTemplates("ERROR");
                        $templateID = $result['ID'];
                        $sms = $result['MESSAGE'];
                        $smsType = $result['SMS_TYPE'];
                        $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    }

                    $this->db->__close();
                    return true;
                } else {
                    $subscriberUpdate = [];
                    $this->play($subDetails, $subInfo);
                    $this->db->__close();
                    return true;
                }
            }
        } elseif ($this->requestType === "BALANCECHECK") {
            if (empty($subDetails)) {
                $result = $this->db->getMessageTemplates("NON_SUB");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
            } else {
                $result = $this->db->getMessageTemplates("BALANCE");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $sms = str_replace("%tickets", $subDetails['BUNDLE_COUNT'], $sms);
                $smsType = $result['SMS_TYPE'];
                $subscriberUpdate = [];
                if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                    $subscriberUpdate['ACTIVITY_STATUS'] = "ACTIVE";
                    $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                }

                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);

                $subscriberInfoUpdate = [];
                $subscriberInfoUpdate['UNSUB_DATE'] = date("Y-m-d H:i:s");
                $subscriberInfoUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberInfoUpdate);
            }
            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
            $this->db->__close();
            return true;
        } elseif ($this->requestType === "SUBSCRIBE") {///subscription
            if (empty($subDetails)) {//user subscribe to the service
                $this->play($subDetails, $subInfo);
                $this->db->__close();
                return true;
            } else {
                $this->play($subDetails, $subInfo);
                $this->db->__close();
                return true;
            }
        } elseif ($this->requestType === "HELP") {
            $result = $this->db->getMessageTemplates("HELP");
            $templateID = $result['ID'];
            $sms = $result['MESSAGE'];
            $smsType = $result['SMS_TYPE'];
            if (!empty($subDetails)) {
                $subscriberUpdate = [];
                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
                //
                $subscriberDetailsArrayUpdate = [];
                $subscriberDetailsArrayUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                $subscriberDetailsArrayUpdate['OTHER_INTERACTION_COUNT'] = $subInfo['OTHER_INTERACTION_COUNT'] + 1;
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberDetailsArrayUpdate);
            }
            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
            $this->db->__close();
            return true;
        } else {//wrongkeyword
            if (empty($subDetails)) {
                if ($this->appConfig['WRONGKEYWORD_SUB']) {//allow subscribing on wrong keyword
                    $this->play($subDetails, $subInfo);
                } else {
                    $result = $this->db->getMessageTemplates("NON_SUB");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
                }
                $this->db->__close();
                return true;
            } else {
                $this->play($subDetails, $subInfo);

                $this->db->__close();
                return true;
            }
        }
    }

    function addSubscribers($data = []) {
        $returnID = $this->db->__insert("tbl_promo_subscribers", $data);
        return $returnID;
    }

    function requestType() {
        $unsub = explode(",", $this->config['unsub']);
        $play = explode(",", $this->config['play']);
        $subscribe = explode(",", $this->config['subscribe']);
        $purchase = explode(",", $this->config['purchase']);
        $help = explode(",", $this->config['help']);
        $kete = explode(",", $this->config['balance']);

        foreach ($unsub as $key => $value) {

            $keyword = $unsub[$key];
            if (strpos($this->moText, $keyword) !== false) {

                $this->requestType = "UNSUB";
                return $this->requestType;
            }
        }
        foreach ($kete as $key => $value) {

            $keyword = $kete[$key];
            if (strpos($this->moText, $keyword) !== false) {
                $arr = explode($keyword, $this->moText);
                if (!empty($arr[1])) {
                    $result = $this->lib->extract_numbers($arr[1]);
                    if (!empty($result)) {
                        $this->noofToken = $result[0];
                        $this->requestType = "PURCHASE";
                        return $this->requestType;
                    }
                }
                $this->requestType = "BALANCECHECK";
                return $this->requestType;
            }
        }
        foreach ($play as $key => $value) {
            $keyword = $play[$key];
            if (strpos($this->moText, $keyword) !== false) {
                $arr = explode($keyword, $this->moText);
                if (!empty($arr[1])) {
                    $result = $this->lib->extract_numbers($arr[1]);
                    if (!empty($result)) {
                        $this->noofToken = $result[0];
                        $this->requestType = "PURCHASE";
                        return $this->requestType;
                    }
                }
                $this->requestType = "PLAY";
                return $this->requestType;
            }
        }
        foreach ($help as $key => $value) {
            $keyword = $help[$key];
            if (strpos($this->moText, $keyword) !== false) {
                $this->requestType = "HELP";
                return $this->requestType;
            }
        }
        foreach ($subscribe as $key => $value) {
            $keyword = $subscribe[$key];
            if (strpos($this->moText, $keyword) !== false) {
                $this->requestType = "SUBSCRIBE";
                return $this->requestType;
            }
        }
        foreach ($purchase as $key => $value) {
            $keyword = $purchase[$key];
            if (strpos($this->moText, $keyword) !== false) {
                $this->requestType = "PURCHASE";
                return $this->requestType;
            }
        }
    }

    function processMT($moID, $templateID, $billingID, $msisdn, $smsType, $sms) {
        $smsstatus = $this->lib->sendSMS($moID, $templateID, $billingID, $sms, $smsType);
        $smsID = $smsstatus['smsID'];
        $responseCode = $smsstatus['response'];

        if (strpos($responseCode, "Accepted") !== false) {
            $msgStatus = "SUCCESS";
            $data['RESPONSE_RECEIVED'] = $responseCode;
            $data['STATUS_CODE'] = "SUCCESS";
            $result = $this->db->__update('tbl_promo_mt_logs', 'ID', $smsID, $data);
        } else {
            $msgStatus = "FAILED";
            $data['STATUS_CODE'] = $msgStatus;
            $data['RESPONSE_RECEIVED'] = $responseCode;
            $result = $this->db->__update('tbl_promo_mt_logs', 'ID', $smsID, $data);
        }
        return true;
    }

    function processData($moID, $subDetails = [], $subInfo = [], $type) {
        $recordID = $this->recordInteraction($moID, 0, $type);

        $availableTickets = ($subDetails['BUNDLE_COUNT'] + $subDetails['CREDIT']) - 1;
        if ($availableTickets <= 0) {

            $availableTickets = 0;
        }

        $subscriberDetailsArrayUpdate = [];
        //if ($recordID % 650005 === 0 || $recordID % 1600006 === 0 || $recordID % 2700007 === 0 || $recordID % 20000000 === 0) {// to check if it is ready for draw
        if ($recordID % 1200000 === 0 || $recordID % 3000000 === 0 || $recordID % 5000000 === 0) {// to check if it is ready for draw
            $draw = [];
            $draw['PROCESS_STATUS'] = 'ENABLE';
            $this->setForDraw($draw, 'POSITION', $recordID);

            return true;
        } else {
            $winnerResult = $this->checkifWinner($recordID);
            $subscriberUpdate = [];
            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                if ($type === "RENEWAL_CREDIT") {
                    $subscriberDetailsArrayUpdate['CREDIT_USED'] = $subInfo['CREDIT_USED'] + 1;
                } else {
                    $subscriberDetailsArrayUpdate['CREDIT_PLAY_COUNT'] = $subInfo['CREDIT_PLAY_COUNT'] + 1;
                    $subscriberDetailsArrayUpdate['TOTAL_USED'] = $subInfo['TOTAL_USED'] + 1;
                }

                $subscriberDetailsArrayUpdate['LOST_COUNT'] = $subInfo['LOST_COUNT'] + 1;
                $subscriberDetailsArrayUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberDetailsArrayUpdate);
                if (!$this->appConfig['LOST_STRING_APPEND']) {
                    $lostID = "LOST_1";
                } else {
                    if ($subDetails['LOST_LEVEL_ID'] >= 10) {
                        $subscriberUpdate['LOST_LEVEL_ID'] = 1;
                        if ($subDetails['LOST_LEVEL_ID'] === 10) {
                            $lostID = "LOST_10";
                        } else {

                            $lostID = "LOST_1";
                        }
                    } else {
                        $subscriberUpdate['LOST_LEVEL_ID'] = $subDetails['LOST_LEVEL_ID'] + 1;
                        $lostID = "LOST_" . $subDetails['LOST_LEVEL_ID'];
                    }
                }
                $result = $this->db->getMessageTemplates($lostID);
                $templateID = $result['ID'];
                $sms = str_replace("%availabletickets", $availableTickets, $result['MESSAGE']);
                $sms = str_replace("%tickets", 1, $sms);
                $smsType = $result['SMS_TYPE'];
                $ticketStatus = "LOST";
            } else {//win
                $ticketStatus = "WIN";
                $subscriberUpdate['LOST_LEVEL_ID'] = 1;
                $result = $this->db->getMessageTemplates("WIN");
                $templateID = $result['ID'];
                $sms = str_replace("%availabletickets", $availableTickets, $result['MESSAGE']);
                $sms = str_replace("%tickets", 1, $sms);
                $sms = str_replace("%amount", $winnerResult['TOTAL_WINNING_AMOUNT'], $sms);
                $smsType = $result['SMS_TYPE'];
                $winnerData = [];
                $winnerData['MSISDN'] = $this->msisdn;
                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                $winnerData['SUBSCRIBER_ID'] = $subDetails['ID'];
                $winnerData['INTERACTION_ID'] = $recordID;
                $this->db->__insert('tbl_promo_fixed_winners', $winnerData);
                $reason = 'WINNER PAYOUT';

                if ($type === "RENEWAL_CREDIT") {
                    $subscriberDetailsArrayUpdate['CREDIT_USED'] = $subInfo['CREDIT_USED'] + 1;
                } else {

                    $subscriberDetailsArrayUpdate['CREDIT_PLAY_COUNT'] = $subInfo['CREDIT_PLAY_COUNT'] + 1;
                    $subscriberDetailsArrayUpdate['TOTAL_USED'] = $subInfo['TOTAL_USED'] + 1;
                }

                $subscriberDetailsArrayUpdate['WIN_COUNT'] = $subInfo['WIN_COUNT'] + 1;
                $subscriberDetailsArrayUpdate['SMS_COUNT'] = $subInfo['SMS_COUNT'] + 1;
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberDetailsArrayUpdate);
            }

            $gremsData = [];
            $gremsData['MSISDN'] = $this->msisdn;

            $gremsData['MNO_TXN_ID'] = 0;
            $gremsData['OPERATOR_TXN_ID'] = date("YmdHis") . $this->msisdn;
            $gremsData['AMOUNT'] = 0;
            $gremsData['DRAW_ID'] = $recordID;
            $gremsData['TICKET_ID'] = $recordID;
            $gremsData['TICKET_STATUS'] = $ticketStatus;
            $gremsData['REQUEST_TYPE'] = "DRAW";
            $gremsData['PAYMENT_STATUS'] = "SUCCESS";
            $gremsData['TXN_TYPE'] = "CREDIT";
            if ($smsType === "WON") {
                $gremsData['WON_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
            }
            $this->db->__insert("grems_txn", $gremsData);

            if ($ticketStatus === "WIN") {
                $disbursement = [];
                $disbursement['MSISDN'] = $this->msisdn;
                $disbursement['AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                $disbursement['PAYOUT_STATUS'] = 'PENDING';
                $disbursement['INTERACTION_ID'] = $recordID;
                $disbursement['INTERACTION_CHANNEL'] = "SMS";
                $this->db->__insert("tbl_promo_fixed_winnersdisbursement_process", $disbursement);
            }

            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
            $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
            return true;
        }
    }

    function updatePaymentStatus($txnID, $paymentData = []) {

        $this->db->__update("tbl_promo_billing", "ID", $txnID, $paymentData);
        return true;
    }

    function recordInteraction($moID, $billingID, $type): int {
        $interactionArray = [];
        $interactionArray['MSISDN'] = $this->msisdn;
        $interactionArray['MO_ID'] = $moID;
        $interactionArray['BILLING_ID'] = $billingID;
        $interactionArray['BILLING_STATUS'] = 'SUCCESS';
        $interactionArray['PLAY_TYPE'] = $type;

        if (strpos($this->appConfig['TEST_MSISDN'], $this->msisdn) !== false) {
            $table = "tbl_promo_entries_test";
        } else {
            $table = "tbl_promo_entries";
        }
        $id = $this->db->__insert($table, $interactionArray);
        if (empty($id) || $id <= 0) {
            return 0;
        } else {
            return $id;
        }
    }

    function checkifWinner($interactionID) {
        $result = $this->db->selectWinningpostion($interactionID);
        if (empty($result)) {
            return array();
        } else {


            return $result;
        }
    }

    function __record($table, $insertArray = []) {

        $this->db->__insert($table, $insertArray);
    }

    function play($subDetails, $subInfo = []) {

        //check if they are boom customer
        $customer_type = "";
        $boomCustomerInfo = $this->lib->getBoomCustomerInfo($this->msisdn); 
        if (!empty($boomCustomerInfo) && isset($boomCustomerInfo["ResponseCode"])) {
            $response_code = $boomCustomerInfo["ResponseCode"];
            if ($response_code == "BOOM-10-1002-S" || $this->msisdn == "255656692469") {
                $customer_type = "BOOM_CUSTOMER";
            } else if ($response_code == "BOOM-10-10050-E") {
                $customer_type = "NONE_BOOM_CUSTOMER";
            } else {
                $customer_type = "UNKNOWN";
            }
        } else {
            $customer_type = "UNKNOWN";
        }

        if (empty($subDetails)) {
            $subscriberArray = [];
            $subscriberArray['MSISDN'] = $this->msisdn;
            $subscriberArray['_STATUS'] = "SUBSCRIBED";
            $subscriberArray['ACTIVITY_STATUS'] = 'ACTIVE';
            $subscriberArray['CUSTOMER_TYPE'] = $customer_type;
            $subscriberArray['LAST_INTERCATION'] = date("Y-m-d H:i:s");
            $subscriberArray['LOST_LEVEL_ID'] = 1;
            if ($this->isStaff) {
                $subscriberArray['DND_STATUS'] = 'ON';
                $subscriberArray['ENTICEMENT_STATUS'] = 'OFF';
            }

            if($customer_type == "BOOM_CUSTOMER" || $this->msisdn == "255656692469"){
                $subscriberArray['CREDIT'] = 1;
                /*$bm_crdt_awrd = $this->db->getMessageTemplates("BOOM_CREDIT_AWARD_SMS");
                $templateID = $bm_crdt_awrd['ID'];
                $sms = $bm_crdt_awrd['MESSAGE'];
                $smsType = $bm_crdt_awrd['SMS_TYPE'];
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);*/
            }

            $this->addSubscribers($subscriberArray);
            $this->sendRenewal($this->msisdn, "SUBSCRIPTION");
            //add to subscriber details table 
            $subscriberDetailsArray = [];
            $subscriberDetailsArray['MSISDN'] = $this->msisdn;
            $this->db->__insert("subscriber_details", $subscriberDetailsArray); // add data to subscriber table

            $welcome = $this->db->getMessageTemplates("WELCOME_SMS");
            $templateID = $welcome['ID'];
            $sms = $welcome['MESSAGE'];
            $smsType = $welcome['SMS_TYPE'];
            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
            $subsms = $this->db->getMessageTemplates("SUB_SMS");
            $templateID = $subsms['ID'];
            $sms = $subsms['MESSAGE'];
            $smsType = $subsms['SMS_TYPE'];
            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);

            if($customer_type !== "BOOM_CUSTOMER" && $this->msisdn !== "255656692469"){
                $requestID = "MPR" . $this->moID . date("YmdHis");
                $amount = $this->appConfig['DIRECT_PLAY_AMOUNT'];
                $responseData = $this->lib->sendCharging($this->moID, $requestID, $amount, "AIRTIME", "PLAY");
                $paymentArray = [];
                $txnID = $responseData['txnID'];
                $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                $this->updatePaymentStatus($txnID, $paymentArray);

                if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    $this->sendRenewal($this->msisdn, "SUBSCRIPTION");
                    return true;
                }

                $ackResponse = json_decode($responseData['response'], true);
                if (!isset($ackResponse['responseParam'])) {
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    $this->sendRenewal($this->msisdn, "SUBSCRIPTION");
                    return true;
                }

                if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                }
                if (!$responseData['isResponse']) {//if timeout
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                }
            }else{
                $bm_crdt_awrd = $this->db->getMessageTemplates("BOOM_CREDIT_AWARD_SMS");
                $templateID = $bm_crdt_awrd['ID'];
                $sms = $bm_crdt_awrd['MESSAGE'];
                $smsType = $bm_crdt_awrd['SMS_TYPE'];
                sleep(1);
                $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);

                $subDetails = $this->db->getSubscriberDetails($this->msisdn);
                $subInfo = $this->db->getSubscriberInformation($this->msisdn);
                $this->processData($this->moID, $subDetails, $subInfo, "BOOM_CREDIT");
            }

            $this->sendRenewal($this->msisdn, "SUBSCRIPTION");

            return true;
        } else {

            if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
                $this->sendRenewal($this->msisdn, "SUBSCRIPTION");
            }

            if ($this->appConfig['CREDIT_ENABLED']) {///if kete option is enabled
                if ($subDetails['CREDIT'] > 0) {
                    $subscriberUpdate = [];
                    $subscriberUpdate['CREDIT'] = $subDetails['CREDIT'] - 1;
                    $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                    if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                        $subscriberUpdate['ACTIVITY_STATUS'] = "ACTIVE";
                        $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                        $subDetailUpdate = [];
                        $subDetailUpdate['REACT_DATE'] = date("Y-m-d H:i:s");
                        $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subDetailUpdate);
                    }
                    $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
                    $this->processData($this->moID, $subDetails, $subInfo, "RENEWAL_CREDIT");
                    /*if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE") {
                        $this->sendRenewal($this->msisdn, "SUBSCRIPTION");
                    }*/
                    return true;
                }
            }

            if ($subDetails['BUNDLE_COUNT'] > 0) {//redeem tickets
                $subscriberUpdate = [];
                $subscriberUpdate['BUNDLE_COUNT'] = $subDetails['BUNDLE_COUNT'] - 1;
                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                    $subscriberUpdate['ACTIVITY_STATUS'] = "ACTIVE";
                    $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                    $subDetailUpdate = [];
                    $subDetailUpdate['REACT_DATE'] = date("Y-m-d H:i:s");

                    $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subDetailUpdate);
                }

                $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
                $this->processData($this->moID, $subDetails, $subInfo, "CREDIT");
                return true;
            } else {


                $requestID = "MPR" . $this->moID . date("YmdHis");
                $amount = $this->appConfig['DIRECT_PLAY_AMOUNT'];
                $responseData = $this->lib->sendCharging($this->moID, $requestID, $amount, "AIRTIME", "PLAY");
                $subscriberUpdate = [];
                $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
                if ($subDetails['ACTIVITY_STATUS'] === "INACTIVE" || $subDetails['ENTICEMENT_STATUS'] === "OFF") {
                    $subscriberUpdate['ACTIVITY_STATUS'] = "ACTIVE";
                    $subscriberUpdate['ENTICEMENT_STATUS'] = 'ON';
                    $subDetailUpdate = [];
                    $subDetailUpdate['REACT_DATE'] = date("Y-m-d H:i:s");

                    $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subDetailUpdate);
                }
                $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
                $paymentArray = [];
                $txnID = $responseData['txnID'];
                $paymentArray['ACK_RESPONSE'] = $responseData['response'];
                $this->updatePaymentStatus($responseData['txnID'], $paymentArray);

                if (!$this->lib->is_json($responseData['response'])) {//check if the response is json
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    $this->db->__close();
                    return true;
                }
                $ackResponse = json_decode($responseData['response'], true);
                if (!isset($ackResponse['responseParam'])) {
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                    return true;
                }


                if (strpos($responseData['response'], '"status":"1"') !== false || strpos($responseData['response'], 'Failed') !== false || strpos($responseData['response'], 'failed') !== false) {//if ack error
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                }
                if (!$responseData['isResponse']) {//if timeout
                    $result = $this->db->getMessageTemplates("ERROR");
                    $templateID = $result['ID'];
                    $sms = $result['MESSAGE'];
                    $smsType = $result['SMS_TYPE'];
                    $this->processMt($this->moID, $templateID, 0, $this->msisdn, "ACK_ERROR", $sms);
                }

                return true;
            }
        }
    }

    function unsubscribe($subDetails) {
        $activity_status = $subDetails['ACTIVITY_STATUS'];

        if ($this->appConfig['UNSUB_SMS_ENABLED']) {

            $result = $this->db->getMessageTemplates(
                $subDetails['ACTIVITY_STATUS'] === "ACTIVE" ? "UNSUB_SMS" : "ALREADY_UNSUB_SMS"
            );

            $templateID = $result['ID'];
            $sms = $result['MESSAGE'];
            $smsType = $result['SMS_TYPE'];
            $subscriberUpdate = [];
            $subscriberUpdate['ACTIVITY_STATUS'] = 'INACTIVE';
            $subscriberUpdate['BUNDLE_COUNT'] = 0;
            $subscriberUpdate['LAST_INTERCATION'] = date("Y-m-d H:i:s");
            $subscriberUpdate['ENTICEMENT_STATUS'] = 'OFF';
            $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
            $subscriberInfo = $this->db->getSubscriberInformation($this->msisdn);
            $subscriberInfo['ID'] = NULL;
            $subscriberInfo['UNSUB_DATE'] = date("y-m-d H:i:s");
            $subscriberInfo['OTHER_INTERACTION_COUNT'] = $subscriberInfo['OTHER_INTERACTION_COUNT'] + 1;
            $subscriberInfo['CREATED_AT'] = date("Y-m-d H:i:s");
            $subscriberInfo['SMS_COUNT'] = $subscriberInfo['SMS_COUNT'] + 1;
            $this->db->__insert("subscriber_details_history", $subscriberInfo);

            //reset subscriber detail table
            $subscriberInfoUpdate = [];
            $subscriberInfoUpdate['SUCCESS_BILLED_PLAY_COUNT'] = 0;
            $subscriberInfoUpdate['FAILED_BILLED_PLAY_COUNT'] = 0;
            $subscriberInfoUpdate['CREDIT_PLAY_COUNT'] = 0;
            $subscriberInfoUpdate['OTHER_INTERACTION_COUNT'] = 0;
            $subscriberInfoUpdate['WIN_COUNT'] = 0;
            $subscriberInfoUpdate['LOST_COUNT'] = 0;
            $subscriberInfoUpdate['TOTAL_PURCHASED'] = 0;
            $subscriberInfoUpdate['TOTAL_USED'] = 0;
            $subscriberInfoUpdate['SMS_COUNT'] = 0;
            $subscriberInfoUpdate['USSD_COUNT'] = 0;
            $subscriberInfoUpdate['UNSUB_DATE'] = date("Y-m-d H:i:s");
            $this->db->__update("subscriber_details", "MSISDN", $this->msisdn, $subscriberInfoUpdate);
            $this->processMt($this->moID, $templateID, 0, $this->msisdn, $smsType, $sms);
            $this->sendRenewal($this->msisdn, "UNSUBSCRIPTION");
        } else {
            $subscriberUpdate = [];
            $subscriberUpdate['ENTICEMENT_STATUS'] = 'OFF';
            $this->db->__update("tbl_promo_subscribers", "MSISDN", $this->msisdn, $subscriberUpdate);
        }
        return true;
    }

    function setForDraw($drawArray, $where, $whereValue) {
        $this->db->__update('tbl_promo_draw', $where, $whereValue, $drawArray);
        return true;
    }

    function sendRenewal($msisdn, $type) {
        // if ($this->isStaff) {//block staff numbers from renewals
        //     return true;
        // }
        if ($type === "SUBSCRIPTION") {
            $requestType = "SUBSCRIPTION";
        } else {
            $requestType = "UNSUBSCRIPTION";
        }

        $urls = array();

        if ($this->isStaff) {
            $urls = array(
                array(
                    "url" => "http://102.218.75.35:7979/api/subs-manage/notification",
                    "serviceID" => "TESTI ZALI"
                )
            );
        }else{
            $urls = array(
                array(
                    "url" => "http://192.168.168.10/TigoRenewalServices/ManageSubscription.php",
                    "serviceID" => "ZALI"
                ),
                array(
                    "url" => "http://102.218.75.35:7979/api/subs-manage/notification",
                    "serviceID" => "TESTI ZALI"
                )
            );
        }

        foreach ($urls as $endpoint) {
            $requestArray = array(
                "msisdn" => $msisdn,
                "serviceID" => $endpoint["serviceID"],
                "activityType" => $requestType
            );
            $jsonRequest = json_encode($requestArray);
            $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($jsonRequest), "Cache-Control: no-cache", "Pragma: no-cache");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint["url"]);
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
    }

}
