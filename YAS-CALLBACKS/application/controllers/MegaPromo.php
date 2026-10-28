<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
defined('BASEPATH') OR exit('No direct script access allowed');

//error_reporting(0);
// require dirname (  __DIR__, 2).'/analog/lib/Analog.php';

class MegaPromo extends CI_Controller {

    public function __construct() {
        parent::__construct();
    }

    private function megaPromoGateway()
    {
        if (!isset($this->TigoMegaPromoServices)) {
            $this->load->model('TigoMegaPromoServices', 'TigoMegaPromoServices');
        }
        return $this->TigoMegaPromoServices;
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
        $requestFile = "logs/MegaPromo-" . date("YmdH") . ".txt";
        file_put_contents($requestFile, $requestData . " \n", FILE_APPEND);
        $requestArray = json_decode($requestData, TRUE);
        $requestID = $requestArray['requestId'];
        $responseTimeStamp = date("YmdHis");
        $responseData = [];
        $responseData['requestId'] = $requestID;
        $responseData['responseTimeStamp'] = $responseTimeStamp;
        $responseData['operation'] = 'CP_NOTIFICATION';
        $responseData['responseParam']['status'] = "0";
        $responseData['responseParam']['statusCode'] = "0000";
        $responseData['responseParam']['description'] = "Success";
        file_put_contents($requestFile, json_encode($responseData) . " \n", FILE_APPEND);
        $responseID = $this->processNotification($requestData);
        $responseData['responseId'] = $responseID;
        echo json_encode($responseData);
        exit;
    }

    function processNotification($request) {
        //$request = file_get_contents("php://input");

        if (empty($request)) {
            echo "Empty Request";
            exit;
        }

        $array = json_decode($request, TRUE);

        $notificationType = "INTERACTION";

        $recordData = [];
        $requestID = $array['requestId'];
        $requestTimeStamp = $array['requestTimeStamp'];
        $referenceID = 0; //default value
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
        if (isset($requestArray['ChargeAmount'])) {
            $amount = $requestArray['ChargeAmount'];
        }

        if ($msisdn === "255656692469-disabled") {

            $url = "http://192.168.168.6/TIGO/notification/megapromo";
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
        if ($msisdn === "255656692469-disabled" || $msisdn === "255676589824-disabled" || $msisdn === "255655807727-disabled" || $msisdn === "255712505004-disabled") {


            // $url = "http://192.168.168.4/m-auction-app/index.php/notification/mauction";
            // $url = "http://192.168.168.10:8285/api/payments/payment-callback";
            $url = "http://188.64.189.38:9090/api/v1/billing/callbacks/yas";
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
        } else {
            if ($requestArray['Type'] === "Payment") {///payment request
                $type = "Payment";
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


                if (strpos($requestID, 'W') !== false) {

                    $tbl = "tbl_payment_zali_renewal_notifications_" . date("Ymd");
                    $recordData['CHANNEL'] = 'RENEWAL';
                    $requestType = "RENEWAL";
                    $recordData['PAYMENT_STATUS'] = $paymentStatus;
                    $recordData['PAYMENT_DETAILS'] = $billingDetail;
                    $recordData['REQUEST_ID'] = $requestID;
                    $recordData['BILLED_AMOUNT'] = $amount;
                    $notificationType = "RENEWAL";
                } elseif (strpos($requestID, 'WF') !== false || strpos($requestID, 'w') !== false) {
                    $recordData['CHANNEL'] = 'RENEWAL';
                    $tbl = "tbl_payment_zali_renewal_notifications_" . date("Ymd");
                    $requestType = "RENEWAL";
                    $recordData['PAYMENT_STATUS'] = $paymentStatus;
                    $recordData['PAYMENT_DETAILS'] = $billingDetail;
                    $recordData['REQUEST_ID'] = $requestID;
                    $recordData['BILLED_AMOUNT'] = $amount;
                    $notificationType = "RENEWAL";
                } elseif (strpos($requestID, 'RMPR') !== false) {
                    $recordData['CHANNEL'] = 'RETRY';
                    $tbl = "tbl_payment_retry_notifications";
                    $recordData['CHANNEL'] = 'RETRY';
                    $requestType = "RETRY";
                    $recordData['PAYMENT_STATUS'] = $paymentStatus;
                    $recordData['PAYMENT_DETAILS'] = $billingDetail;
                    $recordData['REQUEST_ID'] = $requestID;
                    $recordData['BILLED_AMOUNT'] = $amount;
                    $notificationType = "INTERACTION";
                } else {
                    $recordData['CHANNEL'] = 'SMS';
                    $tbl = "tbl_payment_interaction_notifications";
                    $requestType = "PAYMENT";
                    $recordData['PAYMENT_STATUS'] = $paymentStatus;
                    $recordData['PAYMENT_DETAILS'] = $billingDetail;
                    $recordData['REQUEST_ID'] = $requestID;
                    $recordData['BILLED_AMOUNT'] = $amount;
                    $notificationType = "INTERACTION";
                }

                if($notificationType === "INTERACTION"){
                    $id = $this->megaPromoGateway()->_recordData($recordData, "tigo_megapromo", $tbl);
                }else{
                    $id = $this->renewalGateway()->_recordData($recordData, "tigo_renewals_db", $tbl);
                }

                return $id;
            }
        }
    }
}
