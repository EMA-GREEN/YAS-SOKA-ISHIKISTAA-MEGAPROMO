<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of IshikistaaLib
 *
 * @author DELL
 */
//require_once 'IshikistaaServices';
error_reporting(E_ALL);
date_default_timezone_set("Africa/Dar_Es_Salaam");
require_once 'IshikistaaServices.php';

class IshikistaaLib {

    var $amount, $msisdn,$token,$requestID,$referenceID;

    public function __construct($msisdn,$token,$requestID,$referenceID) {
        $this->msisdn = $msisdn;
        $this->token = $token;
        $this->requestID = $requestID;
        $this->referenceID = $referenceID;
    }

//put your code here



function QueryPoints($requestID,$question,$points){
    $responseID = date("Ymdhis").$this->msisdn;
    $responseTimeStamp = date("Ymdhis");    
    $cpID = 86;
    $password = "Tigo@1234";
    $encrypted = md5($cpID.$password.$responseTimeStamp);
    $queryPoints = '{
"requestId": "'.$responseID.'",
"requestTimeStamp": "'.$responseTimeStamp.'",
"channel": "10",
"sourceAddress": "188.64.188.47",
"operation": "QueryPointsResponse",
"username": "GreenTelecom",
"password":"'.$encrypted.'",
"requestParam": {
"data": [
{
"name": "Msisdn",
"value": "'.$this->msisdn.'"
},
{
"name": "Content",
"value": "'.$question.'"
},
{
"name": "OfferCode",
"value": "017008600006"
},
{
"name": "Points",
"value": "'.$points.'"
},
{
"name": "CpId",
"value": "86"
}
]
}
}';
    
     file_put_contents("pointRquest.txt", $queryPoints,FILE_APPEND);

