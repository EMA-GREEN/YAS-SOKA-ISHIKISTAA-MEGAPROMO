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
    $logID = $msg['record_id'];
    $deliveryState = processMessage($msisdn, $logID);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_ishikistaa_mo_retrycharge_router", false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_mo_retrycharge_router', 'triviaExchange', 'tigosoka.ishikistaa.mo.retrycharge.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_ishikistaa_mo_retrycharge_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn,$logID) {

$ishikistaaServicesObj = new SokaServices("cosumer");
$token = $ishikistaaServicesObj->userAccessToken();
$ishikistaaLibObj =  new SokaLib($msisdn,$token,0,0);
$data = $ishikistaaLibObj->sendMoRetry(0, 99);

if ($data['isResponse']) {//    
                if (strpos($data['response'], 'Trivia payment failed') !== false) {
                    updatePaymentStatus($ishikistaaServicesObj, $data);
                    $ishikistaaServicesObj->closeDB();
                    return TRUE;
                }
                else{
                updatePaymentStatus($ishikistaaServicesObj, $data);
                $ishikistaaServicesObj->closeDB();
                return true;
                }
            }
            else{
                
                updatePaymentStatus($ishikistaaServicesObj, $data);
                $ishikistaaServicesObj->closeDB();
                return true;
            }
    
    
    
}
function updatePaymentStatus($ishikistaaServicesObj, $data = []) {
    $txnID = $data['txnID'];
    $responseCode = json_decode($data['response'], TRUE);
    $responseReceived = $data['response'];
    if (isset($responseCode['responseParam']['status']) && $responseCode['responseParam']['status'] == 0) {
        $msgStatus = "ACKNOWLEDGED";
        $return = TRUE;
    } else {
        if (isset($responseCode['responseParam']['status'])) {
            $msgStatus = $responseCode['responseParam']['status'];
        } else {
            $msgStatus = 'FAILED';
        }
        $return = FALSE;
    }
    $table = "vas_transaction_trivia_mo_retry";
    $paymentArray = [];
    $paymentArray['RESPONSE_RECEIVED'] = $msgStatus;
    $paymentArray['TXN_STATUS'] = $msgStatus;
    $paymentArray['RESP_TIME'] = date("Y-m-d H:i:s");
    $ishikistaaServicesObj->_update($table, "ID", $txnID, $paymentArray);
    return $return;
}

