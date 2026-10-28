<?php
date_default_timezone_set("Africa/Dar_Es_Salaam");
error_reporting(E_ALL);
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of HalotelServices
 *
 * @author DELL
 */
require_once 'DbConfig.php';

class IshikistaaServices{
    
    private $dbConn;
    private $userType;

    public function __construct($userType) {
        $this->userType = $userType;
        $this->dbConn = $this->connect_db();
    }

    function connect_db() {
        if ($this->userType == "consumer") {

            $conn = new mysqli(DB_HOST, DB_CONSUMER_USER, PASSWORD, TRIVIA_DB);
        } else {
            if($this->userType ==="renewal"){
                
                   $conn = new mysqli(RENEWAL_DB_HOST, DB_CONSUMER_USER, PASSWORD, RENEWAL_DB);
                
            }

	     /*elseif($this->userType ==="renewal_test"){

                $conn = new mysqli(RENEWAL_DB_HOST, DB_CONSUMER_USER, PASSWORD, TEST_RENEWAL_DB);
            }*/	
            else{
            $conn = new mysqli(DB_HOST, PUBLISHER_USER, PASSWORD, TRIVIA_DB);
        }
        }
        if ($conn) {

            return $conn;
        } else {

            return false;
        }
    }

    function closeDB() {
        $this->dbConn->close();
    }

 function fetchLastQuestion($msisdn){
        $sql = "SELECT tbl_qn_questions.QUESTION AS 'QUESTION',tbl_qn_subscribers.TOTAL_POINTS as 'TOTAL_POINTS',tbl_qn_subscribers.ACTIVITY_STATUS from tbl_qn_subscribers  inner join tbl_qn_questions ON tbl_qn_questions.ID = tbl_qn_subscribers.LAST_QN_ID  where MSISDN = '{$msisdn}'";
        $result = $this->dbConn->query($sql);
        $resultarray = $result->fetch_array();
        return $resultarray;
    }
function getRenewalNotification(){
        $sql = "SELECT ID,MSISDN,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID  from tbl_renewal_content_notifications  where IS_OUTGOING = '1' and date(created_at) = curdate() LIMIT 50";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    } 
    


function getActivationbase(){
    
    $sql = "SELECT MSISDN,SUB_ID from ishikistaa_subs_activate where IS_PROCESSED = '1' limit 30";
    $resultArray = $this->dbConn->query($sql);
    $result = [];
    while($row = $resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;
    
    
}




    function getMoNotifications() {
        $sql = "SELECT ID,MSISDN,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID, STATUS from tbl_mo_notifications  where IS_OUTGOING = '1' and date(created_at) = curdate()";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }
    
    
    function getPaymentDetails($requestID){
        
        $sql  = "SELECT ID,REFERENCE_ID FROM vas_transaction_trivia WHERE REQUEST_ID = ".$requestID. " ORDER BY ID DESC";
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_assoc();
    return $result;
    
        
        
    }
    
     function getSubscriptionNotification(){
        $sql = "SELECT ID,MSISDN,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID  from tbl_subscription_notifications  where IS_OUTGOING = '1' LIMIT 10";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }
    
    
       function getPaymentNotifications($channel) {
          if($channel ==="SMS"){

               $table = "tbl_payment_interaction_notifications_".date("Ymd");
           }
           
           if($channel ==="RENEWAL_TEST"){

               $table = "tbl_payment_test_renewal_notifications_20210203";

           }
           
           if($channel ==="RENEWAL"){

               $table = "tbl_payment_renewal_notifications_".date("Ymd");

           }
	     if($channel ==="MO_RETRY_RENEWAL"){

               $table = "tbl_payment_renewal_mo_notifications";
           }

           if($channel ==="WEB"){

               $table = "tbl_payment_web_notifications_".date("Ymd");
           }

        /*$sql = "SELECT ID,MSISDN,PAYMENT_STATUS,PAYMENT_DETAILS,REQUEST_ID from `{$table}`  where IS_OUTGOING = '1' AND CHANNEL = '{$channel}'";*/

        $sql = "SELECT * from `{$table}`  where IS_OUTGOING = '1' AND CHANNEL = '{$channel}'";
	
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }

    function getMpushYasIshikistaaSubsInitNotifications() {
        $table = "tbl_mpush_subs_init_notifications_" . date("Ymd");
        $sql = "SELECT ID, MSISDN, CHANNEL, USSD_DATA from `{$table}` where IS_OUTGOING = '1' LIMIT 50";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }

    function updateMpushSubsInitActivationCallback($msisdn, $status = 1) {
        $table = "tbl_mpush_subs_init_activation_logs_" . date("Ymd");
        $sql = "UPDATE `{$table}` SET CALLBACK_STATUS = {$status}, CALLBACK_AT = '" . date("Y-m-d H:i:s") . "' WHERE MSISDN = '{$msisdn}' AND CALLBACK_STATUS = 0 ORDER BY ID DESC LIMIT 1";
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
    }

function update($table,$where,$value,$updateArray=[]){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}'";
        
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
}

function updatePayment($table,$where,$value,$updateArray=[],$msisdn){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}' AND MSISDN = '$msisdn' order by id desc limit 1";
        
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
}

