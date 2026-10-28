<?php

set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');
/*
 * Publisher: reads tbl_mpush_subs_init_notifications_{date}
 * where IS_OUTGOING=1 and publishes to RabbitMQ for subscription activation.
 */
require_once 'TigoMegaPromoDatabaseServices.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use MegaPromo\DatabaseServices AS DatabaseServices;

$dbConfig = loadfile('db.config');
$appConfig = loadfile('app.config');
$connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'], $appConfig['QUEUE_PASSWORD']);
$channel = $connection->channel();
$channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
$channel->queue_declare($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['MPUSH_YAS_SUBS_INIT_KEY']);

while (true) {
    $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try {
            $isError = FALSE;
            $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'], $appConfig['QUEUE_PASSWORD']);
            $channel = $connection->channel();
            $channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
            $channel->queue_declare($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], false, true, false, false);
            $channel->queue_bind($appConfig['MPUSH_YAS_SUBS_INIT_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['MPUSH_YAS_SUBS_INIT_KEY']);
        } catch (Exception $ex) {
            $isError = TRUE;
            sleep(1);
        }
    }

    if (!$isError) {
        try {
            $db = new DatabaseServices\TigoMeaPromoDatabaseServices($dbConfig['PUBLISHER_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME'], $dbConfig['DB_HOST']);
            $data = $db->getMpushSubsInitNotifications();
            if (empty($data)) {
                $db->__close();
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
                    $channel->basic_publish($msg, '', $appConfig['MPUSH_YAS_SUBS_INIT_QUEUE']);
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $table = "tbl_mpush_subs_init_notifications_" . date("Ymd");
                    $db->__update($table, "ID", $record_id, $updateArray);
                }
                $db->__close();
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

function loadfile($file) {
    $result = file_get_contents($file);
    return parse_ini_string($result);
}