    $headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($queryPoints),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token,"username : GreenTelecom","password :".$encrypted);
    $url = "http://10.222.10.224:8086/api/public/SDP/QueryPointsResponse";
     
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL,$url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $queryPoints);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                try{
                $response = curl_exec($ch);
                file_put_contents("PointsResponse.txt",$response,FILE_APPEND);
                }
                catch(Exception $ex){
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                if($response){
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                }
                else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                return $responseData;
    
}
    function sendCharging($moID,$amount,$isFallback,$transactionRequest="PLAY") {
        if($isFallback){
            if($amount===30){
                
                $requestID = "FF".date("Ymdhis").$this->msisdn;
            }
            else{
                $requestID = "F".date("Ymdhis").$this->msisdn;
            }
        }
        else{
         $requestID = $moID.date("Ymdhis").$this->msisdn;
        }
        $requestFile = "logs/paymentlogs/".date("YmdH").".txt";
        
        $ishikistaaServicesObj = new IshikistaaServices("consumer");

        if($transactionRequest==="PURCHASE"){
            
            $requestID = "PU".$requestID;
            
            $pointPurchaseArray = [];
            $pointPurchaseArray['MO_ID'] = $moID;
            $pointPurchaseArray['MSISDN'] = $this->msisdn;
            $pointPurchaseArray['AMOUNT'] = $amount;
            $pointPurchaseArray['CHANNEL'] = "SMS";
            $pointPurchaseArray['REQUEST_ID'] = $requestID;
            $table = "tbl_point_purchase_" . date("Ymd");
            $ishikistaaServicesObj->recordData($table, $pointPurchaseArray);
        }

        $this->amount = $amount;
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
       $encrypted = md5($cpID.$password.$date);
$paymentData = '{"password" : "'.$encrypted.'","sourceAddress": "188.64.188.49","requestId": "'.$requestID.'","requestTimeStamp": "'.$date.'","channel": "10","requestParam":{"data":[{"name": "OfferCode","value": "017008600007"},{"name": "Msisdn","value": "'.$this->msisdn.'"},{"name": "ReferenceId","value": "'.$this->referenceID.'"},{"name": "TypeOfService","value": "InteractiveService"},{"name": "Language","value": "1"},{"name": "ChargeAmount","value": "'.$this->amount.'"},{"name": "CpId","value": "86"}]},"operation": "Payment","username": "GreenTelecom"}';


$logData = "----> Payment  Request  Logging Started " . $this->msisdn . "<----" . PHP_EOL;
$requestTime = date("Y-m-d H:i:s");


$paymentArray = [];
$paymentArray['MO_ID'] = $moID;
$paymentArray['MSISDN'] = $this->msisdn;
$paymentArray['AMOUNT'] = $this->amount;
$paymentArray['CHANNEL'] = 'SMS';
$paymentArray['REFERENCE_ID'] = $this->referenceID;
$paymentArray['REQUEST_ID'] = $requestID;
$paymentArray['REQUEST_SENT'] = str_replace('"','',$paymentData);
$paymentArray['REQUEST_HEADER'] = 'X-Authorization: Bearer '.$this->token;
$table = "vas_transaction_trivia";
$txnID  = $ishikistaaServicesObj->recordData($table,$paymentArray);       
            $headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($paymentData),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token);           
            $url = "http://10.222.10.232:8086/api/public/PAY/Payment";
            //$renewurl = "http://10.222.10.216:8086/api/public/PAY_AAT/PAY_AAT";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $paymentData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            try{
                $response = curl_exec($ch);
                
                

            }catch(Exception $ex){
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = 'FAILED';
                    $responseData['txnID'] = $txnID;
                    $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $txnID . ", MO ID : " . $moID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $paymentData . ", Response : ".$ex->getMessage().",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
                    $logData .= "------>Payment Logging Finished<----" . PHP_EOL;
                    file_put_contents($requestFile, $logData,FILE_APPEND);
        
            }
        if($response){
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['txnID'] = $txnID;
                    $logData .= "[Request Time : " . $requestTime . ",LOG ID : " . $txnID . ", MO ID : " . $moID . ",RequestID  : " . $requestID . ",Headers :" . json_encode($headers) . ",Request : " . $paymentData . ", Response : ".$response.",Response Time : " . date('Y-m-d H:i:s') . " ]" . PHP_EOL;
                    $logData .= "------>Payment Logging Finished<----" . PHP_EOL;
                    file_put_contents($requestFile, $logData,FILE_APPEND);
        
        }
        else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = 'ERROR';
                    $responseData['txnID'] = $txnID;
                    
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



function sendQuestion($sms,$moID,$txnID,$smsType){
    $requestID = date("Ymdhis").$this->msisdn;
    $ishikistaaServicesObj = new IshikistaaServices("consumer");
    $date = date("YmdHis");
     $cpID = 86;
    $password = "Tigo@1234";
    $encrypted = md5($cpID.$password.$date);
    $interactiveRequest = '{
    "requestId": "'.$requestID.'",
    "requestTimeStamp": "'.$date.'",
    "channel": "10",
    "sourceAddress": "188.64.188.49",
    "operation": "InteractiveRequest",
    "username": "GreenTelecom",
    "password": "'.$encrypted.'",
    "requestParam": {
    "data": [
    {
    "name": "Msisdn",
    "value": "'.$this->msisdn.'"
    },
    {
    "name": "Content",
    "value": "'.$sms.'"
    },
    {
    "name": "OfferCode",
    "value": "017008600009"
    },
    {
    "name": "CpId",
    "value": "86"
    }
    ]
    }
    }';
    

$requestFile = date("YmdH").".txt";
file_put_contents("logs/requests/InteractiveRequest-".$requestFile,$interactiveRequest." \n",FILE_APPEND);

    
$headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($interactiveRequest),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token);

$smsArray = [];
$smsArray['MO_ID'] = $moID;
$smsArray['TXN_ID'] = $txnID;
$smsArray['MSISDN'] = $this->msisdn;
$smsArray['TEXT'] = $sms;
$smsArray['REQUEST_SENT'] = str_replace('"',"",$interactiveRequest);
$smsArray['SMS_TYPE'] = $smsType;
$smsID = $ishikistaaServicesObj->recordData("trivia_sms_logs",$smsArray);
$url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL,$url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                try{
                $response = curl_exec($ch);
                $responseFile = date("YmdH").".txt";
                file_put_contents("logs/requests/InteractiveRequestResponse-".$responseFile,$response." \n",FILE_APPEND);

                }
                catch(Exception $ex){
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                if($response){
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                }
                else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                return $responseData;
}
    


