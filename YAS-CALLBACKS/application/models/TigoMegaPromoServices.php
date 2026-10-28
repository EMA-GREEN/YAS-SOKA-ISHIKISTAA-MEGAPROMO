<?php
error_reporting(0);

date_default_timezone_set('Africa/Dar_Es_Salaam');

class TigoMegaPromoServices  extends CI_Model{
var $mega;	
    function __construct() {

parent::__construct();
$this->mega = $this->load->database('megapromo',TRUE); 
}



function _recordData($data,$database,$table){
       
	$this->mega->insert($table,$data);
        
        return $id;


}

function gettigopesaPushDetails($refID){
    
    $result = $this->mega->select('*')->from('tbl_promo_mobilemoney_push_transactions')->where('TXN_ID',"{$refID}")->get();
	
    return $result->row_array();
}

/*function checkNotification($requestID){
    $result = $this->soka->select('ID,RESPONSE,RESPONSE_TIME')->from('tbl_notifications')->where('REQUEST_ID',$requestID)->get();
    return $result->row_array();
}*/
function checkNotification($requestID){
    $result = $this->mega->select('*')->from('tbl_promo_tigopesapush_callbacknotif')->where('REF_ID',"{$requestID}")->get();
    return $result->row_array();
}
function checkSubscriber($msisdn){
    $result = $this->mega->select('*')->from('tbl_promo_subscribers')->where('MSISDN',$msisdn)->get();
    return $result->row_array();
}
function subscriberInfo($msisdn){
    $result = $this->mega->select('*')->from('tbl_promo_subscribers')->where('MSISDN',$msisdn)->get();
    return $result->row_array();
}
function _update($table,$column,$value,$arrayData=[]){
    $updateString = $this->arrayToString($arrayData);
    $sql = "UPDATE {$table} SET {$updateString} WHERE `{$column}` = '{$value}'";
        $this->mega->query($sql);
        
}
   function arrayToString($arrayData):string{
array_walk($arrayData, function(&$value, $key){
        $value = "{$key} = '{$value}'";
    });
$string = implode(',',array_values($arrayData));
return $string;
        
    }


}


