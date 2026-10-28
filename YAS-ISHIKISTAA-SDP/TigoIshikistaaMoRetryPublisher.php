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

require_once 'SokaServices.php';
$connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
$channel = $connection->channel();
$channel->exchange_declare('triviaExchange', 'direct', false, true, false);
$channel->queue_declare('tigo_soka_mo_retrycharge_router', false, true, false, false);
$channel->queue_bind('tigo_soka_mo_retrycharge_router', 'triviaExchange', 'tigosoka.soka.mo.retrycharge.key');
while (true) {
     $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try {
            $isError = FALSE;
            $connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
            $channel = $connection->channel();
            $channel->exchange_declare('triviaExchange', 'direct', false, true, false);
            $channel->queue_declare('tigo_soka_mo_retrycharge_router', false, true, false, false);
            $channel->queue_bind('tigo_soka_mo_retrycharge_router', 'triviaExchange', 'tigosoka.renewal.new.key');
        } catch (Exception $ex) {
            //echo $ex->getMessage();
            $isError = TRUE;
            sleep(1);
        }
    }

    if (!$isError) {//
        try {
            $sokaServicesObj = new SokaServices("publisher");
            $data = $sokaServicesObj->getRetry();
            if (empty($data)) {
                $sokaServicesObj->closeDB();
                $id = 0;
                sleep(1);
            } else {
                $max_id = end($data);
                $id = $max_id['ID'];
                for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $record_id = $data[$j]['ID'];
                    
                    $str = array("msisdn" => $mobile, "record_id" => $record_id);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', 'tigo_soka_mo_retrycharge_router');
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "trivia_mo_retry_process";
                    $sokaServicesObj->update($table, $column, $value, $updateArray);
                }
                $sokaServicesObj->closeDB();
                sleep(1);
            }
        } catch (Exception $ex) {
            echo $ex->getMessage();
            sleep(1);
        }
    } else {
        sleep(1);
    }
}



