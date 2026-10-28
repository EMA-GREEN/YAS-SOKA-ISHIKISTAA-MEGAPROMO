<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');
error_reporting(0);

// require dirname (  __DIR__, 2).'/analog/lib/Analog.php';

class SokaTrivia extends CI_Controller {

    public function __construct() {
        parent::__construct();
    }

    private function sokaGateway()
    {
        if (!isset($this->TigoSokaGatewayServices)) {
            $this->load->model('TigoSokaGatewayServices', 'TigoSokaGatewayServices');
        }
        return $this->TigoSokaGatewayServices;
    }

    private function renewalGateway()
    {
        if (!isset($this->TigoRenewalGatewayServices)) {
            $this->load->model('TigoRenewalGatewayServices', 'TigoRenewalGatewayServices');
        }
        return $this->TigoRenewalGatewayServices;
    }

    function notification() {// this will be used for all type of notification
        // receive sms
        $requestData = file_get_contents("php://input");
        if (empty($requestData)) {
            echo "Empty Request";
            exit;
        }
        $requestFile = "logs/Soka-".date("YmdH") . ".txt";
        file_put_contents($requestFile, $requestData . " \n", FILE_APPEND);
        $requestArray = json_decode($requestData, TRUE);
        $requestID = $requestArray['requestId'];
        
        
        $data['request'] = $requestData;
        $data['REQUEST_ID'] = $requestID;
        $responseID = $this->processnotification($requestData);
        $responseTimeStamp = date("YmdHis");
        $responseData = [];
        $responseData['requestId'] = $requestID;
        $responseData['responseId'] = $responseID;
        $responseData['responseTimeStamp'] = $responseTimeStamp;
        $responseData['operation'] = 'CP_NOTIFICATION';
        $responseData['responseParam']['status'] = "0";
        $responseData['responseParam']['statusCode'] = "0000";
        $responseData['responseParam']['description'] = "Success";
        echo json_encode($responseData);
        exit;
    }

    function processnotification($request) {

        $array = json_decode($request, TRUE);

        $notificationType = "";

        $requestID = $array['requestId'];
        $requestTimeStamp = $array['requestTimeStamp'];
        $referenceID = 0; //default value
        $billingSystem = "";
        $requestArray = [];
        $arrayData = $array['requestParam']['data'];
        foreach ($arrayData as $key => $value) {
            $requestArray[$arrayData[$key]['name']] = $arrayData[$key]['value'];
        }
        $msisdn = $requestArray['Msisdn'];
        $recordData['MSISDN'] = $msisdn;
        $amount = 0;
        if (isset($requestArray['TransactionId'])) {
            $referenceID = $requestArray['TransactionId'];
        }
        if(isset($requestArray['ChargeAmount'])){
            
            $amount = $requestArray['ChargeAmount'];
            
        }

        if (isset($requestArray['billingSystem'])) {
            $billingSystem = $requestArray['billingSystem'];
        }
        
        ///this is for testing our service soccer trivia
          if($msisdn==="255656692469-disabled"&&$requestArray['Type']==="InteractiveResponse"){
            $url = "http://188.64.188.232:8082/green-1.0/SMSService/gr33nte1c0m/sms/fdbck/api";
            $jsonRequest = [];
            $jsonRequest['MSISDN']= $msisdn;
            $jsonRequest['TEXT'] = $requestArray['CustomerResponse'];
            $request = json_encode($jsonRequest);
            $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($request), "Cache-Control: no-cache", "Pragma: no-cache");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            return true;
          }  
          /*if($msisdn==="255659256870"){
            $url = "http://localhost/TIGO/supa-trivia/notification/";
            $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($request), "Cache-Control: no-cache", "Pragma: no-cache");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            return true;
        }*/
        ////testing our service
        
        if ($requestArray['Type'] === "DeActivation") {

            if ($requestArray['OfferCode'] === "100000014600308"||$requestArray['OfferCode']==="100000014600308") {
                $tbl = "tbl_interaction_mo_notifications_test";
                $notificationType = "INTERACTION";
            } else {
                $tbl = "tbl_interaction_mo_notifications";
                $notificationType = "INTERACTION";
            }

            $type = "SMS";
            $requestType = "RequestSendSms";
            $recordData['REFERENCE_ID'] = $referenceID;
            $recordData['TEXT'] = "ONDOA SOKA";
            $recordData['REFERENCE_ID'] = 0;
            $recordData['REQUEST_TYPE'] = "UN_SUBSCRIPTION";
        }
        if ($requestArray['Type'] === "Activation" || $requestArray['Type'] === "RequestSendSms") {//activation
            if ($requestArray['OfferCode'] === "100000014600308"||$requestArray['OfferCode']==="100000014600308") {
                $tbl = "tbl_interaction_mo_notifications_test";
                $notificationType = "INTERACTION";
            } else {
                $tbl = "tbl_interaction_mo_notifications";
                $notificationType = "INTERACTION";
            }
            
            $type = "SMS";

            if(isset($requestArray['SubscriptionStatus']) && $requestArray['SubscriptionStatus'] === "A"){
                if (isset($requestArray['CustomerType'])) {///if tag existing
                    $customerType = $requestArray['CustomerType'];
                    $recordData['REFERENCE_ID'] = $referenceID;
                    
                    $recordData['TEXT'] = $customerType . "-SOKA";
                    $recordData['REFERENCE_ID'] = 0;
                    $recordData['REQUEST_TYPE'] = "SUBSCRIPTION";
                } else {
                    $customerType = $requestArray['customerType'];
                    $recordData['REFERENCE_ID'] = $referenceID;
                    
                    $recordData['TEXT'] = "SOKA";
                                    
                    $recordData['REFERENCE_ID'] = 0;
                    $recordData['REQUEST_TYPE'] = "SUBSCRIPTION";
                }
            }
        }

