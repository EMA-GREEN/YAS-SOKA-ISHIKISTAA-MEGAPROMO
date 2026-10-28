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
require_once 'IshikistaaLib.php';
require_once 'IshikistaaServices.php';


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
    $requestID = $msg['requestid'];
    $deliveryState = processMessage($msisdn, $txnstatus, $requestID, $paymentDetails);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_ishikistaa_renewal_router", false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_renewal_router', 'triviaExchange', 'tigoishikistaa.renewal.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_ishikistaa_renewal_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $txnStatus, $requestID, $paymentDetails) {
    $ishikistaaServicesObj = new IshikistaaServices("consumer");
    $renewalServicesObj = new IshikistaaServices("renewal");
    
    if($txnStatus==="SUCCESS"){
    $subDetails = $ishikistaaServicesObj->getSubscriberDetails($msisdn);
    $credit = $subDetails['CREDIT'];
    $subInfoArray = [];
    $subInfoArray['LAST_ISHIKISTAA_RENEWAL_BILLING'] = date("Y-m-d  H:i:s");
    $subInfoArray['LAST_ISHIKISTAA_RENEWAL_ACTIVE'] = date("Y-m-d  H:i:s");
    $subInfoArray['LAST_ISHIKISTAA_RENEWAL_UPDATE'] = date("Y-m-d H:i:s");
    //$subInfoArray['CREDIT'] = $credit+1;
    $ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subInfoArray);
    $callbackArray = [];
    $callbackArray['MW_BILLING_STATUS'] = 'SUCCESS';
    $where =  "TRANSACTION_ID";
    $value = $requestID;
    $return  = $renewalServicesObj->_update("tigo_renewal_responses",$where,$value,$callbackArray);///update middleware response
    $renewalSubscriber = [];
    $renewalSubscriber['RETRY_STATUS'] = 'OFF';
    $renewalSubscriber['LAST_RENEWAL_BILLING'] = date("Y-m-d H:i:s");
    $where = "SERVICE_ID";
    $value = "ISHIKISTAA";
    $table = "tbl_renewal_middleware";
    $renewalServicesObj->updateRenewal($table, $where, $value, $renewalSubscriber,$msisdn);
    }
    
    else{
    
    $callbackArray = [];
    $callbackArray['MW_BILLING_ID'] = '0';
    $callbackArray['MW_BILLING_STATUS'] = 'FAILED';
    $where =  "TRANSACTION_ID";
    $value = $requestID;    
    $return = $renewalServicesObj->_update("tigo_renewal_responses",$where,$value,$callbackArray);///update middleware response
    $updateArray = [];
    $updateArray['RETRY_STATUS'] = 'ON';
    $table = 'tbl_renewal_middleware';
    $where = "SERVICE_ID";
    $value = "ISHIKISTAA";
    $renewalServicesObj->updateRenewal($table, $where, $value, $updateArray,$msisdn);
    }
    $ishikistaaServicesObj->closeDB();
    $renewalServicesObj->closeDB();
    return true;            
}




