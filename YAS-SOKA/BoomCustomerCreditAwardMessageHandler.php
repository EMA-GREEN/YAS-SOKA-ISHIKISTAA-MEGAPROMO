<?php
/**
 * User: EMA-GREEN
 * Date: 01/02/2021
 * Time: 11:53 AM
 */
set_time_limit(0);
ini_set('memory_limit', '-1');

require_once "config.php";
require_once "SokaLib.php";
require_once "SokaServices.php";

class BoomCustomerCreditAwardMessageHandler {

	private static $instance;

	function __construct() {
		date_default_timezone_set('Africa/Dar_ES_Salaam');
	}

	public static function processor() {
		if (!isset(self::$instance)) {
			self::$instance = new BoomCustomerCreditAwardMessageHandler;
		}
		return self::$instance;
	}

	public function process($message){
		$msisdn = $message->msisdn;

		$sokaServicesObj = new SokaServices("consumer");
		$userAccessToken = $sokaServicesObj->userAccessToken();
	    $sokaLib = new SokaLib($msisdn, $userAccessToken, 0, 0);

		$subDetails = $sokaServicesObj->getSubscriberDetails($msisdn);

		if(!empty($subDetails)){
			$subscriberUpdate = [];
	        $subscriberUpdate['BOOM_CREDIT'] = 1; //offer 1 free credit
	        $sokaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberUpdate);

	        //notify subscriber on offered credit
	        $boom_credit_notify_sms = BOOM_CREDIT_NOTIFY_SMS;
	        $notify_sms_response = $sokaLib->sendSMS(0, 0, "BOOM_CREDIT_NOTIFY", $boom_credit_notify_sms);
	        $this->updateSMSStatus($sokaServicesObj, $notify_sms_response, $msisdn);
		}

		$sokaServicesObj->closeDB();

		return "0";
	}

	function updateSMSStatus($sokaServicesObj, $smsstatus = [], $msisdn){
	    $smsID = $smsstatus["smsID"];
	    $responseCode = json_decode($smsstatus["response"], true);
	    $responseReceived = $smsstatus["response"];
	    if (isset($responseCode["responseParam"]["status"]) && $responseCode["responseParam"]["status"] === 0) {
	        $msgStatus = "SUCCESS";
	        $return = true;
	    } else {
	        if (isset($responseCode["responseParam"]["status"])) {
	            $msgStatus = $responseCode["responseParam"]["status"];
	        } else {
	            $msgStatus = "FAILED";
	        }
	        $return = true;
	    }

	    if (strpos($responseReceived, "0: Accepted for delivery") !== false) {
	        $msgStatus = "SUCCESS";
	    }

	    $table = "trivia_interaction_mt_logs";
	    $smsArray = [];
	    $smsArray["RESPONSE_TIME"] = date("Y-m-d H:i:s");
	    $smsArray["RESPONSE_RECEIVED"] = $responseReceived;
	    $smsArray["STATUS_CODE"] = $msgStatus;
	    $sokaServicesObj->_update($table, "ID", $smsstatus["smsID"], $smsArray);
	    return $return;
	}

	function app_log($data){
		$time = date("Y-m-d H:i:s");
		$date_ext = date("YmdH").".txt";
		$data = $time." ".$data;
		file_put_contents("logs/boomcustomer/BoomCustomerCreditAwardConsumer-".$date_ext, $data." \n", FILE_APPEND);
	}

}