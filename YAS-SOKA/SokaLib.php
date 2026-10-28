<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of SokaLib
 *
 * @author DELL
 */
//require_once 'SokaServices';
error_reporting(E_ALL);
date_default_timezone_set("Africa/Dar_Es_Salaam");
require_once 'SokaServices.php';

class SokaLib {

    var $amount, $msisdn, $token, $requestID, $referenceID;

    public function __construct($msisdn, $token, $requestID, $referenceID) {
        $this->msisdn = $msisdn;
        $this->token = $token;
        $this->requestID = $requestID;
        $this->referenceID = $referenceID;
    }

//put your code here



    function QueryPoints($requestID, $question, $points) {
        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/
        $responseID = date("Ymdhis") . $this->msisdn;
        $responseTimeStamp = date("Ymdhis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $responseTimeStamp);
        $queryPoints = '{
"requestId": "' . $responseID . '",
"requestTimeStamp": "' . $responseTimeStamp . '",
"channel": "10",
"sourceAddress": "188.64.188.47",
"operation": "QueryPointsResponse",
"username": "GreenTelecom",
"password":"' . $encrypted . '",
"requestParam": {
"data": [
{
"name": "Msisdn",
"value": "' . $this->msisdn . '"
},
{
"name": "Content",
"value": "' . $question . '"
},
{
"name": "OfferCode",
"value": "' . $offerCode . '"
},
{
"name": "Points",
"value": "' . $points . '"
},
{
"name": "CpId",
"value": "86"
}
]
}
}';

        file_put_contents("pointRquest.txt", $queryPoints, FILE_APPEND);

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($queryPoints), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token, "username : GreenTelecom", "password :" . $encrypted);
        $url = "http://10.222.10.232:8086/api/public/QueryPoints/QueryPointsResponse";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryPoints);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            file_put_contents("PointsResponse.txt", $response, FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        return $responseData;
    }

    function sendMoReprocess($moID, $amount) {
        $requestID = "RP" . date("YmdHis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $this->amount = $amount;
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $paymentData = '{
  "password" : "' . $encrypted . '",
  "sourceAddress": "188.64.188.49",
  "requestId": "' . $requestID . '",
  "requestTimeStamp": "' . $date . '",
  "channel": "10",
  "requestParam": {
    "data": [
      {
        "name": "OfferCode",
        "value": "017008600005"
      },
      {
        "name": "Msisdn",
        "value": "' . $this->msisdn . '"
      },
        {
         "name": "TypeOfService",
        "value": "InteractiveService"
        },
      {
        "name": "Language",
        "value": "1"
      },
      {
        "name": "ChargeAmount",
        "value": "' . $this->amount . '"
      },
      {
        "name": "CpId",
        "value": "86"
      }
    ]
  },
  "operation": "Payment",
  "username": "GreenTelecom"
}';


        $requestFile = date("YmdH") . ".txt";
        file_put_contents("logs/requests/PaymentRequest-" . $requestFile, $paymentData . " \n", FILE_APPEND);

        $paymentArray = [];


        $paymentArray['MSISDN'] = $this->msisdn;
        $paymentArray['AMOUNT'] = $this->amount;
        $paymentArray['REQUEST_ID'] = $requestID;
        $paymentArray['REQUEST_HEADER'] = 'X-Authorization: Bearer ' . $this->token;
        $table = "vas_transaction_trivia_mo_reprocess";
        $txnID = $sokaServicesObj->recordData($table, $paymentArray);


        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($paymentData), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        $url = "http://10.222.10.232:8086/api/public/PAY/Payment";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/PaymentRequestResponse-" . $responseFile, $response . " \n", FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'FAILED : ' . $ex->getMessage();
            $responseData['txnID'] = $txnID;
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/PaymentErrorResponse-" . $responseFile, $ex->getMessage() . " \n", FILE_APPEND);
            return $responseData;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['txnID'] = $txnID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'ERROR';
            $responseData['txnID'] = $txnID;
        }

        return $responseData;
    }

    function sendCharging($moID, $amount, $isFallback, $transactionRequest, $paymentType="NORMAL_BILLING") {
        switch ($paymentType) {
            case "BOOM_BILLING" :
                $offerCode = "017008600272";
                break;
            case "NORMAL_BILLING" :
                $offerCode = "017008600005";
                break;
            default :
                $offerCode = "017008600005";
        }

        if ($isFallback) {
            if($amount===30){
                $requestID = "FF" . $moID . date("YmdHis") . $this->msisdn;
            }else{
                $requestID = "F" . $moID . date("YmdHis") . $this->msisdn;
            }
        } else {
            $requestID = $moID . date("YmdHis") . $this->msisdn;
        }

        if ($amount === "BOOM") {
            $amount = 0;
            $requestID = "B" . $requestID;
        }

        $sokaServicesObj = new SokaServices("consumer");

        if($transactionRequest==="PURCHASE"){
            
            $requestID = "PU".$requestID;
            
            $pointPurchaseArray = [];
            $pointPurchaseArray['MO_ID'] = $moID;
            $pointPurchaseArray['MSISDN'] = $this->msisdn;
            $pointPurchaseArray['AMOUNT'] = $amount;
            $pointPurchaseArray['CHANNEL'] = "SMS";
            $pointPurchaseArray['REQUEST_ID'] = $requestID;
            $table = "tbl_point_purchase_" . date("Ymd");
            $sokaServicesObj->recordData($table, $pointPurchaseArray);
        }

        if ($transactionRequest === "PREDICT") {
            if (!defined('PREDICT_REQUEST_PREFIX')) {
                require_once __DIR__ . '/config.php';
            }
            $requestID = PREDICT_REQUEST_PREFIX . $requestID;
        }
        
        $this->amount = $amount;
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $paymentData = '{"password" : "' . $encrypted . '","sourceAddress": "188.64.188.49","requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","requestParam": {"data": [{"name": "OfferCode","value": "'.$offerCode.'"},{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "ReferenceId","value": "' . $this->referenceID . '"},{"name": "TypeOfService","value": "InteractiveService"},{"name": "Language","value": "1"},{"name": "ChargeAmount","value": "' . $this->amount . '"},{"name": "CpId","value": "86"}]},"operation": "Payment","username": "GreenTelecom"}';


        $requestFile = "logs/paymentlogs/".date("YmdH") . ".txt";
        
        $logData = "----> Payment  Request  Logging Started " . $this->msisdn . "<----" . PHP_EOL;
        $requestTime = date("Y-m-d H:i:s");
        $paymentArray = [];
        $paymentArray['MO_ID'] = $moID;
        $paymentArray['MSISDN'] = $this->msisdn;
        $paymentArray['AMOUNT'] = $this->amount;
        $paymentArray['CHANNEL'] = 'SMS';
        $paymentArray['REFERENCE_ID'] = $this->referenceID;
        $paymentArray['REQUEST_ID'] = $requestID;
        $paymentArray['REQUEST_SENT'] = str_replace('"', '', $paymentData);
        $paymentArray['REQUEST_HEADER'] = 'X-Authorization: Bearer ' . $this->token;
        $table = "vas_transaction_trivia";
        $txnID = $sokaServicesObj->recordData($table, $paymentArray);
        $txnRecord = [];
        $txnRecord['MO_ID'] = $moID;
        $txnRecord['MSISDN'] = $this->msisdn;
        $txnRecord['AMOUNT'] = $this->amount;
        $txnRecord['CHANNEL'] = 'SMS';
        $txnRecord['REFERENCE_ID'] = $this->referenceID;
        $txnRecord['REQUEST_ID'] = $requestID;
        $txnRecord['TXN_ID'] = $txnID;
        $sokaServicesObj->recordData("vas_transaction_trivia_new", $txnRecord);

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($paymentData), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        $renewurl = "http://10.222.10.216:8086/api/public/PAY_AAT/PAY_AAT";
        $url = "http://10.222.10.232:8086/api/public/PAY/Payment";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'TIME_OUT';
            $responseData['txnID'] = $txnID;
            $responseData['requestID'] = $requestID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $txnID . ", MO ID : " . $moID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $paymentData . ", Response : ".$ex->getMessage().",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>Payment Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
            return $responseData;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['txnID'] = $txnID;
            $responseData['requestID'] = $requestID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $txnID . ", MO ID : " . $moID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $paymentData . ", Response : ".$response.",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>Payment Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
            
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'ACK_ERROR';
            $responseData['txnID'] = $txnID;
            $responseData['requestID'] = $requestID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $txnID . ", MO ID : " . $moID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $paymentData . ", Response : TIMEOUT,Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>Payment Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
        }

        return $responseData;
    }

    function get_string_between($string, $start, $end) {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0)
            return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    function sendRenewalContent($text, $moID, $txnID, $smsType) {
        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/
        $requestID = "RN" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $sms = $text;

        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $interactiveRequest = '{
    "requestId": "' . $requestID . '",
    "requestTimeStamp": "' . $date . '",
    "channel": "10",
    "sourceAddress": "188.64.188.49",
    "operation": "InteractiveRequest",
    "username": "GreenTelecom",
    "password": "' . $encrypted . '",
    "requestParam": {
    "data": [
    {
    "name": "Msisdn",
    "value": "' . $this->msisdn . '"
    },
    {
    "name": "Content",
    "value": "' . $sms . '"
    },
    {
    "name": "OfferCode",
    "value": "' . $offerCode . '"
    },
    {
    "name": "CpId",
    "value": "86"
    }
    ]
    }
    }';


        $requestfile = date("YmdH") . ".txt";
        file_put_contents("logs/requests/RenewalContentRequest-" . $requestfile, $interactiveRequest . " \n", FILE_APPEND);

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);

        $smsArray = [];
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsArray['REQUEST_SENT'] = str_replace('"', "", $interactiveRequest);
        $smsID = $sokaServicesObj->recordData("tbl_renewal_content_logs", $smsArray);
        $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/RenewalContentRequestResponse-" . $responseFile, $response . " \n", FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        return $responseData;
    }

    function sendQuestion($text, $moID, $txnID, $smsType) {
        $requestID = "QN" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $sms = $text;

        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/

        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $interactiveRequest = '{
    "requestId": "' . $requestID . '",
    "requestTimeStamp": "' . $date . '",
    "channel": "10",
    "sourceAddress": "188.64.188.49",
    "operation": "InteractiveRequest",
    "username": "GreenTelecom",
    "password": "' . $encrypted . '",
    "requestParam": {
    "data": [
    {
    "name": "Msisdn",
    "value": "' . $this->msisdn . '"
    },
    {
    "name": "Content",
    "value": "' . $sms . '"
    },
    {
    "name": "OfferCode",
    "value": "' . $offerCode . '"
    },
    {
    "name": "CpId",
    "value": "86"
    }
    ]
    }
    }';


        $requestfile = date("YmdH") . ".txt";
        file_put_contents("logs/requests/SubInteractiveRequest-" . $requestfile, $interactiveRequest . " \n", FILE_APPEND);

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);

        $smsArray = [];
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsArray['REQUEST_SENT'] = str_replace('"', "", $interactiveRequest);
        $smsID = $sokaServicesObj->recordData("trivia_reminder_logs", $smsArray);
        $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/SubInteractiveRequestResponse-" . $responseFile, $response . " \n", FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        return $responseData;
    }

    function interactiveRequest($text, $moID, $txnID, $smsType) {

        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/

        $sms = $this->convert(utf8_encode($text));
        if(preg_match('/[\W]/', $sms)){
            $sms = json_encode($sms);
        }
        $requestID = "I" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        if(preg_match('/[\W]/', $sms)){
            $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": ' . $sms . '},{"name": "OfferCode","value": "'.$offerCode.'"},{"name": "CpId","value": "86"}]}}';
        }else{
            $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": "' . $sms . '"},{"name": "OfferCode","value": "'.$offerCode.'"},{"name": "CpId","value": "86"}]}}';
        }
        $requestTime = date("Y-m-d H:i:s");
        $requestfile = date("YmdH") . ".txt";
        $logfile = "logs/interactiverequest/" . $requestfile;
        $logData = "----> Interactive Request  Logging Started " . $this->msisdn . "<----" . PHP_EOL;

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);

        $smsArray = [];
        $smsArray['MO_ID'] = $moID;
        $smsArray['TXN_ID'] = $txnID;
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsArray['REQUEST_SENT'] = str_replace('"', "", $interactiveRequest);
        $smsArray['SMS_TYPE'] = $smsType;
        $smsID = $sokaServicesObj->recordData("trivia_interaction_mt_logs", $smsArray);
	if($smsID <=0){

	 $smsID = $sokaServicesObj->recordData("trivia_interaction_mt_logs", $smsArray);
	}
	
        $responseData = [];
        
        if($this->msisdn ==="255712900033"){
            $url = "http://10.222.130.234:8480/api/public/SDP/InteractiveRequest";
        }
        else{
            $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            if ($response) {
                $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ", MO ID : " . $moID . ", BILLING ID : " . $txnID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ", Response : " . $response . ",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
                $responseData['isResponse'] = TRUE;
                $responseData['response'] = $response;
                $responseData['smsID'] = $smsID;
                $responseFile = date("YmdH") . ".txt";
                $logData .= "------>MT Logging Finished<----" . PHP_EOL;
                file_put_contents($logfile, $logData . "\n", FILE_APPEND);
                return $responseData;
            } else {
                $responseData['isResponse'] = false;
                $responseData['response'] = "FAILED";
                $responseData['smsID'] = $smsID;
                $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ", MO ID : " . $moID . ", BILLING ID : " . $txnID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ", Response : TIMEOUT,Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
                $logData .= "------>MT Logging Finished<----" . PHP_EOL;
                file_put_contents($logfile, $logData . "\n", FILE_APPEND);
                return $responseData;
            }
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $ex->getMessage();
            $responseData['smsID'] = $smsID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ", MO ID : " . $moID . ", BILLING ID : " . $txnID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ",Error Response : " . $ex->getMessage() . ",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>MT Logging Finished<----" . PHP_EOL;
            file_put_contents($logfile, $logData . "\n", FILE_APPEND);
            return $responseData;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
        }
        return $responseData;
    }

    function sendReportSMS($text) {
        $offerCode = "017008600010";
    
        $sms = $this->convert(utf8_encode($text));
        $sms = json_encode($sms);
        $requestID = "I" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": ' . $sms . '},{"name": "OfferCode","value": "'.$offerCode.'"},{"name": "CpId","value": "86"}]}}';

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        
        $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            return $response;
        } catch (Exception $ex) {
            return $ex->getMessage();
        }
    }

    function sendSMSApi($text) {
        $offerCode = "017008600010";
    
        $sms = $this->convert(utf8_encode($text));
        $sms = json_encode($sms);
        $requestID = "I" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": ' . $sms . '},{"name": "OfferCode","value": "'.$offerCode.'"},{"name": "CpId","value": "86"}]}}';

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        
        $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            return $response;
        } catch (Exception $ex) {
            return $ex->getMessage();
        }
    }

    function sendActivationRequest() {

        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/

        $sokaServiceObj = new SokaServices("consumer");
//$userAccessToken = $sokaServiceObj->userAccessToken();
        $url = 'http://10.222.10.232:8086/api/public/ACT/Activate';
        $request = [];
        $cpID = 86;
        $password = "Tigo@1234";
        $date = date("YmdHis");
        $encrypted = md5($cpID . $password . $date);
        $requestID = "SU" . date("Ymdhis") . $this->msisdn;
        $activeRequest = '{
"requestId":"W' . $requestID . '",
"requestTimeStamp":"' . $date . '",
"channel":"10",
"sourceAddress":"188.64.188.49",
"operation":"ACTIVATE",
"username":"GreenTelecom",
"password":"' . $encrypted . '",
"requestParam":
{
"data":[{
"name":"OfferCode",
"value":"' . $offerCode . '"
},
{
"name":"Msisdn",
"value":"' . $this->msisdn . '"
},
{
"name":"ShortCode",
"value":"50707"
},
{
"name":"CpId",
"value":"86"
}
]}}
';





        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($activeRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $activeRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $responseData = [];
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
        } else {

            $responseData['isResponse'] = FALSE;
            $responseData['response'] = "FAILED";
        }

        return $responseData;
    }

    function sendMpushYasSubscriptionActivation($ussdData = '') {
        $subscriptionURL = "http://10.222.10.232:8086/api/public/ACT/Activate";
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $offer_code = "017008600010";
        $requestID = "MP" . $date . $this->msisdn;
        $accessToken = $this->token;
        $sokaServicesObj = new SokaServices("consumer");
        $logTable = "tbl_mpush_subs_init_activation_logs_" . date("Ymd");

        $payload = '{
      "requestId": "' . $requestID . '",
      "requestTimeStamp": "' . $date . '",
      "channel": "10",
      "sourceAddress": "188.64.189.37",
      "operation": "ACTIVATE",
      "username": "GreenTelecom",
      "password": "' . $encrypted . '",
      "requestParam": {
        "data": [
          {
            "name": "OfferCode",
            "value": "' . $offer_code . '"
          },
          {
            "name": "Msisdn",
            "value": "' . $this->msisdn . '"
          },
          {
            "name": "CpId",
            "value": "' . $cpID . '"
          }
        ]
      }
    }';

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($payload), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $accessToken);

        $requestFile = date("YmdH") . ".txt";
        $requestLog = "URL: " . $subscriptionURL . "\n"
            . "Headers: " . json_encode($headers) . "\n"
            . "Request: " . $payload . "\n";
        file_put_contents("logs/requests/MpushYasSubsInit-" . $requestFile, $requestLog, FILE_APPEND);

        $activationLog = [];
        $activationLog['MSISDN'] = $this->msisdn;
        $activationLog['REQUEST_ID'] = $requestID;
        $activationLog['REQUEST_SENT'] = trim(preg_replace('/\s+/', ' ', str_replace('"', '', $payload)));
        $activationLog['USSD_DATA'] = $ussdData;
        $logID = $sokaServicesObj->recordData($logTable, $activationLog);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $subscriptionURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseData = [];
        try {
            $response = curl_exec($ch);
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/MpushYasSubsInit-" . $responseFile, "requestId: " . $requestID . "\nResponse: " . $response . "\n", FILE_APPEND);
        } catch (Exception $ex) {
            if ($logID) {
                $sokaServicesObj->_update($logTable, "ID", $logID, [
                    'RESPONSE_RECEIVED' => 'FAILED',
                    'RESP_TIME' => date("Y-m-d H:i:s")
                ]);
            }
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'FAILED : ' . $ex->getMessage();
            $responseData['requestId'] = $requestID;
            return $responseData;
        }

        $responseParam = $this->extractMpushActivationResponseParam($response);
        if ($logID) {
            $sokaServicesObj->_update($logTable, "ID", $logID, [
                'RESPONSE_RECEIVED' => $responseParam,
                'RESP_TIME' => date("Y-m-d H:i:s")
            ]);
        }

        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['requestId'] = $requestID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = "FAILED";
            $responseData['requestId'] = $requestID;
        }

        return $responseData;
    }

    function extractMpushActivationResponseParam($response) {
        if (!$response) {
            return "FAILED";
        }
        $decoded = json_decode($response, true);
        if (!isset($decoded['responseParam']['status'], $decoded['responseParam']['statusCode'])) {
            return "FAILED";
        }
        return json_encode([
            'status' => $decoded['responseParam']['status'],
            'statusCode' => $decoded['responseParam']['statusCode']
        ]);
    }

    function sendMoRetry($moID,$amount) {
        $requestID = "RT" .$moID.date("YmdHis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $this->amount = $amount;
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $paymentData = '{"password" : "' . $encrypted . '","sourceAddress": "188.64.188.49","requestId": "'.$requestID.'","requestTimeStamp": "'.$date.'","channel": "10","requestParam":{"data": [{"name": "OfferCode","value": "017008600005"},{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "TypeOfService","value": "InteractiveService"},{"name": "Language","value": "1"},{"name": "ChargeAmount","value": "' .$amount. '"},{"name": "CpId","value": "86"}]},"operation": "Payment","username": "GreenTelecom"}';
        $requestFile = date("YmdH") . ".txt";
        

        $paymentArray = [];

        $paymentArray['REQUEST_SENT'] = str_replace('"', '', $paymentData);
        $paymentArray['MSISDN'] = $this->msisdn;
        $paymentArray['AMOUNT'] = $this->amount;
        $paymentArray['REQUEST_ID'] = $requestID;
        $paymentArray['REQUEST_HEADER'] = 'X-Authorization: Bearer ' . $this->token;
        $table = "vas_transaction_trivia";
        $txnID = $sokaServicesObj->recordData($table, $paymentArray);
        //$this->token = 'eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJHcmVlblRlbGVjb20iLCJhdWQiOiJBQ0NFU1MiLCJzY29wZXMiOiJBRE1JTiIsImlzcyI6Imh0dHA6Ly9zaXhkZWUuY29tIiwiaWF0IjoxNjY2OTI2MDAxLCJleHAiOjE2Njc3OTAwMDF9.wnP7afh0dmz0kNG__0QDjQmnx7ORpqqmT-nciuCcZxLGcTmwhLY8IZNPhEfzoeGydvrGiLG6SYRFQvQTVsl-RA';

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($paymentData), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        
        file_put_contents("logs/requests/ReryPaymentRequest-" . $requestFile,"Headers :" . json_encode($headers) ." Request : ".$paymentData . " \n", FILE_APPEND);
        $url = "http://10.222.10.232:8086/api/public/PAY/Payment";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/PaymentRequestResponse-" . $responseFile, $response . " \n", FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'FAILED : ' . $ex->getMessage();
            $responseData['txnID'] = $txnID;
            $responseFile = date("YmdH") . ".txt";
            file_put_contents("logs/responses/PaymentErrorResponse-" . $responseFile, $ex->getMessage() . " \n", FILE_APPEND);
            return $responseData;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['txnID'] = $txnID;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'ERROR';
            $responseData['txnID'] = $txnID;
        }

        return $responseData;
    }

   
     function sendopenQuestion($text) {
        $requestID = "OP" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new SokaServices("consumer");
        $sms = $text;
        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        
        $requestFile = "logs/openquestion/".date("YmdH") . ".txt";

        $offerCode = "017008600010";
        /*if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $offerCode = "017008600271";
        }*/
        
        $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": "' . $sms . '"},{"name": "OfferCode","value": "' . $offerCode . '"},{"name": "CpId","value": "86"}]}}';
        
        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($interactiveRequest), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        
        $logData = "----> OpenQuestion  Logging Started " . $this->msisdn . "<----" . PHP_EOL;
        $requestTime = date("Y-m-d H:i:s");
        
        
        $smsArray = [];
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsID = $sokaServicesObj->recordData("tbl_openquestion_logs", $smsArray);
        $url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        try {
            $response = curl_exec($ch);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $ex->getMessage();
            $responseData['smsID'] = $smsID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ", Response : ".$ex->getMessage().",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>OpenQuestion Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
            return true;
        }
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ", Response : ".$response.",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>Openquestion Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
            
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = $response;
            $responseData['smsID'] = $smsID;
            $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $smsID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $interactiveRequest . ", Response : ERROR,Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
            $logData .= "------>Openquestion Logging Finished<----" . PHP_EOL;
            file_put_contents($requestFile, $logData,FILE_APPEND);
            
        }
        return $responseData;
    }

    function sendSms($txnID, $moID, $type, $text) {

        $shortCode = "15670";
        if($this->msisdn === "255679515213" || $this->msisdn === "255713123650" || $this->msisdn === "255773000781" || $this->msisdn === "255674055934"){
            $shortCode = "85105";
        }

        $sms = utf8_encode($text);
        $sokaServicesObj = new SokaServices("consumer");
        $sms = urlencode($sms);
        $url = "http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=$this->msisdn&text=$sms&from=$shortCode&dlr-mask=0&priority=3";

        $smsArray = [];
        $smsArray['MO_ID'] = $moID;
        $smsArray['TXN_ID'] = $txnID;
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsArray['REQUEST_SENT'] = $url;
        $smsArray['SMS_TYPE'] = $type;

        $smsID = $sokaServicesObj->recordData("trivia_interaction_mt_logs", $smsArray);

        $response = file_get_contents($url);
        if ($response) {
            $data['isResponse'] = TRUE;
            $data['response'] = $response;
            $data['smsID'] = $smsID;
        } else {
            $data['isResponse'] = FALSE;
            $data['response'] = "FAILED";
            $data['smsID'] = $smsID;
        }
        return $data;
    }

    function clean($text) {
        $utf8 = array(
            '/[áàâãªä]/u' => 'a',
            '/[ÁÀÂÃÄ]/u' => 'A',
            '/[ÍÌÎÏ]/u' => 'I',
            '/[íìîï]/u' => 'i',
            '/[éèêë]/u' => 'e',
            '/[ÉÈÊË]/u' => 'E',
            '/[æ]/u' => 'ae',
            '/[óòôõºö]/u' => 'o',
            '/[ÓÒÔÕÖ]/u' => 'O',
            '/[úùûü]/u' => 'u',
            '/[ÚÙÛÜ]/u' => 'U',
            '/ç/' => 'c',
            '/Ç/' => 'C',
            '/ñ/' => 'n',
            '/Ñ/' => 'N',
            '/–/' => '-', // UTF-8 hyphen to "normal" hyphen
            '/[’‘‹›‚]/u' => ' ', // Literally a single quote
            '/[“”«»„]/u' => ' ', // Double quote
            '/ /' => ' ', // nonbreaking space (equiv. to 0x160)
        );
        return preg_replace(array_keys($utf8), array_values($utf8), $text);
    }

    function convert($sms) {

        $normalizeChars = array(
            'Š' => 'S', 'š' => 's', 'Ð' => 'Dj', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I',
            'Ï' => 'I', 'Ñ' => 'N', 'Ń' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U',
            'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'å' => 'a', 'æ' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
            'ï' => 'i', 'ð' => 'o', 'ñ' => 'n', 'ń' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y', 'ƒ' => 'f',
            'ă' => 'a', 'î' => 'i', 'â' => 'a', 'ș' => 's', 'ț' => 't', 'Ă' => 'A', 'Î' => 'I', 'Â' => 'A', 'Ș' => 'S', 'Ț' => 'T',
        );

        return strtr($sms, $normalizeChars);
    }

    function is_json($input) {
        $input = trim($input);
        if (substr($input, 0, 1) != '{' OR substr($input, -1, 1) != '}')
            return false;
        return is_array(@json_decode($input, true));
    }

    function staffSeries() {
        $staffArray = array("255715123", "255713123", "255658123", "255673123","255678123");

        foreach ($staffArray as $key) {
            if (strpos($this->msisdn, $key) !== false) {
                return true;
            }
        }
        return false;
    }

}
