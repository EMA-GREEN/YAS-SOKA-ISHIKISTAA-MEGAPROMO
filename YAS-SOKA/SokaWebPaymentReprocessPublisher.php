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
$channel->queue_declare('tigo_soka_webpayment_reprocess', false, true, false, false);
$channel->queue_bind('tigo_soka_webpayment_reprocess', 'triviaExchange', 'tigosoka.webreprocess.key');
while (true) {
     $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try {
            $isError = FALSE;
            $connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
            $channel = $connection->channel();
            $channel->exchange_declare('triviaExchange', 'direct', false, true, false);
            $channel->queue_declare('tigo_soka_webpayment_reprocess', false, true, false, false);
            $channel->queue_bind('tigo_soka_webpayment_reprocess', 'triviaExchange', 'tigosoka.webreprocess.key');
        } catch (Exception $ex) {
            echo $ex->getMessage();
            $isError = TRUE;
            sleep(1);
        }
    }

    if (!$isError) {//
        $date = "20201127";
        try {
            $sokaServicesObj = new SokaServices("publisher");
            $data = $sokaServicesObj->getReprocessPaymentNotifications($date);
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
                    $paymentDetails = $data[$j]['PAYMENT_DETAILS'];
                    $txnStatus = $data[$j]['PAYMENT_STATUS'];
                    $requestID = $data[$j]['REQUEST_ID'];
                    $responseTime = $data[$j]['CREATED_AT'];
                    $str = array("msisdn" => $mobile, "paymentdetail" => $paymentDetails, "record_id" => $record_id, "requestid" => $requestID, "txnstatus" => $txnStatus,"reponse_time"=>$responseTime);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', 'tigo_soka_webpayment_reprocess');
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_payment_web_notifications_".$date;
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


