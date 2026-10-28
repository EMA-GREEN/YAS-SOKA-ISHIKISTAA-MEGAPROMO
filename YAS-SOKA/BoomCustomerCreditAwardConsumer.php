<?php
/**
 * User: EMA-GREEN
 * Date: 31/05/2024
 * Time: 12:12 PM
 */
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once __DIR__ . "/vendor/autoload.php";
require __DIR__.'/BoomCustomerCreditAwardMessageHandler.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$boomcreditkey = "boomcuster.credit.award.key";
$boomcreditqueue = "boom_cust_credit_award";
$boomcreditexchange = "triviaExchange";

$connection;
$channel;

try {
    $connection = new AMQPStreamConnection('127.0.0.1', 5672, 'tigo_manage', 'manage@CNu8Ut');
    $channel = $connection->channel();
    $channel->exchange_declare($boomcreditexchange, "direct", false, true, false);

    $channel->queue_declare($boomcreditqueue, false, true, false, false);
    $channel->queue_bind($boomcreditqueue, $boomcreditexchange, $boomcreditkey);
} catch(Exception $e) {
    app_log('Errors in AMQPStreamConnection due to: ' .$e->getMessage());
}

$callback = function ($msg) {
    processMessage($msg);
};

try {
    $channel->basic_qos(null, 10, null);
    $channel->basic_consume($boomcreditqueue, '', false, false, false, false, $callback);
} catch(Exception $e) {
    app_log('Errors in message consumption due to: ' .$e->getMessage());
}

while (count($channel->callbacks)) {
    $channel->wait();
}

$channel->close();
$connection->close();

function processMessage($msg){
    $message_body = $msg->body;
    $message = json_decode($message_body);
    //consume message
    $response_status = BoomCustomerCreditAwardMessageHandler::processor()->process($message);
    if($response_status == "0"){
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
    }else{
        $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag']);
    }
}

function app_log($data){
    $time = date("Y-m-d H:i:s");
    $date_ext = date("YmdH").".txt";
    $data = $time." ".$data;
    file_put_contents("logs/boomcustomer/BoomCustomerCreditAwardConsumer-".$date_ext, $data." \n", FILE_APPEND);
}