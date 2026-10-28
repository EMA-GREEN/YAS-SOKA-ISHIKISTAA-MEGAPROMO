<?php

set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * Publisher: reads tbl_mpush_subs_init_notifications_{date}
 * where IS_OUTGOING=1 and publishes to RabbitMQ for subscription activation.
 */
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require_once 'SokaServices.php';

$connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
$channel = $connection->channel();
$channel->exchange_declare('triviaExchange', 'direct', false, true, false);
$channel->queue_declare('tigo_soka_mpush_yas_subs_init_router', false, true, false, false);
$channel->queue_bind('tigo_soka_mpush_yas_subs_init_router', 'triviaExchange', 'tigosoka.mpush.yas.subs.init.key');

while (true) {
    $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try {
            $isError = FALSE;
            $connection = new AMQPStreamConnection('localhost', 5672, 'tigo_manage', 'manage@CNu8Ut');
            $channel = $connection->channel();
            $channel->exchange_declare('triviaExchange', 'direct', false, true, false);
            $channel->queue_declare('tigo_soka_mpush_yas_subs_init_router', false, true, false, false);
            $channel->queue_bind('tigo_soka_mpush_yas_subs_init_router', 'triviaExchange', 'tigosoka.mpush.yas.subs.init.key');
        } catch (Exception $ex) {
            $isError = TRUE;
            sleep(1);
        }
    }

    if (!$isError) {
        try {
            $sokaServicesObj = new SokaServices("publisher");
            $data = $sokaServicesObj->getMpushYasSokaSubsInitNotifications();
            if (empty($data)) {
                $sokaServicesObj->closeDB();
                sleep(1);
            } else {
                for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $record_id = $data[$j]['ID'];
                    $channelName = $data[$j]['CHANNEL'];
                    $str = array(
                        "msisdn" => $mobile,
                        "record_id" => $record_id,
                        "channel" => $channelName
                    );
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', 'tigo_soka_mpush_yas_subs_init_router');
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_mpush_subs_init_notifications_" . date("Ymd");
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
