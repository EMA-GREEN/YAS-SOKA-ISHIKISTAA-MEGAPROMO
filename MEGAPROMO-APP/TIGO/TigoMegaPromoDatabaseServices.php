<?php

namespace MegaPromo\DatabaseServices;

class TigoMeaPromoDatabaseServices {
    var $userType, $host, $user, $password, $dbname, $db;
    function __construct($username, $password, $db, $host) {
        $this->user = $username;
        $this->password = $password;
        $this->host = $host;
        $this->dbname = $db;
        $this->db = $this->__connect();
    }

    function __connect() {
        $mysql = new \mysqli($this->host, $this->user, $this->password, $this->dbname);
        if($mysql){
            return $mysql;
        }
        else{
            return false;
        }
        return $mysql;
    }
    function __close() {
        $this->db->close();
    }
function getMessageLogs(){
        
        $sql = "SELECT distinct MSISDN,ID,MESSAGE FROM tbl_tigo_promo_mo_logs  WHERE OUTGOING_STATUS='1' limit 50";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }


function getRetryPaymentLogs(){    
    $sql = "SELECT * from tbl_payment_retry_notifications  WHERE IS_OUTGOING='1' limit 100";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }
function getRetryMessageLogs(){
        
        $sql = "SELECT distinct MSISDN,ID,MO_ID,MESSAGE,ID FROM tbl_tigo_promo_mo_logs_retry  WHERE OUTGOING_STATUS='1' limit 15";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }


    function getRenewalcontentLogs(){    
    $date = date("Ymd");
    $sql = "SELECT * from tbl_renewal_content_notifications  WHERE IS_OUTGOING='1' limit 5";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }


function getPaymentLogs(){    
    $sql = "SELECT * from tbl_payment_interaction_notifications  WHERE IS_OUTGOING='1' limit 100";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }

function getMpushSubsInitNotifications(){
    $date = date("Ymd");
    $sql = "SELECT ID,MSISDN,CHANNEL,IS_OUTGOING,CREATED_AT from tbl_mpush_subs_init_notifications_$date where IS_OUTGOING='1' LIMIT 100";
    $resultArray = $this->db->query($sql);
    $result = [];
    if (!$resultArray) {
        return $result;
    }
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;
}

function getRenewalLogs(){    
    $date = date("Ymd");
    $sql = "SELECT * from tbl_payment_renewal_notifications_$date  WHERE IS_OUTGOING='1' limit 100";
    $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
    
        return $result;
    
    }


    
    function airtimeAccessToken(){
    
    
    $sql = "SELECT ACCESS_TOKEN FROM  user_access_token order by id desc limit 1";
    $resultArray = $this->db->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result['ACCESS_TOKEN'];
}
    
function getPaymentDetails($requestID):array{
      $sql = "SELECT * FROM tbl_promo_billing where TXN_ID = '{$requestID}'";
      $resultArray = $this->db->query($sql);
      $result = $resultArray->fetch_assoc();
      if(empty($result)){//return empty array if the result is empty
          return array();
      }
      else{
          return $result;
      }
      
  }
       function selectWinningpostion($positionID):array{
        $sql = "SELECT SUM(AMOUNT) AS 'TOTAL_WINNING_AMOUNT' ,ID AS 'POSITION_ID'  from tbl_promo_winning_positions  WHERE {$positionID} % POSITION = 0";   
        $resultArray = $this->db->query($sql);
        $result = $resultArray->fetch_assoc();       
        if(empty($result)){
                 return array();
        }
        else{
              return $result;
        }
    }
    
    function __insert($table, $insertArray = []): int {
        $columns = implode(", ", array_keys($insertArray));
        $values = implode(', ', array_map(function($insertArray) {
                    return sprintf("'%s'", $insertArray);
                }, $insertArray));
        $sql = "INSERT INTO `{$table}`($columns) VALUES ($values)";
        
        try{
        $this->db->query($sql);
        
        if ($this->db->affected_rows > 0) {
            return $this->db->insert_id;
        } else {
             $this->db = $this->__connect();
              $this->db->query($sql);
        if ($this->db->affected_rows > 0) {
            return $this->db->insert_id;
        }
            return 0;
        }
        }catch(Exception $ex){
            $this->db = $this->__connect();
              $this->db->query($sql);
              
        if ($this->db->affected_rows > 0) {
            return $this->db->insert_id;
        }
            return 0;
            
        }
    }

    function getMessageTemplates($smsType){
        $sql = "SELECT * FROM tbl_promo_message_templates where SMS_TYPE = '{$smsType}' order by id desc limit 1";
        $resultArray = $this->db->query($sql);
        return $resultArray->fetch_assoc();
    }
    function getSubscriberDetails($msisdn) {

        $sql = "SELECT * FROM tbl_promo_subscribers where MSISDN = '{$msisdn}'";
        $resultArray = $this->db->query($sql);

        $result = $resultArray->fetch_assoc();

        return $result;
    }
    function getSubscriberInformation($msisdn){
        $sql = "SELECT `MSISDN`,`SUCCESS_BILLED_PLAY_COUNT`,`FAILED_BILLED_PLAY_COUNT`,`CREDIT_PLAY_COUNT`,`OTHER_INTERACTION_COUNT`,`WIN_COUNT`,`LOST_COUNT`,`TOTAL_PURCHASED`,`TOTAL_USED`,`SMS_COUNT`,`USSD_COUNT`,`CREATED_AT`,`REACT_DATE`,`CREDIT_USED`,`RENEWAL_SUCCESS` FROM subscriber_details where MSISDN = '{$msisdn}'";
        $resultArray = $this->db->query($sql);
        $result = $resultArray->fetch_assoc();

        return $result;
        
    }
    function __update($table,$where,$value,$updateArray=[]){
        $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where}  = '{$value}'";        
	$this->db->query($sql);
        return $this->db->affected_rows;
    }

    


 function gettigoPesaPushRequests(){
      
      $sql = "SELECT * FROM tbl_promo_mobilemoney_push_transactions where IS_OUTGOING = '1' and date(date) = curdate()";
      $resultArray = $this->db->query($sql);
    $result = [];
    while($row=$resultArray->fetch_assoc()){
        $result[] = $row;
    }
        return $result;
    
  }

    
    function arrayToString($arrayData):string{
array_walk($arrayData, function(&$value, $key){
        $value = "{$key} = '{$value}'";
    });
$string = implode(',',array_values($arrayData)); 
return $string;   
}   

}
