<?php

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

require_once 'IshikistaaServices.php';

$connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
$channel = $connection->channel();
$channel->exchange_declare('triviaExchange', 'direct', false, true, false);
$channel->queue_declare('tigo_ishikistaa_mosubscription_router', false, true, false, false);
$channel->queue_bind('tigo_ishikistaa_mosubscription_router', 'triviaExchange', 'tigoishikistaa.subscription.messages.key');
while (true) {

    $isError = FALSE;

    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try {
            $connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
            $channel = $connection->channel();
            $channel->exchange_declare('triviaExchange', 'direct', false, true, false);
            $channel->queue_declare('tigo_ishikistaa_mosubscription_router', false, true, false, false);
            $channel->queue_bind('tigo_ishikistaa_mosubscription_router', 'triviaExchange', 'tigoishikistaa.subscription.messages.key');
        } catch (Exception $ex) {
            $isError = FALSE;
            echo $ex->getMessage();
            sleep(1);
        }
    }
    if (!$isError) {
        try {
            $ishikistaaaServicesObj = new IshikistaaServices("publisher");
            $data = $ishikistaaaServicesObj->getSubscriptionNotification();
            if (empty($data)) {
               $ishikistaaaServicesObj->closeDB();
                $id = 0;
                sleep(1);
            } else {
                $max_id = end($data);
                $id = $max_id['ID'];
                for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $requestType = $data[$j]['REQUEST_TYPE'];
                    $record_id = $data[$j]['ID'];
                    $str = array("msisdn" => $mobile, "requesttype"=>$requestType);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', 'tigo_ishikistaa_mosubscription_router');
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_subscription_notifications";
                    $ishikistaaaServicesObj->_update($table, $column, $value, $updateArray);
                }
                $ishikistaaaServicesObj->closeDB();
                sleep(1);
            }
        } catch (Exception $ex) {
            sleep(1);
        }
    } else {
        sleep(1);
    }
}
