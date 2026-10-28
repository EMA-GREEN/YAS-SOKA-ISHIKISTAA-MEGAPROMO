<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');
error_reporting(0);

class Ishikistaa extends CI_Controller {

    public function __construct() {
        parent::__construct();
    }

    private function gateway()
    {
        if (!isset($this->TigoGatewayServices)) {
            $this->load->database();
            $this->load->model('TigoGatewayServices', 'TigoGatewayServices');
        }
        return $this->TigoGatewayServices;
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
        $requestFile = "logs/Ishikistaa" . date("YmdH") . ".txt";
        file_put_contents($requestFile, $requestData . " \n", FILE_APPEND);
        $requestArray = json_decode($requestData, TRUE);
        //check in the notificationtable
        $requestID = $requestArray['requestId'];

        $data['request'] = $requestData;
        $data['REQUEST_ID'] = $requestID;
        $responseTimeStamp = date("YmdHis");
        $responseID = $this->processnotification($requestData);
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
        $amount = 0;
        if (isset($requestArray['ChargeAmount'])) {

            $amount = $requestArray['ChargeAmount'];
        }

        if (isset($requestArray['billingSystem'])) {
            $billingSystem = $requestArray['billingSystem'];
        }

        $msisdn = $requestArray['Msisdn'];
        $recordData['MSISDN'] = $msisdn;
        if (isset($requestArray['TransactionId'])) {
            $referenceID = $requestArray['TransactionId'];
        }

        if ($requestArray['Type'] === "DeActivation") {
            $type = "SMS";
            $tbl = "tbl_mo_notifications";
            $requestType = "RequestSendSms";
            $recordData['REFERENCE_ID'] = $referenceID;
            $recordData['TEXT'] = "ONDOA STAR";
            $recordData['REFERENCE_ID'] = 0;
            $recordData['REQUEST_TYPE'] = "UN_SUBSCRIPTION";
            $notificationType = "INTERACTION";
        }

        if ($requestArray['Type'] === "Activation" || $requestArray['Type'] === "RequestSendSms") {//activation
            $type = "SMS";
            $notificationType = "INTERACTION";
            $tbl = "tbl_mo_notifications";

            if(isset($requestArray['SubscriptionStatus']) && $requestArray['SubscriptionStatus'] === "A"){
                if (isset($requestArray['CustomerType'])) {///if tag existing
                    $customerType = $requestArray['CustomerType'];
                    $recordData['REFERENCE_ID'] = $referenceID;
                    $recordData['TEXT'] = $customerType . "-STAR";
                    $recordData['REFERENCE_ID'] = 0;
                    $recordData['REQUEST_TYPE'] = "SUBSCRIPTION";
                } else {
                    $requestType = "RequestSendSms";
                    $recordData['REFERENCE_ID'] = $referenceID;
                    $recordData['TEXT'] = "STAR";
                    $recordData['REFERENCE_ID'] = 0;
                    $recordData['REQUEST_TYPE'] = "SUBSCRIPTION";
                }
            }
        }

        if ($requestArray['Type'] === "InteractiveResponse") {
            $type = "SMS";
            $notificationType = "INTERACTION";
            $tbl = "tbl_mo_notifications";
            $recordData['REFERENCE_ID'] = $referenceID;

            if(isset($requestArray['Reason'])&&!empty($requestArray['Reason'])){
                $recordData['STATUS'] = $requestArray['Reason'];
            }
            
            $text = $requestArray['CustomerResponse'];
            $recordData['REQUEST_TYPE'] = "InteractiveResponse";
            $recordData['TEXT'] = $text;
        }

        if ($requestArray['Type'] === "Payment") {///payment request
            if ($requestArray['ChargeAmount'] === "null" || $requestArray['ChargeAmount'] === "" || $requestArray['ChargeAmount'] === 0) {
                $paymentStatus = "FAILED";
                $billingDetail = $requestArray['Reason'];
            } else {
                if (isset($requestArray['Reason']) && !empty($requestArray['Reason'])) {
                    $status = "FAILED";
                    $paymentInfo = $requestArray['Reason'];
                    $billingInfo = $paymentInfo;
                } else {
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
            } elseif (strpos($requestID, 'A') !== false) {
                $recordData['CHANNEL'] = 'ANDROID';
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'GS') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL';
                $tbl = "tbl_payment_renewal_mo_notifications";
                $notificationType = "INTERACTION";
            } elseif (strpos($requestID, 'R') !== false || strpos($requestID, 'W') !== false) {
                $recordData['CHANNEL'] = 'RENEWAL';
                $recordData['BILLED_AMOUNT'] = $amount;
                $tbl = "tbl_payment_ishikistaa_renewal_notifications_" . date("Ymd");
                $notificationType = "RENEWAL";
            } else {
                $recordData['CHANNEL'] = 'SMS';
                $recordData['BILLING_SYSTEM'] = $billingSystem;
                $tbl = "tbl_payment_interaction_notifications_" . date("Ymd");
                $notificationType = "INTERACTION";
            }
            $requestType = "PAYMENT";
            $recordData['PAYMENT_STATUS'] = $paymentStatus;
            $recordData['PAYMENT_DETAILS'] = $billingDetail;
            $recordData['REQUEST_ID'] = $requestID;
        }

        if($notificationType === "INTERACTION"){
            $id = $this->gateway()->_recordData($recordData, "ishikistaa_tigo_vas", $tbl);
        }else{
            $id = $this->renewalGateway()->_recordData($recordData, "tigo_renewals_db", $tbl);
        }

        return $id;
    }
}
