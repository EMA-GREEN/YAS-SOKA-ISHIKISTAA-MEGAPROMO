<?php

/**
 * Description of MoConsumers
 *
 * @author Arun@GreenTelecom
 * Purpose :  To consume Mo messages from the queue
 */
error_reporting(E_ALL^ E_DEPRECATED);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once __DIR__ . '/vendor/autoload.php';

require_once 'TigoMegaPromoRenewalController.php';
require_once 'MegaPromoLib.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use MegaPromo\RenewalCallbackController AS CallbackController;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;
try {
    $lib = new MegaPromoLibrary\MegaPromoLib(0);
    $appConfig = $lib->loadfile('app.config');
    
    $connection = new AMQPStreamConnection($appConfig['QUEUE_HOST'], $appConfig['QUEUE_PORT'], $appConfig['QUEUE_USER'],$appConfig['QUEUE_PASSWORD']);
    $channel = $connection->channel();
} catch (Exception $ex) {
    $ex->getMessage();
    echo $ex;
}
$callback = function($data) {
    $msg = json_decode($data->body, TRUE);
	
		    
    $msisdn = $msg['msisdn'];
    $txnstatus = $msg['paymentStatus'];
    $paymentDetails = $msg['paymentDetails'];
    $requestID = $msg['requestID'];
    $billedAmount  = $msg['billedAmount'];
    $deliveryState = processQueue($msisdn, $txnstatus,$requestID,$paymentDetails,$billedAmount);
    if ($deliveryState) {
      	  $data->delivery_info['channel']->basic_ack($data->delivery_info['delivery_tag']); //acknowledge the message 
    } else {
        $data->delivery_info['channel']->basic_nack($data->delivery_info['delivery_tag']); //acknowledge the message 
    }
};


$channel->exchange_declare($appConfig['EXCHANGE_NAME'], "direct", false, true, false);
$channel->queue_declare($appConfig['RENEWAL_CALLBACK_QUEUE'], false, true, false, false);
$channel->queue_bind($appConfig['RENEWAL_CALLBACK_QUEUE'],$appConfig['EXCHANGE_NAME'],$appConfig['RENEWAL_CALLBACK_KEY']);
$channel->basic_qos(null,$appConfig['QOS_LIMIT'], null);
$channel->basic_consume($appConfig['RENEWAL_CALLBACK_QUEUE'], "", false, false, false, false, $callback);
while (count($channel->callbacks)) {
    $channel->wait();
}
$connection->close();
$channel->close();


function processQueue($msisdn, $txnStatus,$requestID,$paymentDetails,$billedAmount){

    $interactionController  =  new CallbackController\TigoMegaPromoRenewalController($msisdn, $txnStatus, $requestID, $paymentDetails, $billedAmount);
    $deliveryState =  $interactionController->processRenewal("1");
    if($deliveryState){
        return TRUE;
    }
    else{
        
        return FALSE;
    }
    
}


