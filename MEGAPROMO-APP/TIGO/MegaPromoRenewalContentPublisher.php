<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
require_once 'TigoMegaPromoDatabaseServices.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use MegaPromo\DatabaseServices AS DatabaseServices;



$dbConfig = loadfile('db.config');
$appConfig = loadfile('app.config');

$connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'],$appConfig['QUEUE_PASSWORD']);
$channel = $connection->channel();
$channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
$channel->queue_declare($appConfig['RENEWAL_CONTENT_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['RENEWAL_CONTENT_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['RENEWAL_CONTENT_KEY']);



while(true){
    $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try{
            $isError = FALSE;
            $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'],$appConfig['QUEUE_PASSWORD']);
$channel = $connection->channel();
$channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
$channel->queue_declare($appConfig['RENEWAL_CONTENT_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['RENEWAL_CONTENT_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['RENEWAL_CONTENT_KEY']);
}
        catch(Exception $ex){

        }
    }
if(!$isError){
try{
$db =  new DatabaseServices\TigoMeaPromoDatabaseServices($dbConfig['PUBLISHER_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME'], $dbConfig['DB_HOST']);
$data = $db->getRenewalcontentLogs();

if(empty($data)){

    sleep(1);
}

    else{
     for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $record_id = $data[$j]['ID'];
                    $count =     $data[$j]['CNT']; 
                    $str = array("msisdn" => $mobile,"id"=>$record_id,"count"=>$count);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', $appConfig['RENEWAL_CONTENT_QUEUE']);
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_renewal_content_notifications";
                    $db->__update($table, $column, $value,$updateArray);
                }
                 $db->__close();


    }

}
catch(Exception $ex){

    echo $ex->getMessage();
    sleep(1);
}
}
else{
    sleep(1);
}
}

  function loadfile($file) {
        $result = file_get_contents($file);
        $data = parse_ini_string($result);
        return $data;
    }

