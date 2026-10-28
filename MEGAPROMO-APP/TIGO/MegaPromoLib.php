<?php

namespace MegaPromo\MegaPromoLibrary;

use MegaPromo\DatabaseServices AS DatabaseServices;

class MegaPromoLib {

    private $db, $msisdn, $appConfig, $airtimeToken;

    public function __construct($msisdn) {
        $this->msisdn = $msisdn;
        $this->appConfig = $this->loadfile('app.config');
        $this->dbfile = $this->loadfile('db.config');
        $this->db = new DatabaseServices\TigoMeaPromoDatabaseServices($this->dbfile['PUBLISHER_USER'], $this->dbfile['DB_PASSWORD'], $this->dbfile['DB_NAME'], $this->dbfile['DB_HOST']);
        $this->airtimeToken = $this->db->airtimeAccessToken();
    }

    function extract_numbers($string) {
        preg_match_all('/([\d]+)/', $string, $match);
        return $match[0];
    }

    function loadfile($file) {
        $result = file_get_contents($file);
        $data = parse_ini_string($result);
        return $data;
    }

    function sendCharging($moID, $requestID, $amount, $paymentMethod, $requestType) {

        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $request = $this->appConfig['AIRTIME_BILLING_REQUEST'];
        $request = str_replace("encrypted", $encrypted, $request);
        $request = str_replace("mobileNUM", $this->msisdn, $request);
	$request = str_replace("requestID", $requestID, $request);
        $request = str_replace("date", $date, $request);
        $json = str_replace("billamount", $amount, $request);
	$logfile = "MegaPromoPaymentRequestResponse-".date("YmdH").".txt";
        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($json), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->airtimeToken);
	$requestTime  = date("Y-m-d H:i:s");
        $logData = "---->Payment Logging Started ".$this->msisdn."<----".PHP_EOL;

        try {
            $paymentData = [];
            $paymentData['MSISDN'] = $this->msisdn;
            $paymentData['MO_ID'] = $moID;
            $paymentData['AMOUNT'] = $amount;
            $paymentData['TXN_ID'] = $requestID;
            $paymentData['STATUS_CODE'] = 'SENT';
            $paymentData['PAYMENT_METHOD'] =$paymentMethod;
            $paymentData['REQUEST_TYPE'] = $requestType;
            $txnID = $this->db->__insert('tbl_promo_billing', $paymentData);
            } catch (Exception $ex) {
            $finaldecdata['isResponse'] = FALSE;
            $finaldecdata['response'] = "PROCESS_FAILED";
            $finaldecdata['txnID'] = 0;
            $errorFile = "BillingRecordError-".date("YmdH").".txt";
	    $request = trim(preg_replace('/\s+/', '', $json));
            $logData .= "[Request Time : ".$requestTime.",RequestID  : ".$requestID.",Headers :".json_encode($headers).",Request : ".$request.", Response : ".$ex->getMessage()." ]".PHP_EOL;
            $logData .= "------>Payment Logging Finished<----".PHP_EOL;
            //$request = trim(preg_replace('/\s+/', '', $json));
            file_put_contents("logs/interactivepayments/".$errorFile, $logData."\n" , FILE_APPEND);
            return $finaldecdata;
        }
      
        
        $url = $this->appConfig['AIRTIME_BILLING_URL'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        if ($data) {
            $finaldecdata['isResponse'] = TRUE;
            $finaldecdata['response'] = $data;
            $finaldecdata['txnID'] = $txnID;
        } else {
            $finaldecdata['isResponse'] = FALSE;
            $finaldecdata['response'] = "PROCESS_FAILED";
            $finaldecdata['txnID'] = $txnID;
        }
        
        
        curl_close($ch);$responseTime  = date("Y-m-d H:i:s");
        $request = trim(preg_replace('/\s+/', '', $json));
        $logData .= "[Request Time : ".$requestTime.", RecordID  : ".$txnID.",Headers :".json_encode($headers).",Request : ".$request.", Response Time : ".$responseTime.",Response : ".$data."]".PHP_EOL;
        $logData .= "---->Payment Logging Finished ".$this->msisdn."<----".PHP_EOL;
        file_put_contents("logs/interactivepayments/".$logfile, $logData."\n" , FILE_APPEND);
        return $finaldecdata;
    }

