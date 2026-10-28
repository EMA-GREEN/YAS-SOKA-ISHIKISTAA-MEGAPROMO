<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * Consumer: processes mpush YAS Ishikistaa subs init queue and calls ACT/Activate.
 */
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

$callback = function ($data) {
    $msg = json_decode($data->body, TRUE);
    $msisdn = $msg['msisdn'];
    $recordID = $msg['record_id'];
    $ussdData = isset($msg['ussd_data']) ? $msg['ussd_data'] : '';
    $deliveryState = processMessage($msisdn, $recordID, $ussdData);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']);
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']);
    }
};

$channel->exchange_declare("triviaExchange", "direct", false, true, false);
$channel->queue_declare("tigo_ishikistaa_mpush_yas_subs_init_router", false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_mpush_yas_subs_init_router', 'triviaExchange', 'tigoishikistaa.mpush.yas.subs.init.key');
$channel->basic_qos(null, QOS_LIMIT, null);
$channel->basic_consume("tigo_ishikistaa_mpush_yas_subs_init_router", "", false, false, false, false, $callback);

while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn, $recordID, $ussdData = '') {
    $ishikistaaServicesObj = new IshikistaaServices("consumer");
    $userAccessToken = $ishikistaaServicesObj->userAccessToken();
    $ishikistaaLib = new IshikistaaLib($msisdn, $userAccessToken, 0, 0);
    $ishikistaaLib->sendMpushYasSubscriptionActivation($ussdData);
    $ishikistaaServicesObj->closeDB();
    return TRUE;
}
