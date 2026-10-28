<?php
error_reporting(0);

date_default_timezone_set('Africa/Dar_Es_Salaam');

class TigoSokaGatewayServices  extends CI_Model{
var $soka;	
    function __construct() {

parent::__construct();
$this->soka = $this->load->database('soka',TRUE); 
}



function _recordData($data,$database,$table){
        if($database==="boli"){   
            $this->$database->insert($table,$data);
        }
        else{
	$this->soka->insert($table,$data);
        }
	//echo $this->soka->last_query();
	$id = $this->soka->insert_id();
        
	return $id;


}

function checkPoint($msisdn){
   
    
    $result = $this->soka->select('TOTAL_POINTS')->from('tbl_qn_subscribers')->where('MSISDN',$msisdn)->get();
    return $result->row_array();
    
}

function  getRenewID($uuid,$database){
        $table = 'trivia_renewals_'.date("Ymd");
    	$result = $this->$database->select('TRANSACTION_UUID')->from($table)->where('TRANSACTION_UUID',$uuid)->get();
	return $result->row_array();

    
}

function checkNotification($requestID){
    $result = $this->soka->select('ID,RESPONSE,RESPONSE_TIME')->from('tbl_notifications')->where('REQUEST_ID',$requestID)->get();
    return $result->row_array();
}

function _update($table,$column,$value,$arrayData=[]){
    $updateString = $this->arrayToString($arrayData);
    $sql = "UPDATE {$table} SET {$updateString} WHERE `{$column}` = '{$value}'";
        $this->soka->query($sql);
        return $this->soka->affected_rows;
}
   function arrayToString($arrayData):string{
array_walk($arrayData, function(&$value, $key){
        $value = "{$key} = '{$value}'";
    });
$string = implode(',',array_values($arrayData));
return $string;
        
    }



function getPromotionStatus(){
    $result = $this->soka->select('STATUS')->from('tbl_tigo_soka_promo_renewal_status')->get();
    return $result->row_array();
}


function updateSubscriberCredit($database,$msisdn){
    $sql = "UPDATE tbl_qn_subscribers SET CREDIT = CREDIT + 1 WHERE MSISDN = '{$msisdn}'";
     $this->$database->query($sql);
    
}
function deleteTaha($msisdn,$database){
    $this->$database->where('msisdn',$msisdn);
    $this->$database->delete('application');
}


function checkMoNotification($requestID,$msisdn){
    $date = date("Y-m-d");
    $sql = "SELECT * FROM trivia_mo_check where REQUEST_ID = '$requestID' AND MSISDN = '$msisdn' AND date(DATE) = '$date' order by id desc limit 1";
    $result = $this->soka->query($sql);
    return $result->row_array();
}


}

?>
