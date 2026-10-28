<?php

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
$channel->queue_declare($appConfig['MO_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['MO_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['MO_QUEUE_KEY']);


while(true){
    $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try{
            $isError = FALSE;
            $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'],$appConfig['QUEUE_PASSWORD']);
$channel = $connection->channel();
$channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
$channel->queue_declare($appConfig['MO_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['MO_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['MO_QUEUE_KEY']);
}
        catch(Exception $ex){
            
        }
    }
if(!$isError){    
try{
$db =  new DatabaseServices\TigoMeaPromoDatabaseServices($dbConfig['CONSUMER_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME'], $dbConfig['DB_HOST']);
$data = $db->getMessageLogs();
if(empty($data)){
    
    sleep(1);
}
    
    else{
     for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $record_id = $data[$j]['ID'];
                    $mo = $data[$j]['MESSAGE'];
                    $str = array("msisdn" => $mobile, "message" => $mo,"recordID"=>$record_id);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', $appConfig['MO_QUEUE']);
                    $updateArray = [];
                    $updateArray['OUTGOING_STATUS'] = '0';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_tigo_promo_mo_logs";
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
