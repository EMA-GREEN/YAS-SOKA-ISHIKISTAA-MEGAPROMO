<?php
error_reporting(E_ALL^ E_DEPRECATED);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once __DIR__ . '/vendor/autoload.php';

require_once 'TigoMegaPromoMobileMoneyController.php';
require_once 'MegaPromoLib.php';


use MegaPromo\MobileMoneyController AS MobileMoneyController;
use MegaPromo\MegaPromoLibrary AS MegaPromoLibrary;
    $lib = new MegaPromoLibrary\MegaPromoLib(0);
    $appConfig = $lib->loadfile('app.config');
	$deliveryState = processQueue("255659256870",0,"GTL211202203311ASSS05",300);
 




function processQueue($msisdn,$recordID,$txnID,$amount){
    $operator = "TIGOPESA";
    $mobilemoneyController  =  new MobileMoneyController\TigoMegaPromoMobileMoneyController($msisdn,$operator);
    $deliveryState =  $mobilemoneyController->processMessage($recordID,$txnID,$amount);
    if($deliveryState){
        return TRUE;
    }
    else{
        
        return FALSE;
    }
    
}