    function sendSMS($moID,$templateID, $billingID, $sms, $smsType) {
        $mtUrl = $this->appConfig['MT_API_REQUEST'];
	
        $requestTime  = date("Y-m-d H:i:s");
        $logfile = "MessageLogs-".date("YmdH").".txt";
        $logData = "----> MT Logging Started ".$this->msisdn."<----".PHP_EOL;
        
        $mtUrl = str_replace("mobile", $this->msisdn, $mtUrl);
        $mtUrl = str_replace("=sms", "=".urlencode($sms), $mtUrl);
	
	
        $smsInfo = [];
        $smsInfo['MO_ID'] = $moID;
        $smsInfo['TXN_ID'] = $billingID;
        $smsInfo['TEMPLATE_ID'] = $templateID;
        $smsInfo['MSISDN'] = $this->msisdn;
        $smsInfo['SMS_TYPE'] = $smsType;
        $smsID = $this->db->__insert('tbl_promo_mt_logs', $smsInfo);
        $response = file_get_contents($mtUrl);
        
        
        $logData .= "[Request Time : ".$requestTime.",Billing ID  : ".$billingID.",Text : ".$sms.", Mo ID :".$moID.", Sms Type : ".$smsType.", Response : ".$response." ]".PHP_EOL;
        $logData .= "------>MT Logging Finished ".$this->msisdn."<----".PHP_EOL;
        file_put_contents("logs/messagelogs/".$logfile, $logData."\n" , FILE_APPEND);	
        
        
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

function getBoomCustomerInfo($msisdn) {
    $boom_url = $this->appConfig['CHECK_BOOM_ID_URL'];
    $username = $this->appConfig['BOOM_USERNAME'];
    $password = $this->appConfig['BOOM_PASSWORD'];
    $access_token = $this->getBoomAccessToken($username, $password);
    $reference_id = date("YmdHis")."".$msisdn;

    $payload_arr = [
        "MSISDN" => $msisdn,
        "ReferenceID" => $reference_id
    ];

    $payload = json_encode($payload_arr);

    $headers = array("Method:POST", "Content-Type: application/json", "Cache-Control: no-cache", "Authorization: Bearer ".$access_token, "Username: ".$username);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $boom_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    $response_data = json_decode($response, true);

    $boomCustomerInfo = "";

    if (json_last_error() === JSON_ERROR_NONE && isset($response_data['ResponseCode'])) {
        $boomCustomerInfo = $response_data;
    }

    return $boomCustomerInfo;

}

function getBoomAccessToken($username, $password) {
    $accessToken = "";
    $token_url = $this->appConfig['BOOM_TOKEN_URL'];

    $headers = array("Method:POST", "Content-Type: application/json", "Cache-Control: no-cache");

    $payload_arr = [
        "UserName" => $username,
        "Password" => $password
    ];

    $payload = json_encode($payload_arr);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch); 

    $data = json_decode($response, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($data['access_token'])) {
        $accessToken = $data['access_token'];
    } 

    return $accessToken;  
}

function is_json($input) {
    $input = trim($input);
    if (substr($input,0,1)!='{' OR substr($input,-1,1)!='}')
        return false;
    return is_array(@json_decode($input, true));
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


function getTigoAccessToken($recordID){
        $url = $this->appConfig['TOKEN_URL'];
        $body  = $this->appConfig['TOKEN_CRED'];
        
        $logfile = "logs/tigopesa/MegaPromoTigoPesaRequestResponse-".date("YmdH").".txt";
        $requestTime = date("Y-m-d H:i:s");
        $logData = "------>TokenRequest Logging Started ".$this->msisdn."<----".PHP_EOL;
        $headers = array(
            "Method:POST",
            "Content-Type: text/xml;charset=\"utf-8\"",
            "Content-length: " . strlen($body),
            "Cache-Control: no-cache",
            "Pragma: no-cache"
        );
        
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            try{
            $response = curl_exec($ch);
            $responseTime = date("Y-m-d H:i:s");
            
            if ($response) {
                if(!json_decode($response,TRUE)){//response is not json    
                $data['isResponse'] = FALSE;
                $data['response'] = "TOKEN_REQUEST_FORMAT_ERROR";
                $data['responseLevel'] = "TOKEN";
                $data['status'] = "TOKEN_REQUEST_FORMAT_ERROR";
                $data['txnID'] = $recordID;
                }
                else{// response is in json format
                $data['isResponse'] = TRUE;
                $data['response'] = $response;
                $data['responseLevel'] = "TOKEN";
                $data['status'] = "TOKEN_REQUEST";
                $data['txnID'] = $recordID;
                }
            } else {
                $data['isResponse'] = FALSE;
                $data['response'] = "FAILED";
                $data['responseLevel'] = "TOKEN";
                $data['status'] = "TOKEN_REQUEST_FAILED";
                $data['txnID'] = $recordID;
            }
            }
            catch(Exception $ex){
                $responseTime = date("Y-m-d H:i:s");
                $data['isResponse'] = TRUE;
                $data['response'] = "FAILED";
                $data['responseLevel'] = "TOKEN";
                $data['status'] = "TOKEN_REQUEST";
                $data['txnID'] = $recordID;
                $response = "TIMEOUT";
            }
            $logData .= "[Request Time : ".$requestTime.",RequestType : TOKEN REQUEST, Billing ID  : ".$recordID.", Response : ".$response.", ResponseTIme : ".$responseTime."]".PHP_EOL;
            $logData .= "------>Token Logging Finished ".$this->msisdn."<----".PHP_EOL;
            file_put_contents($logfile, $logData,FILE_APPEND);
            return $data;
        } 
    
    function  tigopesaPush($txnID,$recordID,$amount){//generate Token
        $userAccessToken = $this->getTigoAccessToken($recordID);
	
        if(!$userAccessToken['isResponse']){
            $finaldecdata['isResponse'] = FALSE;
            $finaldecdata['response'] = $userAccessToken['response'];
            $finaldecdata['responseLevel'] = "TOKEN";
            $finaldecdata['status'] = "TOKEN_FAILED";
            $finaldecdata['txnID'] = $recordID;
            return $finaldecdata;
        }
         $useraccessTokenArray = json_decode($userAccessToken['response'],TRUE);
	
      if(isset($useraccessTokenArray['access_token'])){
       $token = $useraccessTokenArray['access_token'];
       $txnJsonArray['CustomerMSISDN'] = $this->msisdn;
       $txnJsonArray['BillerMSISDN']=  "25565744466";
       $txnJsonArray['Remarks']=  "Mega Promo";
       $txnJsonArray['Amount'] = $amount;
       $txnJsonArray['ReferenceID']=  $txnID;
       $txnJson = json_encode($txnJsonArray); 
       $headers = array("Method:POST","Content-Type: application/json","Content-length: " . strlen($txnJson),"Cache-Control: no-cache","Pragma: no-cache","username:GreenTMegaPromo","password:pl19ARK","Authorization: bearer ".$token);
       
       $logfile = "logs/tigopesa/MegaPromoTigoPesaRequestResponse-".date("YmdH").".txt";
       $requestTime = date("Y-m-d H:i:s");
       $logData = "------>PushRequest Logging Started ".$this->msisdn."<----".PHP_EOL;
        
       
       
       
       
       $url = $this->appConfig['TIGOPESA_PUSH_URL'];
       $ch = curl_init();
       curl_setopt($ch, CURLOPT_URL, $url);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
       curl_setopt($ch, CURLOPT_TIMEOUT, 10);
       curl_setopt($ch, CURLOPT_POST, true);
       curl_setopt($ch, CURLOPT_POSTFIELDS, $txnJson);
       curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            try{
            $response = curl_exec($ch);
	    $logData .= "[Request Time : ".$requestTime.",RequestType : PUSH REQUEST, Billing ID  : ".$recordID.",Request : ".$txnJson.", Response : ".$response.", ResponseTIme : ".date('Y-m-d H:i:s')."]".PHP_EOL;
	     $logData .= "------>PushRequest Logging Finished ".$this->msisdn."<----".PHP_EOL;	
	    file_put_contents($logfile, $logData,FILE_APPEND);
            if($response){
                if(!json_decode($response,TRUE)){//response is not in json
                    $isResponse=FALSE;
                }
                else{
                    $isResponse=TRUE;
                }
            }
            else{
                    $isResponse=FALSE;
                    $response = "FAILED";   
            }
            $finaldecdata['isResponse'] = $isResponse;
            $finaldecdata['response'] = $response;
            $finaldecdata['responseLevel'] = "PAYMENT";
            $finaldecdata['status'] = "PAYMENT_REQUEST_SENT";
            $finaldecdata['txnID'] = $recordID;
            
            
            
            return $finaldecdata;

            }catch(Exception $ex){
            $finaldecdata['isResponse'] = TRUE;
            $finaldecdata['response'] = $ex->getMessage();
            $finaldecdata['responseLevel'] = "PAYMENT";
            $finaldecdata['status'] = "PAYMENT_REQUEST_FAILED";
            $finaldecdata['txnID'] = $recordID;
            
            
             $logData .= "------>PushRequest Logging Finished ".$this->msisdn."<----".PHP_EOL;
            file_put_contents($logfile, $logData,FILE_APPEND);
            return $finaldecdata;
            }

        }

    }

    function sendMpushYasSubscriptionActivation() {
        $subscriptionURL = $this->appConfig['MPUSH_YAS_ACTIVATE_URL'];
        $date = date("YmdHis");
        $password = "Tigo@1234";
        $cpID = "86";
        $encrypted = md5($cpID . $password . $date);
        $offerCode = $this->appConfig['MPUSH_YAS_OFFER_CODE'];
        $requestID = "MP" . $date . $this->msisdn;

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
            "value": "' . $offerCode . '"
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

        $headers = array("Method:POST", "Content-Type: application/json", "Content-length: " . strlen($payload), "Cache-Control: no-cache", "Pragma: no-cache", "X-Requested-With: XMLHttpRequest", "X-Authorization: Bearer " . $this->airtimeToken);
        $requestFile = date("YmdH") . ".txt";
        file_put_contents("logs/requests/MpushYasSubsInit-" . $requestFile, "URL: " . $subscriptionURL . "\nHeaders: " . json_encode($headers) . "\nRequest: " . $payload . "\n", FILE_APPEND);

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
            file_put_contents("logs/responses/MpushYasSubsInit-" . $requestFile, "requestId: " . $requestID . "\nResponse: " . $response . "\n", FILE_APPEND);
        } catch (Exception $ex) {
            $responseData['isResponse'] = FALSE;
            $responseData['response'] = 'FAILED : ' . $ex->getMessage();
            $responseData['requestId'] = $requestID;
            curl_close($ch);
            return $responseData;
        }
        curl_close($ch);

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

    
}
