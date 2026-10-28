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
    $recordID = $msg['record_id'];
    $deliveryState = processMessage($msisdn,$recordID);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message
    }
};
$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_soka_subscription_activation_router", false, true, false, false);
$channel->queue_bind('tigo_soka_subscription_activation_router', 'triviaExchange', 'tigosoka.subscription.activation.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_soka_subscription_activation_router", "", false, false, false, false, $callback);


while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $recordID) {
    $sokaServicesObj = new SokaServices("consumer");
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken,0,0);
    $subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);
    $responseData = $sokaLib->sendActivationRequest();
    $updateActivation = [];
    if($responseData['isResponse']){
        $updateActivation['RESPONSE_RECEIVED'] = $responseData['response'];
     }
    else{
        $updateActivation['RESPONSE_RECEIVED'] = "FAILED";
        
    }
    $sokaServicesObj->_update("soka_subs_activate_process","SUB_ID", $recordID,$updateActivation);
    $sokaServicesObj->closeDB();
    return TRUE;
}
