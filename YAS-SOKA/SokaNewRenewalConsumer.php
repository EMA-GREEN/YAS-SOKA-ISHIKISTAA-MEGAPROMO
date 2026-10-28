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
require_once 'SokaLib.php';
require_once 'SokaServices.php';


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
    $txnstatus = $msg['txnstatus'];
    $paymentDetails = $msg['paymentdetail'];
    $promoStatus = $msg['promoStatus'];
    $requestID = $msg['requestid'];
    $deliveryState = processMessage($msisdn, $txnstatus, $requestID, $paymentDetails,$promoStatus);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_renewal_new_router", false, true, false, false);
$channel->queue_bind('tigo_soka_renewal_new_router', 'triviaExchange', 'tigosoka.renewal.new.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_renewal_new_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails,$promoStatus) {
    
    $sokaServicesObj = new SokaServices("consumer");
    $renewalServicesObj = new SokaServices("renewal");
    $userAccessToken = $sokaServicesObj->userAccessToken();
        $sokaLib = new SokaLib($msisdn, $userAccessToken, 0, 0); //create soka libe and define amount
        
        
        if (strpos($requestID, 'WF') !== false||strpos($requestID, 'w') !== false) {
            $wheresub = "1";
            $wherevalue = "1";
            $renewalType = "FALLBACK";
            $rntxntable  = "tbl_tigo_soka_fallback_renewal_transaction";
            $renewalSubscriberTable = "tbl_soka_fallback_renewal_middleware";
            
        }
        else{
            $wheresub = "1";
            $wherevalue = "1";
            $renewalType = "RENEWAL";
            $rntxntable  = "tbl_tigo_soka_renewal_transaction";
            $renewalSubscriberTable = "tbl_soka_renewal_middleware";
            
        }
    if($txnStatus==="SUCCESS"){
        
    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);
    $credit = $subDetails['CREDIT'];
    $subInfoArray = [];
    $subInfoArray['LAST_SOKA_RENEWAL_BILLING'] = date("Y-m-d  H:i:s");
    $subInfoArray['LAST_SOKA_RENEWAL_ACTIVE'] = date("Y-m-d  H:i:s");
    $subInfoArray['LAST_SOKA_RENEWAL_UPDATE'] = date("Y-m-d H:i:s");
    if(date("H") <=22 &&  date("H")>= 7){
    $subInfoArray['CREDIT'] = $subDetails['CREDIT']+1;    
    $sms = "HONGERA kwa BAHATI ya KETE 1 SASA! Jibu swali 1 BUREE ingia kwenye DROO YA KUSHINDA sehemu ya Tsh 24,800,000 PAPO HAPO. Nyota ya USHINDI inaweza kuwa YAKO SASA!";
    $smsstatus = $sokaLib->sendRenewalContent($sms, 0, 0, "REN_CONTENT");
    if ($smsstatus['isResponse']) {
        updateSMSStatus($sokaServicesObj, $smsstatus);
    } else {
        updateSMSStatus($sokaServicesObj, $smsstatus);
        
    }
    }
    
	if(strpos($requestID,'RW')!==false){
		$subtable  = "tbl_qn_subscribers";

	}
	else{
	       
	 $subtable  = "tbl_qn_subscribers";
	}
	
	
    $sokaServicesObj->_update($subtable, "MSISDN", $msisdn, $subInfoArray);
    
    
    
    
    
    
    
    $callbackArray = [];
    $callbackArray['MW_BILLING_STATUS'] = 'SUCCESS';
    $callbackArray['RESPONSE_RECEIVED_TIME'] = date("Y-m-d H:i:s");
    $where =  "TRANSACTION_ID";
    $value = $requestID;
	if(strpos($requestID,'RW')!==false){
	$txntable = "tbl_tigo_mo_subscriber_charge_transaction";

	}	
	else{
	$txntable = $rntxntable;
	}
    $return  = $renewalServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
    $renewalSubscriber = [];
    $renewalSubscriber['RETRY_STATUS'] = 'OFF';
    $renewalSubscriber['RENEWAL_STATUS'] = 'INACTIVE';
    $renewalSubscriber['RN_PUBLISHING_STATUS'] = 'OFF';
    $renewalSubscriber['LAST_RENEWAL_BILLING'] = date("Y-m-d H:i:s");
    $where = $wheresub;
    $value = $wherevalue;
      if(strpos($requestID,'RW')!==false){

	$table  = "tbl_tigo_mo_subscriber_middleware";
	}	
	else{
	    $table = $renewalSubscriberTable;
	}
    $renewalServicesObj->updateRenewal($table, $where, $value, $renewalSubscriber,$msisdn);
    }
    else{    
        if(strpos($paymentDetails,"Subscriber not active")!==false||strpos($paymentDetails,"Subscriber does not exist")!==false){
             $subInfoArray = [];
             $subInfoArray['ACTIVITY_STATUS'] = "INACTIVE";
             $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subInfoArray);
   }
        
        
        
    if(date("H")<=7){
        
        $nextRetrialPeriod  = date("Y-m-d H:i:s",strtotime("+10 minutes"));
        
    }
    else{
        
        $nextRetrialPeriod  = date("Y-m-d H:i:s",strtotime("+5 minutes"));
        
    }
    $callbackArray = [];
    $callbackArray['MW_BILLING_ID'] = '0';
    $callbackArray['MW_BILLING_STATUS'] = $paymentDetails;
    $callbackArray['RESPONSE_RECEIVED_TIME'] = date("Y-m-d H:i:s");
    $where =  "TRANSACTION_ID";
    $value = $requestID;    
	if(strpos($requestID,'RW')!==false){
	$txntable  = "tbl_tigo_mo_subscriber_charge_transaction";

	}
	else{
	$txntable = $rntxntable;
	}
    $return = $renewalServicesObj->_update($txntable,$where,$value,$callbackArray);///update middleware response
    $updateArray = [];
    $updateArray['RETRY_STATUS'] = 'ON';
    $updateArray['NEXT_RETRIAL_PERIOD'] = $nextRetrialPeriod;
	if(strpos($requestID,'RW')!==false){
		$table  = "tbl_tigo_mo_subscriber_middleware";
	}
	else{
    $table = $renewalSubscriberTable;
	}
    $where = $wheresub;
    $value = $wherevalue;
    
    $renewalServicesObj->updateRenewal($table, $where, $value, $updateArray,$msisdn);
    }
    $sokaServicesObj->closeDB();
    $renewalServicesObj->closeDB();
    return true;            
}

function updateSMSStatus($sokaServicesObj, $smsstatus = []) {
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
    $table = "tbl_renewal_content_logs";
    $smsArray = [];
    $smsArray['RESPONSE_TIME'] = date("Y-m-d H:i:s");
    $smsArray['RESPONSE_RECEIVED'] = $responseReceived;
    $smsArray['STATUS_CODE'] = $msgStatus;
    $sokaServicesObj->_update($table, "ID", $smsstatus['smsID'], $smsArray);
    return $return;
}




