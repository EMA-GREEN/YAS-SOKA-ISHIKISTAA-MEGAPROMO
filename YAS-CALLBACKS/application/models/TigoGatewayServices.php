<?php
error_reporting(0);

date_default_timezone_set('Africa/Dar_Es_Salaam');

class TigoGatewayServices  extends CI_Model{
var $db4,$db232,$trivia;	
    function __construct() {

parent::__construct();
//$this->db4 = $this->load->database('vas_application',TRUE);
//$this->db232 = $this->load->database('sms_push',TRUE);
//$this->trivia = $this->load->database('trivia',TRUE);
}

function getuuID($uuid,$database){
	$result = $this->$database->select('TRANSACTION_UUID')->from('tigo_message_logs')->where('TRANSACTION_UUID',$uuid)->get();

	return $result->row_array();

}

function _recordData($data,$database,$table){
	$this->db->insert($table,$data);
	$id = $this->db->insert_id();
        
	return $id;


}

function checkPoint($msisdn){
   
    
    $result = $this->db->select('TOTAL_POINTS')->from('tbl_qn_subscribers')->where('MSISDN',$msisdn)->get();
    return $result->row_array();
    
}

function  getRenewID($uuid,$database){
        $table = 'trivia_renewals_'.date("Ymd");
    	$result = $this->$database->select('TRANSACTION_UUID')->from($table)->where('TRANSACTION_UUID',$uuid)->get();

	return $result->row_array();

    
}

function checkNotification($requestID){
    $result = $this->db->select('ID,RESPONSE,RESPONSE_TIME')->from('tbl_notifications')->where('REQUEST_ID',$requestID)->get();
    return $result->row_array();
}

function _update($table,$column,$value,$arrayData=[]){
    $updateString = $this->arrayToString($arrayData);
    $sql = "UPDATE {$table} SET {$updateString} WHERE `{$column}` = '{$value}'";
        $this->db->query($sql);
        return $this->db->affected_rows;
}
   function arrayToString($arrayData):string{
array_walk($arrayData, function(&$value, $key){
        $value = "{$key} = '{$value}'";
    });
$string = implode(',',array_values($arrayData));
return $string;
        
    }

function updateSubscriberCredit($database,$msisdn){
    
/*    $this->$database->where('MSISDN',$msisdn);
    $this->$database->set('CREDIT',1);
    $this->$database->update('tbl_qn_subscribers');*/
    $sql = "UPDATE tbl_qn_subscribers SET CREDIT = CREDIT + 1 WHERE MSISDN = '{$msisdn}'";
     $this->$database->query($sql);
    
}
function deleteTaha($msisdn,$database){
    $this->$database->where('msisdn',$msisdn);
    $this->$database->delete('application');
}
}

?>
