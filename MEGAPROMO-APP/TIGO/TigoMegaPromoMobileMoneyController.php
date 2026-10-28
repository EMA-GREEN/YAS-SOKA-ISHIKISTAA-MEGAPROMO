<?php

namespace MegaPromo\MobileMoneyController;

/**
 * Description of InteractionController
 * Purpose :  To  process all the consumed messages
 * @author Arun@GreenTelecom
 */
date_default_timezone_set('Africa/Dar_Es_Salaam');


require_once 'TigoMegaPromoDatabaseServices.php';
require_once 'MegaPromoLib.php';

use MegaPromo\DatabaseServices AS DatabaseServices;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;

Class TigoMegaPromoMobileMoneyController {

    var $msisdn, $moID, $moText, $db, $lib, $userAccessToken, $requestType, $noofToken, $appConfig,$isStaff,$dbfile;

    function __construct($msisdn, $operator) {
        $this->msisdn = $msisdn;
        $this->operator = $operator;
        $this->lib = new MegaPromoLibrary\MegaPromoLib($this->msisdn);
        $this->dbfile = $this->lib->loadfile("db.config");
        $this->db = new DatabaseServices\TigoMeaPromoDatabaseServices($this->dbfile['PUBLISHER_USER'], $this->dbfile['DB_PASSWORD'], $this->dbfile['DB_NAME'], $this->dbfile['DB_HOST']);
    }

    function processMessage($recordID, $txnID, $amount) {
        
        
        
        $responseArray = [];
        $responseData = $this->lib->tigopesaPush($txnID, $recordID, $amount);
        
        if($responseData['isResponse']){//if response is there
        
            $response = $responseData['response'];
            if(!json_decode($response,TRUE)){/// not json
                $responseArray['STATUS'] = $response['status'];
            }
            else{
                $jsonArray = json_decode($response,TRUE);
                if(isset($jsonArray['ResponseStatus'])&&$jsonArray['ResponseStatus']){
                if(isset($jsonArray['ResponseCode'])&&$jsonArray['ResponseCode']==="BILLER-18-0000-S"){
                    $responseArray['ACK_RESPONSE'] = $jsonArray['ResponseCode'].",".$jsonArray['ResponseDescription'];
                }else{
                    
                    $responseArray['ACK_RESPONSE'] = $jsonArray['ResponseCode'].",".$jsonArray['ResponseDescription'];
                }
                }else{
                    if(isset($jsonArray['ResponseStatus'])){// failed to push
                        $responseArray['ACK_RESPONSE'] = $jsonArray['ResponseCode'].",".$jsonArray['ResponseDescription'];
                        $responseArray['STATUS'] = "FAILED";
                    }else{
                        $responseArray['STATUS'] = "FAILED";
                    $responseArray['ACK_RESPONSE'] = $response;   
                    }
                }
            }   
        }else{
            $responseArray['ACK_RESPONSE'] = $responseData['response'];
        }
        $responseArray['RESPONSE_LEVEL'] = $responseData['responseLevel'];
        $responseArray['UPDATE_AT'] = date("Y-m-d H:i:s");
        $this->db->__update("tbl_promo_mobilemoney_push_transactions", "ID", $recordID,$responseArray);
        return true;
    }

}