        if ($requestArray['Type'] === "InteractiveResponse") {
            $check = $this->sokaGateway()->checkMoNotification($requestID, $msisdn);
            if (!empty($check)) {
                return true;
            } else {
                $moArray = [];
                $moArray['MSISDN'] = $msisdn;
                $moArray['REQUEST_ID'] = $requestID;
                $moArray['DATE'] = date("Y-m-d");
                $this->sokaGateway()->_recordData($moArray, "trivia_tigotz_vas", "trivia_mo_check");
            }

            if ($requestArray['OfferCode'] === "100000014600308"||$requestArray['OfferCode']==="100000014600308") {
                $tbl = "tbl_interaction_mo_notifications_test";
                $notificationType = "INTERACTION";
            } else {
                $tbl = "tbl_interaction_mo_notifications";
                $notificationType = "INTERACTION";
            }

            if(isset($requestArray['Reason'])&&!empty($requestArray['Reason'])){
                $recordData['STATUS'] = $requestArray['Reason'];
            }

            $type = "SMS";

            $recordData['REFERENCE_ID'] = $referenceID;
            $table = "tbl_interaction_mo_notifications";
            $text = $requestArray['CustomerResponse'];
            $recordData['REQUEST_TYPE'] = "InteractiveResponse";
            $recordData['TEXT'] = $text;
            $recordData['REQUEST_ID'] = $requestID;
        }

        if ($requestArray['Type'] === "Payment") {///payment request
            $type = "Payment";
         if ($requestArray['ChargeAmount'] === "null" || $requestArray['ChargeAmount'] === "" || $requestArray['ChargeAmount'] === 0) {
                $paymentStatus = "FAILED";
                if(isset($requestArray['Reason'])&&!empty($requestArray['Reason'])){
                    $billingDetail = $requestArray['Reason'];
                }else{
                    $billingDetail = "No Reason For Failure";
                }
            } else {
            	if(isset($requestArray['Reason'])&&!empty($requestArray['Reason'])){
            		$status = "FAILED";
            		$paymentInfo = $requestArray['Reason'];
            		$billingInfo = $paymentInfo;
            	}else{
                    $status = "SUCCESS";
                    $paymentInfo = $requestArray['BillingId'];
                    $billingInfo = $requestArray['BillingId'];
            	}
                $paymentStatus = $status;
                $paymentDetails = $paymentInfo;
                $billingDetail = $billingInfo;
            }


            if (strpos($requestID, 'WW') !== false) {
                $recordData['CHANNEL'] = 'WEB';
                $tbl = "tbl_payment_web_notifications_" . date("Ymd");
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'TS') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL_TEST';
                $tbl = "tbl_payment_test_renewal_notifications_20210203";
                $notificationType = "RENEWAL";
            } elseif (strpos($requestID, 'A') !== false) {
                $recordData['CHANNEL'] = 'ANDROID';
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'RT') !== false) {
                $recordData['CHANNEL'] = 'RETRY';
                $tbl = "tbl_payment_retry_mo_notifications";
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'RP') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL';
                $tbl = "tbl_payment_retry_mo_notifications";
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'W') !== false || strpos($requestID, 'RW') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL';
                $tbl = "tbl_payment_soka_renewal_notifications_" . date("Ymd");
                $recordData['BILLED_AMOUNT'] = $amount;
                $notificationType = "RENEWAL";
            } elseif (strpos($requestID, 'w') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL';
                $recordData['PROMO_STATUS'] = 'ON';
                $tbl = "tbl_payment_soka_renewal_notifications_" . date("Ymd");
                $notificationType = "RENEWAL";
            } else {
                $recordData['CHANNEL'] = 'SMS';
                if ($requestArray['OfferCode'] === "013014600289"||$requestArray['OfferCode']==="100000014600308") {
                    if (strpos($requestID, 'B') !== false) {
                        $recordData['IS_OUTGOING'] = '0';
                    }
                    $tbl = "tbl_payment_interaction_notifications_test_" . date("Ymd");
                } else {
                    $recordData['BILLING_SYSTEM'] = $billingSystem;
                    $tbl = "tbl_payment_interaction_notifications_" . date("Ymd");
                }
                $notificationType = "INTERACTION";
            }
            $requestType = "PAYMENT";
            $recordData['PAYMENT_STATUS'] = $paymentStatus;
            $recordData['PAYMENT_DETAILS'] = $billingDetail;
            $recordData['REQUEST_ID'] = $requestID;
        }

        if($notificationType === "INTERACTION"){
            $id = $this->sokaGateway()->_recordData($recordData, "trivia_tigotz_vas", $tbl);
        }else{
            $id = $this->renewalGateway()->_recordData($recordData, "tigo_renewals_db", $tbl);
        }

        return $id;
    }


}
