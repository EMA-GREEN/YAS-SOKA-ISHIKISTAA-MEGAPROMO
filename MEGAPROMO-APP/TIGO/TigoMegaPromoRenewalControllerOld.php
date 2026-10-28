<?php
namespace MegaPromo\RenewalCallbackController;

require_once 'TigoMegaPromoDatabaseServices.php';
require_once 'MegaPromoLib.php';
use MegaPromo\DatabaseServices AS DatabaseServices;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;


class TigoMegaPromoRenewalController {
    
    
    var $msisdn, $requestID, $txnStatus, $paymentDetails, $db, $lib, $billedAmount, $userAccessToken,$renewalDB,$dbfile;
    
    
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
    
    $this->renewalDB = new DatabaseServices\TigoMeaPromoDatabaseServices($this->dbfile['RENEWAL_USER'], $this->dbfile['DB_PASSWORD'], $this->dbfile['RENEWAL_DB_NAME'], $this->dbfile['RENEWAL_DB_HOST']);
    
    }


function processRetry() {
        $msisdn = $this->msisdn;
        $requestID = $this->requestID;
        $paymentDetails = $this->paymentDetails;
        $billedAmount = $this->billedAmount;
        $txnStatus = $this->txnStatus;
        $paymentData = [];
        $paymentData['STATUS_CODE'] = $txnStatus;
        $paymentData['RESPONSE'] = $paymentDetails;
        $subDetails = $this->db->getSubscriberDetails($this->msisdn);
        $subInfo    = $this->db->getSubscriberInformation($this->msisdn);
        $subscriberUpdate = [];
        $subscriberInfoUpdate = [];
	
        if($txnStatus ==="SUCCESS"){///update subscriber table renewals txn and subs table
            if($this->appConfig['CREDIT_ENABLED']){	
            if($this->appConfig['AUTO_RENEWAL_MESSAGE_SCHEDULE_START']<=date("H")&&$this->appConfig['AUTO_RENEWAL_MESSAGE_SCHEDULE_END']>=date("H")){    
                $subscriberUpdate['CREDIT'] = $subDetails['CREDIT'] + 1;  
                $sms = $this->appConfig['RENEWAL_DEFAULT_CONTENT'];
                if($subDetails['ENTICEMENT_STATUS']==="ON" && $subDetails['ACTIVITY_STATUS']==="ACTIVE"){
                $this->processMT(0, 0, 0, $this->msisdn, "RENEWAL_CONTENT", $sms);
                }
            }    
            }
            $subscriberInfoUpdate['RENEWAL_SUCCESS'] = $subInfo['RENEWAL_SUCCESS'] +1;
            $this->db->__update("tbl_promo_subscribers","MSISDN",$this->msisdn,$subscriberUpdate);
            $this->db->__update("subscriber_details","MSISDN",$this->msisdn,$subscriberInfoUpdate);
            $paymentUpdate = [];
            $paymentUpdate['STATUS_CODE'] = "SUCCESS";
            $paymentUpdate['RESPONSE'] = $paymentDetails;
            $this->db->__update("tbl_promo_billing","TXN_ID",$requestID,$paymentUpdate);
            
            $paymentDetailsArray = $this->db->getPaymentDetails($this->requestID);
            $txnType = $paymentDetailsArray['TXN_TYPE'];
            $txnID = $paymentDetailsArray['ID'];
            
            $successTransaction = [];
            $successTable = "tbl_promo_billing_".date("Ymd",strtotime($paymentDetailsArray['DATE']));
            $successTransaction['BILLING_ID'] = $txnID;
            $successTransaction['MSISDN'] = $this->msisdn;
            $successTransaction['MO_ID'] = $paymentDetailsArray['MO_ID'];
            $successTransaction['RESPONSE'] = $paymentDetails;
            $successTransaction['STATUS_CODE'] = "SUCCESS";
            $successTransaction['AMOUNT'] = $paymentDetailsArray['AMOUNT'];
            $successTransaction['TXN_ID'] = $paymentDetailsArray['TXN_ID'];
            $successTransaction['PAYMENT_METHOD'] = $paymentDetailsArray['PAYMENT_METHOD'];
            $successTransaction['TXN_TYPE'] = $paymentDetailsArray['TXN_TYPE'];
            $successTransaction['DATE'] = $paymentDetailsArray['DATE'];
            $successTransaction['REQUEST_TYPE'] = $paymentDetailsArray['REQUEST_TYPE'];
            $this->db->__insert($successTable, $successTransaction);
        }else{
            
    
            $paymentUpdate = [];
            $paymentUpdate['STATUS_CODE'] = "FAILED";
            $paymentUpdate['RESPONSE'] = $paymentDetails;
            $this->db->__update("tbl_promo_billing","TXN_ID",$requestID,$paymentUpdate);
            
        }
        
        $this->renewalDB->__close();
        $this->db->__close();
        return TRUE;


}



