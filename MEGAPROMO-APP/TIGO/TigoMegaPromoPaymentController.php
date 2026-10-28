<?php
namespace MegaPromo\PaymentCallbackController;

require_once 'TigoMegaPromoDatabaseServices.php';
require_once 'MegaPromoLib.php';
use MegaPromo\DatabaseServices AS DatabaseServices;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;


class TigoMegaPromoPaymentController {
    
    
    var $msisdn, $requestID, $txnStatus, $paymentDetails, $db, $lib, $billedAmount, $userAccessToken,$dbfile;
    
    
    function __construct($msisdn, $txnStatus, $requestID, $paymentDetails, $billedAmount) {
    $this->msisdn = $msisdn;
    $this->txnStatus = $txnStatus;
    $this->requestID = $requestID;
    $this->paymentDetails = $paymentDetails;
    $this->billedAmount = $billedAmount;
    $this->lib = new MegaPromoLibrary\MegaPromoLib($this->msisdn);
    $this->config = $this->lib->loadfile('keywords.config');
    $this->dbfile = $this->lib->loadfile('db.config');
    $this->appConfig = $this->lib->loadfile('app.config');
    $this->db = new DatabaseServices\TigoMeaPromoDatabaseServices($this->dbfile['PUBLISHER_USER'], $this->dbfile['DB_PASSWORD'], $this->dbfile['DB_NAME'], $this->dbfile['DB_HOST']);
    }



function processMessage() {
    $subscriberInfo = $this->db->getSubscriberInformation($this->msisdn);
 $msisdn = $this->msisdn;
        $requestID = $this->requestID;
        $paymentDetails = $this->paymentDetails;
        $billedAmount = $this->billedAmount;
        $txnStatus = $this->txnStatus;
        $paymentData = [];
        $paymentData['STATUS_CODE'] = $txnStatus;
        $paymentData['RESPONSE'] = $paymentDetails;
        $paymentDetailsArray = $this->db->getPaymentDetails($this->requestID);
        $txnType = $paymentDetailsArray['TXN_TYPE'];
        $txnID = $paymentDetailsArray['ID'];
        $moID = $paymentDetailsArray['MO_ID'];
        $requestType = $paymentDetailsArray['REQUEST_TYPE'];
        $this->db->__update("tbl_promo_billing","ID",$txnID,$paymentData);
        $subDetails = $this->db->getSubscriberDetails($this->msisdn);
        if ($txnStatus === "SUCCESS") {//send subscriptoin message
            //record it to daily transaction table 
            $successTransaction = [];
            $successTable = "tbl_promo_billing_".date("Ymd",strtotime($paymentDetailsArray['DATE']));
            $successTransaction['BILLING_ID'] = $txnID;
            $successTransaction['MSISDN'] = $this->msisdn;
            $successTransaction['MO_ID'] = $paymentDetailsArray['MO_ID'];
            $successTransaction['ACK_RESPONSE'] = $paymentDetailsArray['ACK_RESPONSE'];
            $successTransaction['RESPONSE'] = $paymentDetails;
            $successTransaction['STATUS_CODE'] = "SUCCESS";
            $successTransaction['AMOUNT'] = $paymentDetailsArray['AMOUNT'];
            $successTransaction['TXN_ID'] = $paymentDetailsArray['TXN_ID'];
            $successTransaction['PAYMENT_METHOD'] = $paymentDetailsArray['PAYMENT_METHOD'];
            $successTransaction['TXN_TYPE'] = $paymentDetailsArray['TXN_TYPE'];
            $successTransaction['DATE'] = $paymentDetailsArray['DATE'];
            $successTransaction['REQUEST_TYPE'] = $paymentDetailsArray['REQUEST_TYPE'];
            $this->db->__insert($successTable, $successTransaction);
            
            if ($requestType==="PURCHASE") {
                if ($billedAmount === "300") {
                        $token = 1;
                        $tokenValidity = date("Y-m-d H:i:s", strtotime("+12 months"));
                    }

                    if ($billedAmount === "1800") {
                        $token = 7;
                        $tokenValidity = date("Y-m-d H:i:s", strtotime("+12 months"));
                    }
                    if ($billedAmount === "6000") {
                        $token = 30;
                        $tokenValidity = date("Y-m-d H:i:s", strtotime("+12 months"));
                    }
                    $subscriberUpdateInfo = [];
                    $subscriberUpdateInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
                    $subscriberUpdateInfo['_STATUS'] = "BILLED";
                    $subscriberUpdateInfo['BUNDLE_COUNT'] = $subDetails['BUNDLE_COUNT'] + $token;
                    $subscriberUpdateInfo['BUNDLE_EXPIRY'] = $tokenValidity;
                    
                    $result = $this->db->getMessageTemplates("SUCCESS_PURCHASE");
                $templateID = $result['ID'];
                $sms = str_replace("%tickets",$token,$result['MESSAGE']);
                $smsType = $result['SMS_TYPE'];
                $this->processMt($moID, $templateID, 0, $msisdn, $smsType, $sms);
                $this->db->__update("tbl_promo_subscribers","MSISDN",$msisdn,$subscriberUpdateInfo);
                
                
                //update subscriber details table 
                
                $subscriberDetailsUpdate = [];
                $subscriberDetailsUpdate['TOTAL_PURCHASED'] = $subscriberInfo['TOTAL_PURCHASED'] + $token;
                 $this->db->__update("subscriber_details","MSISDN",$msisdn,$subscriberDetailsUpdate);
                $this->db->__close();
                return true;   
            }else{//
                
                if($txnType==="USSD" && $paymentDetailsArray['STATUS_CODE'] ==="CALLBACK_PENDING" ){
                    $interactionID = $this->recordInteraction($moID, $txnID);
                    $this->ProcessData($interactionID,$txnID,$moID,$requestType,$subDetails,$subscriberInfo);
                }
       
                
            if($txnType !=="USSD"){
                    $interactionID = $this->recordInteraction($moID, $txnID);
                    $this->ProcessData($interactionID,$txnID,$moID,$requestType,$subDetails,$subscriberInfo);
                    }
                    $subscriberUpdateInfo = [];
                    $subscriberUpdateInfo['LAST_BILLING'] = date("Y-m-d H:i:s");
                    $subscriberUpdateInfo['_STATUS'] = "BILLED";
                    $this->db->__update("tbl_promo_subscribers","MSISDN",$msisdn,$subscriberUpdateInfo);
                    $this->db->__close();
                    return true;
                
            }
        }
        
        else{
                $subscriberInfoUpdate = [];
                $subscriberInfoUpdate['FAILED_BILLED_PLAY_COUNT'] = $subscriberInfo['FAILED_BILLED_PLAY_COUNT'] +1;
                $subscriberInfoUpdate['LOST_COUNT']  = $subscriberInfo['LOST_COUNT'] + 1;
                $subscriberInfoUpdate['SMS_COUNT'] = $subscriberInfo['SMS_COUNT'] +1;                    
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn,$subscriberInfoUpdate);
            
            
        $paymentData = [];
        $paymentData['STATUS_CODE'] = $txnStatus;
        $paymentData['RESPONSE'] = $paymentDetails;
        $paymentDetailsArray = $this->db->getPaymentDetails($this->requestID);
        $txnType = $paymentDetailsArray['TXN_TYPE'];
        $txnID = $paymentDetailsArray['ID'];
        $moID = $paymentDetailsArray['MO_ID'];
        $this->db->__update("tbl_promo_billing","ID",$txnID,$paymentData);
            
            
            if(strpos($paymentDetails,'Billing Failure due to low balance for Msisdn')!==false){
                
                $result = $this->db->getMessageTemplates("LOW_BALANCE");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
                
                
                
            }
            else{
                $result = $this->db->getMessageTemplates("ERROR");
                $templateID = $result['ID'];
                $sms = $result['MESSAGE'];
                $smsType = $result['SMS_TYPE'];
            }
            $this->processMt($moID, $templateID, $txnID, $msisdn, $smsType, $sms);
            $this->db->__close();
            return true;
        }
        }


    function recordInteraction($moID, $billingID): int {
        $interactionArray = [];
        $interactionArray['MSISDN'] = $this->msisdn;
        $interactionArray['MO_ID'] = $moID;
        $interactionArray['BILLING_ID'] = $billingID;
        $interactionArray['BILLING_STATUS'] = 'SUCCESS';
        
        
        if(strpos($this->appConfig['TEST_MSISDN'],$this->msisdn)!==false){
            $table = "tbl_promo_entries_test";
        }
        else{
            $table = "tbl_promo_entries";
        }
        $id = $this->db->__insert($table, $interactionArray);
        
        if (empty($id) || $id <= 0) {
            return 0;
        } else {
            return $id;
        }
    }
   function ProcessData($interactionID,$txnID,$moID,$requestType,$subDetails=[],$subscriberInfo=[]){
       
      $recordID = $interactionID; 
       $subscriberInfoUpdate = [];
      //if ($recordID % 650005 === 0|| $recordID % 1600006 === 0 || $recordID % 2700007 === 0 || $recordID % 20000000===0) {
       if ($recordID % 1200000 === 0 || $recordID % 3000000 === 0 || $recordID % 5000000 === 0) {// to check if it is ready for draw
            $draw = [];
            $draw['PROCESS_STATUS'] = 'ENABLE';
            $this->setForDraw($draw, 'POSITION', $recordID);
            $this->db->__close();
            return true;
        }
       else{
                $winnerResult = $this->checkifWinner($recordID);
                $subscriberUpdate = [];
            if (empty($winnerResult) || $winnerResult['POSITION_ID'] <= 0) {//Lost
                  if($subDetails['LOST_LEVEL_ID'] >= 10){
                       $subscriberUpdate['LOST_LEVEL_ID'] = 1;
                        if($subDetails['LOST_LEVEL_ID']===10){
                           $lostID = "LOST_10";
                       }
                       else{
                           $lostID = "LOST_1";
                       }
                }
                else{
                $subscriberUpdate['LOST_LEVEL_ID'] = $subDetails['LOST_LEVEL_ID'] + 1;
                $lostID = "LOST_" . $subDetails['LOST_LEVEL_ID'];
            }          
                $subscriberInfoUpdate['SUCCESS_BILLED_PLAY_COUNT'] = $subscriberInfo['SUCCESS_BILLED_PLAY_COUNT'] +1;
                $subscriberInfoUpdate['LOST_COUNT']  = $subscriberInfo['LOST_COUNT'] + 1;
                $subscriberInfoUpdate['SMS_COUNT'] = $subscriberInfo['SMS_COUNT'] +1;                    
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn,$subscriberInfoUpdate);
                $result = $this->db->getMessageTemplates($lostID);
                $templateID = $result['ID'];
                $sms = str_replace("%availabletickets", $subDetails['BUNDLE_COUNT'] - 1, $result['MESSAGE']);
                $sms = str_replace("%tickets", 1, $sms);
                $smsType = $result['SMS_TYPE'];
                $tickStatus = "LOST";
            } else {//win
                $subscriberUpdate['LOST_LEVEL_ID'] = 1;
                $result = $this->db->getMessageTemplates("WIN_DIRECT_PLAY");
                $templateID = $result['ID'];
                $sms = str_replace("%amount", $winnerResult['TOTAL_WINNING_AMOUNT'], $result['MESSAGE']);
		echo $sms;
                $smsType = $result['SMS_TYPE'];   
                $subscriberInfoUpdate['SUCCESS_BILLED_PLAY_COUNT'] = $subscriberInfo['SUCCESS_BILLED_PLAY_COUNT'] +1;
                $subscriberInfoUpdate['WIN_COUNT']  = $subscriberInfo['WIN_COUNT'] + 1;
                $subscriberInfoUpdate['SMS_COUNT'] = $subscriberInfo['SMS_COUNT'] +1;                    
                $this->db->__update("subscriber_details", "MSISDN", $this->msisdn,$subscriberInfoUpdate);
                
                $winnerData = [];
                $winnerData['MSISDN'] = $this->msisdn;
                $winnerData['PAYOUT_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
                $winnerData['POSITION_ID'] = $winnerResult['POSITION_ID'];
                $winnerData['SUBSCRIBER_ID'] = $subDetails['ID'];
                $winnerData['INTERACTION_ID'] = $recordID;
                $this->db->__insert('tbl_promo_fixed_winners', $winnerData);
                $tickStatus = "WIN";
                $reason = 'WINNER PAYOUT';
            }
            
            $gremsData = [];
            $gremsData['MSISDN'] = $this->msisdn;
            $mnotxn = $this->db->getPaymentDetails($this->requestID);
            $mnoTxnArray = json_decode($mnotxn['ACK_RESPONSE'], TRUE);
            

	      if(!isset($mnoTxnArray['responseId'])){   
            $mnotxnID = $mnotxn['RESPONSE'];    
            }else{
            $mnotxnID = $mnoTxnArray['responseId'];
            } 	

            $gremsData['MNO_TXN_ID'] = $mnotxnID;
            $gremsData['OPERATOR_TXN_ID'] = date("YmdHis").$this->msisdn;
            $gremsData['AMOUNT'] = $mnotxn['AMOUNT'];
            $gremsData['DRAW_ID'] = $txnID;
            $gremsData['TICKET_ID'] = $recordID;
            $gremsData['TICKET_STATUS'] = $tickStatus;
            $gremsData['REQUEST_TYPE'] = "DRAW";
            $gremsData['PAYMENT_STATUS'] = "SUCCESS";
            $gremsData['TXN_TYPE'] = "AIRTIME";
            if ($smsType === "WON") {
                $gremsData['WON_AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
            }
            //$this->recordGremsData($gremsData);
            
            
            if($tickStatus ==="WIN"){
                
                $disbursement = [];
            
            $disbursement['MSISDN'] = $this->msisdn;
            $disbursement['AMOUNT'] = $winnerResult['TOTAL_WINNING_AMOUNT'];
            $disbursement['PAYOUT_STATUS'] = 'PENDING';
            $disbursement['INTERACTION_ID'] = $recordID;
            $disbursement['INTERACTION_CHANNEL'] = $mnotxn['TXN_TYPE'];
            $this->db->__insert("tbl_promo_fixed_winnersdisbursement_process",$disbursement);
            
            }
            
            if($mnotxn['TXN_TYPE']==="USSD" && $mnotxn['STATUS_CODE']!=="CALLBACK_PENDING"){
                return true;
            }
            
            $this->db->__insert("grems_txn", $gremsData);
            $this->processMt($moID, $templateID, $txnID, $this->msisdn, $smsType, $sms);
            $this->db->__update("tbl_promo_subscribers","MSISDN", $this->msisdn, $subscriberUpdate);
            return true;
           
       }
       
   }
   
      function checkifWinner($interactionID) {
        $result = $this->db->selectWinningpostion($interactionID);
        if (empty($result)) {
            return array();
        } else {


            return $result;
        }
    }


function setForDraw($drawArray, $where, $whereValue) {
        $this->db->__update('tbl_promo_draw', $where, $whereValue,$drawArray);
        return true;
    }

    
    function processMT($moID, $templateID, $billingID, $msisdn, $smsType, $sms) {
    $smsstatus = $this->lib->sendSMS($moID, $templateID,$billingID, $sms, $smsType);    
    $smsID = $smsstatus['smsID'];
    $responseCode = $smsstatus['response'];
    
    if (strpos($responseCode,"Accepted") !==false) {
        $msgStatus = "SUCCESS";
        $data['RESPONSE_RECEIVED'] = $responseCode;
        $data['STATUS_CODE'] = "SUCCESS";
        $result = $this->db->__update('tbl_promo_mt_logs', 'ID', $smsID,$data);
    } else {
         $msgStatus = "FAILED";
         $data['STATUS_CODE'] = $msgStatus;
         $data['RESPONSE_RECEIVED'] = $responseCode;
        $result = $this->db->__update('tbl_promo_mt_logs', 'ID', $smsID,$data);
    }
        return true;
}
}    