function answerDetails($txnID){
    
    $sql = "SELECT * from tbl_qn_answers where TXN_ID = '{$txnID}' ";
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_array();
        return $result;
}

function transactionDetails($requestID,$msisdn){
    
      $sql = "SELECT * FROM vas_transaction_trivia WHERE REQUEST_ID = '{$requestID}' AND MSISDN = '{$msisdn}'ORDER BY ID DESC limit 1";
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_array();
        return $result;
}
function getSubscriberDetails($msisdn) {
        $sql = "SELECT * FROM  tbl_qn_subscribers where MSISDN = '" . $msisdn . "'";
        $result = $this->dbConn->query($sql);
        $resultarray = $result->fetch_array();
        return $resultarray;
    }
     function getAnswerStatus($lastQnID) {
        $sql = "SELECT ANSWER,POINTS FROM tbl_qn_questions where ID = " . $lastQnID;
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_array();
        return $result;
    }
    
    
    function getQuestion($qnID) {
        $sql = "SELECT ID,QUESTION FROM tbl_qn_questions where ID = " . $qnID;
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_assoc();
        return $result;
    }
    
    function getStringAppend( $id){
       if($id > 26){   
        $sql  = "SELECT ID,CONTENT,CONTENT_TYPE from string_append where  CONTENT_TYPE =  'DEFAULT' limit 1 ";
    }
    else{
        
    $sql  = "SELECT ID,CONTENT,CONTENT_TYPE from string_append where  ID > $id AND CONTENT_TYPE = 'APPEND' limit 1 ";
    }
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc(); 
    if(empty($result)){
    return array();
    }
else{
return $result;
}
    
    
}
    
function getAppendContent(){
$columnName = strtolower(date('l'));
$time = date("H:i:s");
if(date("H")<3){
$sql = "SELECT {$columnName},timeline from tigo_soka_string_append where  (timeline <= '{$time}'  or timeline= 'default') and {$columnName} is not null order by timeline asc limit 1 ";
}else{
$prevTime = date("H:i:s",strtotime("-3 Hours"));
$sql = "SELECT {$columnName},timeline from tigo_soka_string_append where  (timeline <= '{$time}'  or timeline= 'default') and timeline > '{$prevTime}' and {$columnName} is not null order by timeline asc limit 1 ";
}

$resultArray = $this->dbConn->query($sql);
$result = $resultArray->fetch_assoc();
if(empty($result)){

    return array();
}
else{
return $result;
}
}

function getCreditDetails($id){
    $sql = "SELECT MSISDN FROM tbl_qn_subscribers where CREDIT = '1' and SUB_ID > {$id} ";
    $resultArray = $this->dbConn->query($sql);
    $result = [];
    while($row = $resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;
}

function userAccessToken(){
    $sql = "SELECT ACCESS_TOKEN FROM  user_access_token order by id desc limit 1";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result['ACCESS_TOKEN'];
    
}

function _update($table,$where,$value,$updateArray=[]){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}'";
        
        
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
}

function getRewards(){
    $date = date("Y-m-d");
    $sql = "SELECT LEVEL,LEVEL_TYPE,LEVEL_VALUE FROM tbl_qn_levels where date(LEVEL_DATE) = '{$date}' ";
    
    $resultArray = $this->dbConn->query($sql);
    $result = [];
    while($row = $resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;
}

  function recordData($table,$data=[]): int{
        $columns = implode(", ",array_keys($data));
        $values = implode(', ',array_map(function($data){return sprintf("'%s'", $data);}, $data));
        $sql = "INSERT INTO `{$table}`($columns) VALUES ($values)";        
	
	$this->dbConn->query($sql);
        if($this->dbConn->affected_rows > 0 ){
                return $this->dbConn->insert_id;
        }
        else{
                return 0;
        }

}

function updateRenewal($table,$where,$value,$updateArray=[],$msisdn){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}' AND MSISDN = '$msisdn'";
	
	$this->dbConn->query($sql);
        
        return $this->dbConn->affected_rows;
}



function getOpenQuestion($scheduleID){
    $sql = "SELECT ID,CONTENT FROM  openquestion_schedule where ID = $scheduleID order by id desc limit 1";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result;

}


function getOpenquestionNotifications(){
        $sql = "SELECT ID,MSISDN,SCHEDULE_ID  from tbl_openquestion_notifications  where IS_OUTGOING = '1' LIMIT 20";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
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