function sendRenewalContent($sms,$moID,$txnID,$smsType){
    $requestID = "RN".date("Ymdhis").$this->msisdn;
    $ishikistaaServicesObj = new IshikistaaServices("consumer");
    $date = date("YmdHis");
     $cpID = 86;
    $password = "Tigo@1234";
    $encrypted = md5($cpID.$password.$date);
    $interactiveRequest = '{
    "requestId": "'.$requestID.'",
    "requestTimeStamp": "'.$date.'",
    "channel": "10",
    "sourceAddress": "188.64.188.49",
    "operation": "InteractiveRequest",
    "username": "GreenTelecom",
    "password": "'.$encrypted.'",
    "requestParam": {
    "data": [
    {
    "name": "Msisdn",
    "value": "'.$this->msisdn.'"
    },
    {
    "name": "Content",
    "value": "'.$sms.'"
    },
    {
    "name": "OfferCode",
    "value": "017008600009"
    },
    {
    "name": "CpId",
    "value": "86"
    }
    ]
    }
    }';
    

$requestFile = date("YmdH").".txt";
file_put_contents("logs/requests/InteractiveRequest-".$requestFile,$interactiveRequest." \n",FILE_APPEND);

    
$headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($interactiveRequest),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token);

$smsArray = [];

$smsArray['MSISDN'] = $this->msisdn;
$smsArray['TEXT'] = $sms;
$smsArray['REQUEST_SENT'] = str_replace('"',"",$interactiveRequest);

$smsID = $ishikistaaServicesObj->recordData("tbl_renewal_content_logs",$smsArray);
$url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL,$url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                try{
                $response = curl_exec($ch);
                $responseFile = date("YmdH").".txt";
                file_put_contents("logs/requests/InteractiveRequestResponse-".$responseFile,$response." \n",FILE_APPEND);

                }
                catch(Exception $ex){
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                if($response){
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                }
                else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                return $responseData;
}
    


function interactiveRequest($sms,$moID,$txnID,$smsType){
    $requestID = date("Ymdhis").$this->msisdn;
    $ishikistaaServicesObj = new IshikistaaServices("consumer");
    $date = date("YmdHis");
     $cpID = 86;
    $password = "Tigo@1234";
    $encrypted = md5($cpID.$password.$date);
    $interactiveRequest = '{
    "requestId": "'.$requestID.'",
    "requestTimeStamp": "'.$date.'",
    "channel": "10",
    "sourceAddress": "188.64.188.49",
    "operation": "InteractiveRequest",
    "username": "GreenTelecom",
    "password": "'.$encrypted.'",
    "requestParam": {
    "data": [
    {
    "name": "Msisdn",
    "value": "'.$this->msisdn.'"
    },
    {
    "name": "Content",
    "value": "'.$sms.'"
    },
    {
    "name": "OfferCode",
    "value": "017008600009"
    },
    {
    "name": "CpId",
    "value": "86"
    }
    ]
    }
    }';
    

$requestFile = date("YmdH").".txt";
file_put_contents("logs/requests/InteractiveRequest-".$requestFile,$interactiveRequest." \n",FILE_APPEND);

    
$headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($interactiveRequest),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token);