function processRenewal($count) {
        $msisdn = $this->msisdn;
        $requestID = $this->requestID;
        $paymentDetails = $this->paymentDetails;
        $billedAmount = $this->billedAmount;
        $txnStatus = $this->txnStatus;
        $paymentData = [];
        $paymentData['STATUS_CODE'] = $txnStatus;
        $paymentData['RESPONSE'] = $paymentDetails;

        
        $subDetails = $this->db->getSubscriberDetails($this->msisdn);
        $subInfo    = $this->db->getSubscriberInformation($this->msisdn);
        $subscriberUpdate = [];
        $subscriberInfoUpdate = [];


        
        if (strpos($requestID, 'WF') !== false||strpos($requestID, 'w') !== false) {
            $renewalType = "FALLBACK";
            $rntxntable  = "tbl_tigo_soka_promo_renewal_transaction";
            $renewalSubscriberTable = "tbl_soka_promo_renewal_middleware";
        
    }else{
        $renewalType = "NORMAL";
         $rntxntable = "tbl_tigo_zali_renewal_transaction";
         $renewalSubscriberTable  = "tbl_zali_renewal_middleware";
    }
        
        
        
        
        
        if($txnStatus ==="SUCCESS"){///update subscriber table renewals txn and subs table
            if($this->appConfig['CREDIT_ENABLED']){
		
            if($this->appConfig['AUTO_RENEWAL_MESSAGE_SCHEDULE_START']<=date("H")&&$this->appConfig['AUTO_RENEWAL_MESSAGE_SCHEDULE_END']>=date("H")){    
                $subscriberUpdate['CREDIT'] = $subDetails['CREDIT'] + $count;  
                $sms = $this->appConfig['RENEWAL_DEFAULT_CONTENT'];
                $sms = str_replace("%kete",$count,$sms);
                if($subDetails['ENTICEMENT_STATUS']==="ON" && $subDetails['ACTIVITY_STATUS']==="ACTIVE"){
                $this->processMT(0, 0, 0, $this->msisdn, "RENEWAL_CONTENT", $sms);
                }
            }    
            }
            
            $subscriberUpdate['LAST_RENEWAL_BILLING'] = date("Y-m-d H:i:s");
            $subscriberInfoUpdate['RENEWAL_SUCCESS'] = $subInfo['RENEWAL_SUCCESS'] +1;
            
            $this->db->__update("tbl_promo_subscribers","MSISDN",$this->msisdn,$subscriberUpdate);
            $this->db->__update("subscriber_details","MSISDN",$this->msisdn,$subscriberInfoUpdate);
            
            //update renewal tables
    $callbackArray = [];
    $callbackArray['MW_BILLING_STATUS'] = 'SUCCESS';
    $callbackArray['RESPONSE_RECEIVED_TIME'] = date("Y-m-d H:i:s");
    	
    $where =  "TRANSACTION_ID";
    $value = $requestID;
    $this->renewalDB->__update($rntxntable,"TRANSACTION_ID",$requestID,$callbackArray);
    
    
    
    $renewalSubscriber = [];
    $renewalSubscriber['RENEWAL_STATUS'] = 'INACTIVE';
    $renewalSubscriber['RN_PUBLISHING_STATUS'] = 'OFF'; 
    if($renewalType==="NORMAL"){
    $renewalSubscriber['RETRY_STATUS'] = 'OFF';
    }
    $renewalSubscriber['LAST_RENEWAL_BILLING'] = date("Y-m-d H:i:s");
    
    $this->renewalDB->__update($renewalSubscriberTable,"MSISDN",$this->msisdn,$renewalSubscriber);
    
            
        }else{
                 $callbackArray = [];
                 $updateArray = [];
                 
                 $subupdate = [];
                 
                 
       if(strpos($paymentDetails,"Subscriber not active")!==false||strpos($paymentDetails,"Subscriber does not exist")!==false){
           $subupdate['ACTIVITY_STATUS'] = 'INACTIVE';
           $this->db->__update("tbl_promo_subscribers","MSISDN",$this->msisdn,$subupdate);
           $updateArray['ACTIVITY_STATUS'] = 'INACTIVE';
       }     
            
            

     $callbackArray['MW_BILLING_ID'] = '0';
     $callbackArray['MW_BILLING_STATUS'] = $paymentDetails;
     $callbackArray['RESPONSE_RECEIVED_TIME'] = date("Y-m-d H:i:s");
     $where =  "TRANSACTION_ID";
     $value = $requestID;
     $this->renewalDB->__update($rntxntable,"TRANSACTION_ID",$requestID,$callbackArray);
     $nextRetrialPeriod  = date("Y-m-d H:i:s",strtotime("+10 minutes"));
     
    $updateArray['RETRY_STATUS'] = 'ON';
    $updateArray['NEXT_RETRIAL_PERIOD'] = $nextRetrialPeriod; 
    $this->renewalDB->__update($renewalSubscriberTable, "MSISDN", $this->msisdn,$updateArray);
    
        }
        
        $this->renewalDB->__close();
        $this->db->__close();
        return TRUE;


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

function processRenewalContent($count){
                $subDetails = $this->db->getSubscriberDetails($this->msisdn);
                $subscriberUpdate['CREDIT'] = $subDetails['CREDIT'] + $count;  
                $sms = $this->appConfig['RENEWAL_DEFAULT_CONTENT'];
                $sms = str_replace("%kete", $count, $sms);
                 if($subDetails['ENTICEMENT_STATUS']==="ON" && $subDetails['ACTIVITY_STATUS']==="ACTIVE"){
                $this->processMT(0, 0, 0, $this->msisdn, "RENEWAL_CONTENT", $sms);
                }
                $this->db->__update("tbl_promo_subscribers","MSISDN",$this->msisdn,$subscriberUpdate);$this->db->__update("tbl_promo_subscribers","MSISDN",$this->msisdn,$subscriberUpdate);
                return true;
}
}

