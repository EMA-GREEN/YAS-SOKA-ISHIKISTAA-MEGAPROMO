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
$channel->queue_declare($appConfig['TIGOPESA_PUSH_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['TIGOPESA_PUSH_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['TIGOPESA_PUSH_KEY']);



while(true){
    $isError = FALSE;
    if (!$connection->isConnected() || !isset($connection)) {
        $isError = TRUE;
        try{
            $isError = FALSE;
            $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'],$appConfig['QUEUE_PASSWORD']);
$channel = $connection->channel();
$channel->exchange_declare($appConfig['EXCHANGE_NAME'], 'direct', false, true, false);
$channel->queue_declare($appConfig['TIGOPESA_PUSH_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['TIGOPESA_PUSH_QUEUE'], $appConfig['EXCHANGE_NAME'], $appConfig['TIGOPESA_PUSH_KEY']);
}
        catch(Exception $ex){

        }
    }
if(!$isError){
try{
$db =  new DatabaseServices\TigoMeaPromoDatabaseServices($dbConfig['PUBLISHER_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME'], $dbConfig['DB_HOST']);
$data = $db->gettigoPesaPushRequests();

if(empty($data)){
    sleep(1);
}

    else{
        $max_id = end($data);
        $id = $max_id['ID'];
     for ($j = 0; $j < sizeof($data); $j++) {
                    $mobile = $data[$j]['MSISDN'];
                    $record_id = $data[$j]['ID'];
                    $amount = $data[$j]['AMOUNT'];
                    $txnID = $data[$j]['TXN_ID'];
                    $str = array("msisdn" => $mobile, "record_id" => $record_id, "txnID" => $txnID, "amount"=>$amount);
                    $msg = new AMQPMessage(json_encode($str));
                    $channel->basic_publish($msg, '', $appConfig['TIGOPESA_PUSH_QUEUE']);
                    $updateArray = [];
                    $updateArray['IS_OUTGOING'] = '0';
                    $updateArray['STATUS'] = 'PUBLISHED';
                    $column = "ID";
                    $value = $record_id;
                    $table = "tbl_promo_mobilemoney_push_transactions";
                    $db->__update($table,$column,$value,$updateArray);
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


