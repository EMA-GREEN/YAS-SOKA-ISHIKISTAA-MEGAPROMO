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
require_once "SokaServices.php";

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$boomcreditkey = "boomcuster.credit.award.key";
$boomcreditqueue = "boom_cust_credit_award";
$boomcreditexchange = "triviaExchange";

$connection = new AMQPStreamConnection('127.0.0.1', 5672, 'tigo_manage', 'manage@CNu8Ut');
$channel = $connection->channel();
$channel->exchange_declare($boomcreditexchange, "direct", false, true, false);

$channel->queue_declare($boomcreditqueue, false, true, false, false);
$channel->queue_bind($boomcreditqueue, $boomcreditexchange, $boomcreditkey);

$pblsh_lmt = 30;

while(true){

	if(!$connection->isConnected()||!isset($connection)){
		try {
			$connection = new AMQPStreamConnection('127.0.0.1', 5672, 'broadcast_app', 'broadcast@CNu8Ut');
			$channel = $connection->channel();
			$channel->exchange_declare($boomcreditexchange, "direct", false, true, false);
			$channel->queue_declare($boomcreditqueue, false, true, false, false);
			$channel->queue_bind($boomcreditqueue, $boomcreditexchange, $boomcreditkey);
		} catch(Exception $e) {
			 app_log('Errors in opening AMQPStreamConnection due to: ' .$e->getMessage());
		}
	}

	$sokaServicesObj = new SokaServices("consumer");

	$boom_customers = $sokaServicesObj->getBoomCustomers($pblsh_lmt);

	$n_subs = count($boom_customers);
	if($n_subs > 0){
		$whereIds = "";
		for($i=0; $i < $n_subs; $i++){
			$sub_id = $boom_customers[$i]["ID"];
	        $msisdn = $boom_customers[$i]["MSISDN"];
			publish($msisdn, $channel, $boomcreditexchange, $boomcreditkey);
	        if($i > 0){
				$whereIds .= ",";
			}
			$whereIds .= $sub_id;
	    }
        $sokaServicesObj->updateSubmittedBoomCustomersToQueue($whereIds);
	}

	$sokaServicesObj->closeDB();

	sleep(1);

}

function publish($msisdn, $channel, $boomcreditexchange, $boomcreditkey){
	$msisdn = trim(preg_replace('/\s\s+/', '', $msisdn));
	$str['msisdn'] = $msisdn;
	$encoded_msg_str = json_encode($str);
	$msg = new AMQPMessage($encoded_msg_str);
	try {
		$channel->basic_publish($msg, $boomcreditexchange, $boomcreditkey);
		app_log("...Publishing: ".$encoded_msg_str);
	} catch(Exception $e) {
		app_log('Errors in message consuming due to: ' .$e->getMessage());
	}
}

function app_log($data){
	$date_ext = date("YmdH").".txt";
	file_put_contents("logs/boomcustomer/BoomCustomerCreditAwardPublisher-".$date_ext, $data." \n", FILE_APPEND);
}
