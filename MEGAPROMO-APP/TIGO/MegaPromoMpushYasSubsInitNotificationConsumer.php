<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * Consumer: processes mpush YAS MegaPromo subs init queue and calls ACT/Activate.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once 'TigoMegaPromoDatabaseServices.php';
require_once 'MegaPromoLib.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;

try {
    $lib = new MegaPromoLibrary\MegaPromoLib(0);
    $appConfig = $lib->loadfile('app.config');
    $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'], $appConfig['QUEUE_PASSWORD']);
    $channel = $connection->channel();
} catch (Exception $ex) {
    echo $ex->getMessage();
    echo $ex;
}

$callback = function ($data) {
    $msg = json_decode($data->body, TRUE);
    $msisdn = $msg['msisdn'];
    $deliveryState = processMessage($msisdn);
    if ($deliveryState) {
        $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']);
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']);
    }
};

$channel->exchange_declare($appConfig['EXCHANGE_NAME'], "direct", false, true, false);
$channel->queue_declare($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['MPUSH_YAS_SUBS_INIT_KEY']);
$channel->basic_qos(null, $appConfig['QOS_LIMIT'], null);
$channel->basic_consume($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], "", false, false, false, false, $callback);

while (count($channel->callbacks)) {
    $channel->wait();
}

$connection->close();
$channel->close();

function processMessage($msisdn) {
    $lib = new MegaPromoLibrary\MegaPromoLib($msisdn);
    $lib->sendMpushYasSubscriptionActivation();
    return TRUE;
}