$smsArray = [];
$smsArray['MO_ID'] = $moID;
$smsArray['TXN_ID'] = $txnID;
$smsArray['MSISDN'] = $this->msisdn;
$smsArray['TEXT'] = $sms;
$smsArray['REQUEST_SENT'] = str_replace('"',"",$interactiveRequest);
$smsArray['SMS_TYPE'] = $smsType;
$smsID = $ishikistaaServicesObj->recordData("trivia_sms_logs",$smsArray);
$url = "http://10.222.10.232:8086/api/public/INT/InteractiveRequest";
$responseData = [];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL,$url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $interactiveRequest);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                try{
                $response = curl_exec($ch);
                if($response){
                $responseFile = date("YmdH").".txt";
                file_put_contents("logs/requests/InteractiveRequestResponse-".$responseFile,$response." \n",FILE_APPEND);
                $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;    
                    return $responseData;
                }
                else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = "FAILED";
                    $responseData['smsID'] = $smsID;    
                    return $responseData;
                }
                }
                catch(Exception $ex){
                    try{
                        $response = curl_exec($ch);
                        if($response){
                    $responseFile = date("YmdH").".txt";
                    file_put_contents("logs/requests/InteractiveRequestResponse-".$responseFile,$response." \n",FILE_APPEND);
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;    
                    return $responseData;
                            
                        }
                        else{
                            
                        $responseData['isResponse'] = TRUE;
                        $responseData['response'] = "FAILED";
                        $responseData['smsID'] = $smsID;    
                        return $responseData;
                    
                        }
                    }
                    catch(Exception $ex){
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $ex->getMessage();
                    $responseData['smsID'] = $smsID;
                    return $responseData;
                    }
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $ex->getMessage();
                    $responseData['smsID'] = $smsID;
                    return $responseData;
                }
                if($response){
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                else{
                    $responseData['isResponse'] = FALSE;
                    $responseData['response'] = $response;
                    $responseData['smsID'] = $smsID;
                    
                }
                return $responseData;
}
    function sendSms($txnID, $moID, $type, $text) {
        
        
        $sms = utf8_encode($text);
        $ishikistaaServicesObj = new IshikistaaServices("consumer");
        $sms = urlencode($sms);
        $url = "http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=$this->msisdn&text=$sms&from=15633&dlr-mask=0&priority=3";
        
        $smsArray = [];
        $smsArray['MO_ID'] = $moID;
        $smsArray['TXN_ID'] = $txnID;
        $smsArray['MSISDN'] = $this->msisdn;
        $smsArray['TEXT'] = $sms;
        $smsArray['REQUEST_SENT'] = $url;
        $smsArray['SMS_TYPE'] = $type;
        
        $smsID = $ishikistaaServicesObj->recordData("trivia_sms_logs",$smsArray);

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


function sendActivationRequest(){
    
  $ishikistaaServiceObj = new IshikistaaServices("consumer");

$url = 'http://10.222.10.232:8086/api/public/ACT/Activate';
$request = [];
$cpID = 86;
$password = "Tigo@1234";
$date = date("YmdHis");
$encrypted = md5($cpID.$password.$date);
$requestID = "SU".date("Ymdhis").$this->msisdn;
$activeRequest = '{
"requestId":"W'.$requestID.'",
"requestTimeStamp":"'.$date.'",
"channel":"10",
"sourceAddress":"188.64.188.49",
"operation":"ACTIVATE",
"username":"GreenTelecom",
"password":"'.$encrypted.'",
"requestParam":
{
"data":[{
"name":"OfferCode",
"value":"017008600009"
},
{
"name":"Msisdn",
"value":"'.$this->msisdn.'"
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





     $headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($activeRequest),"Cache-Control: no-cache","Pragma: no-cache","X-Requested-With: XMLHttpRequest","X-Authorization: Bearer ".$this->token);


         $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $activeRequest);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $responseData = [];
             if($response){       
                    $responseData['isResponse'] = TRUE;
                    $responseData['response'] = $response;   
             } 
             else{
                 
                 $responseData['isResponse'] = FALSE;
                    $responseData['response'] = "FAILED";
             }
    
            return $responseData;
    
}

    function sendMpushYasSubscriptionActivation($ussdData = '') {
        $subscriptionURL = "http://10.222.10.232:8086/api/public/ACT/Activate";
        $date = date("YmdHis");
        $cpID = "86";
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $requestID = "MP" . $date . $this->msisdn;
        $payload = '{"requestId":"' . $requestID . '","requestTimeStamp":"' . $date . '","channel":"10","sourceAddress":"188.64.188.49","operation":"ACTIVATE","username":"GreenTelecom","password":"' . $encrypted . '","requestParam":{"data":[{"name":"OfferCode","value":"017008600009"},{"name":"Msisdn","value":"' . $this->msisdn . '"},{"name":"CpId","value":"' . $cpID . '"}]}}';
        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($payload), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->token);
        $requestFile = date("YmdH") . ".txt";
        $requestLog = "URL: " . $subscriptionURL . "\n"
            . "Headers: " . json_encode($headers) . "\n"
            . "Request: " . $payload . "\n";
        file_put_contents("logs/requests/MpushYasSubsInit-" . $requestFile, $requestLog, FILE_APPEND);
        $ishikistaaServicesObj = new IshikistaaServices("consumer");
        $table = "tbl_mpush_subs_init_activation_logs_" . date("Ymd");
        $logArray = [];
        $logArray['MSISDN'] = $this->msisdn;
        $logArray['REQUEST_ID'] = $requestID;
        $logArray['REQUEST_SENT'] = $payload;
        $logArray['USSD_DATA'] = $ussdData;
        $logID = $ishikistaaServicesObj->recordData($table, $logArray);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $subscriptionURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $responseFile = date("YmdH") . ".txt";
        file_put_contents("logs/responses/MpushYasSubsInit-" . $responseFile, "requestId: " . $requestID . "\nResponse: " . $response . "\n", FILE_APPEND);
        $responseReceived = "FAILED";
        if ($response) {
            $responseJson = json_decode($response, true);
            if (isset($responseJson['responseParam'])) {
                $responseReceived = json_encode($responseJson['responseParam']);
            }
        }
        $updateArray = [];
        $updateArray['RESPONSE_RECEIVED'] = $responseReceived;
        $updateArray['RESP_TIME'] = date("Y-m-d H:i:s");
        $ishikistaaServicesObj->update($table, "ID", $logID, $updateArray);
        $ishikistaaServicesObj->closeDB();
        $responseData = [];
        $responseData['requestId'] = $requestID;
        if ($response) {
            $responseData['isResponse'] = TRUE;
            $responseData['response'] = $response;
        } else {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = "FAILED";
        }
        return $responseData;
    }

    function clean($text) {
        $utf8 = array(
            '/[áàâãªä]/u' => 'a',
            '/[ÁÀÂÃÄ]/u' => 'A',
            '/[ÍÌÎÏ]/u' => 'I',
            '/[íìîï]/u' => 'i',
            '/[éèêë]/u' => 'e',
            '/[ÉÈÊË]/u' => 'E',
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
   function is_json($input) {
    $input = trim($input);
    if (substr($input,0,1)!='{' OR substr($input,-1,1)!='}')
        return false;
    return is_array(@json_decode($input, true));
}


function sendopenQuestion($text) {
        $requestID = "OP" . date("Ymdhis") . $this->msisdn;
        $sokaServicesObj = new IshikistaaServices("consumer");
        $sms = $text;
        $date = date("YmdHis");
        $cpID = 86;
        $password = "Tigo@1234";
        $encrypted = md5($cpID . $password . $date);
        $requestFile = "logs/openquestion/".date("YmdH") . ".txt";
        $interactiveRequest = '{"requestId": "' . $requestID . '","requestTimeStamp": "' . $date . '","channel": "10","sourceAddress": "188.64.188.49","operation": "InteractiveRequest","username": "GreenTelecom","password": "' . $encrypted . '","requestParam": {"data": [{"name": "Msisdn","value": "' . $this->msisdn . '"},{"name": "Content","value": "' . $sms . '"},{"name": "OfferCode","value": "017008600009"},{"name": "CpId","value": "86"}]}}';
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


function staffSeries(){
$staffArray = array("255715123","255713123","255658123","255673123");

foreach($staffArray as $key){
if(strpos($this->msisdn,$key)!==false){
return true;
}
}
return false;


}

}
