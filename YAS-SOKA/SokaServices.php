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

class SokaServices{
    
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
            if($this->userType==="renewal"){
                $conn = new mysqli(RENEWAL_DB_HOST, DB_CONSUMER_USER, PASSWORD, RENEWAL_TRIVIA_DB);
            }
            
            else{
            $conn = new mysqli(DB_HOST, PUBLISHER_USER, PASSWORD, TRIVIA_DB);
            }
        }
        if ($conn) {
            return $conn;
        } else {
		echo "Error Occured While connecting".$this->userType;
            return false;
        }
    }

    function closeDB() {
        $this->dbConn->close();
    }



   /*function getReprocessMo($msisdn){   
    $sql = "SELECT * FROM trivia_mo_retry where MSISDN = '{$msisdn}'";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result;
}*/


function getReprocessMo(){
    
    $sql = "SELECT * FROM trivia_mo_retry where IS_OUTGOING = '1' order by id desc limit 10";
     $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
}

function getTokenDetails(){
    $sql = "SELECT ACCESS_TOKEN,EXPIRES_AT FROM  user_access_token order by id desc limit 1";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result;
    
}


  function fetchLastQuestion($msisdn){
        $sql = "SELECT tbl_qn_questions.QUESTION AS 'QUESTION',tbl_qn_subscribers.TOTAL_POINTS as 'TOTAL_POINTS',tbl_qn_subscribers.ACTIVITY_STATUS from tbl_qn_subscribers  inner join tbl_qn_questions ON tbl_qn_questions.ID = tbl_qn_subscribers.LAST_QN_ID  where MSISDN = '{$msisdn}'";
        $result = $this->dbConn->query($sql);
        $resultarray = $result->fetch_array();
        return $resultarray;
    }



function getRetryDetails($msisdn){
    
    $sql = "SELECT * FROM trivia_mo_retry_process where MSISDN = '{$msisdn}'";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result;
}

function getRetry(){
    $date = date("Y-m-d H:i:s");
    $sql  = "SELECT * FROM tbl_mo_retry where IS_OUTGOING = '1' and COUNT >= 1  and NEXT_RETRY <= '$date' limit 8";
     $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
}


function getLevelDetails($level){
    $date = date("Y-m-d");
    $sql = "SELECT LEVEL,LEVEL_TYPE,LEVEL_VALUE,LEVEL_MSG FROM tbl_qn_levels where date(LEVEL_DATE) = '{$date}' AND LEVEL = '{$level}'";
    $result = $this->dbConn->query($sql);
    $resultArray = $result->fetch_array();
    return $resultArray;
}

    function getMoNotifications() {
        $sql = "SELECT distinct MSISDN,ID,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID, STATUS from tbl_interaction_mo_notifications  where IS_OUTGOING = '1' order by id desc LIMIT 75";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }
    
    function getBoomCustomers($pblsh_lmt){
        $sql = "SELECT ID, MSISDN FROM tbl_boom_customer_base WHERE IS_SUBMITTED=0 ORDER BY ID DESC LIMIT ".$pblsh_lmt;
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }

    function updateSubmittedBoomCustomersToQueue($whereIds){
        $sql = "UPDATE tbl_boom_customer_base SET IS_SUBMITTED = 1 WHERE ID IN (".$whereIds.")";
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
    }
    
    function getRenewalNotification(){
        $sql = "SELECT ID,MSISDN,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID,CNT  from tbl_renewal_content_notifications  where IS_OUTGOING = '1' LIMIT 50";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    } 
    
    function getSubscriptionNotification(){
        $sql = "SELECT ID,MSISDN,TEXT,REQUEST_TYPE,REQUEST_ID,REFERENCE_ID  from tbl_subscription_notifications  where IS_OUTGOING = '1' LIMIT 20";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }

    function getMpushYasSokaSubsInitNotifications(){
        $table = "tbl_mpush_subs_init_notifications_".date("Ymd");
        $sql = "SELECT ID,MSISDN,CHANNEL,USSD_DATA,IS_OUTGOING,CREATED_AT from `{$table}` where IS_OUTGOING = '1' LIMIT 100";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        if (!$resultArray) {
            return $result;
        }
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }

    function confirmMpushSubsInitActivation($msisdn) {
        $tables = [
            "tbl_mpush_subs_init_activation_logs_" . date("Ymd"),
            "tbl_mpush_subs_init_activation_logs_" . date("Ymd", strtotime("-1 day"))
        ];

        foreach ($tables as $table) {
            $sql = "SELECT ID FROM `{$table}` WHERE MSISDN = '{$msisdn}' AND CONFIRMED_STATUS = '0' ORDER BY ID DESC LIMIT 1";
            $resultArray = $this->dbConn->query($sql);
            if (!$resultArray) {
                if ($this->dbConn->errno == 1146) {
                    file_put_contents(
                        "logs/mpush/MpushSubsInitConfirm-" . date("Ymd") . ".txt",
                        date("Y-m-d H:i:s") . " Missing table: {$table} MSISDN: {$msisdn}\n",
                        FILE_APPEND
                    );
                }
                continue;
            }
            if ($resultArray->num_rows === 0) {
                continue;
            }
            $row = $resultArray->fetch_assoc();
            return $this->_update($table, "ID", $row['ID'], [
                'CONFIRMED_STATUS' => 1,
                'CONFIRMED_AT' => date("Y-m-d H:i:s")
            ]);
        }

        return 0;
    }
    
    function getPaymentDetails($requestID){
        
        $sql  = "SELECT * FROM vas_transaction_trivia WHERE REQUEST_ID = '".$requestID."' ORDER BY ID DESC";
        
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_assoc();
    return $result;
    
        
        
    }
    
    function getRetryAnswerDetails($txnID){
        
        $sql  = "SELECT * FROM tbl_mo_retry  WHERE RETRY_TXN_ID = ".$txnID." ORDER BY ID DESC limit 1";
        
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_assoc();
    return $result;
        
        
    }
    
       function getReprocessPaymentNotifications($date) {
           $table = "tbl_payment_web_notifications_".$date;
           $sql = "SELECT ID,MSISDN,PAYMENT_STATUS,PAYMENT_DETAILS,REQUEST_ID,CREATED_AT from `{$table}` where IS_OUTGOING = '1'   LIMIT 100";
           $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }


function getRenewalReprocessNotifications($channel) {
           if($channel ==="SMS"){

               $table = "tbl_payment_interaction_notifications_".date("Ymd");
           }
           if($channel ==="RENEWAL"){
               $table = "tbl_payment_renewalreprocess_notifications_20210110";

           }
           if($channel ==="WEB"){
               $table = "tbl_payment_web_notifications_".date("Ymd");
           }
        $sql = "SELECT ID,MSISDN,PAYMENT_STATUS,PAYMENT_DETAILS,REQUEST_ID from `{$table}` where IS_OUTGOING = '1'   LIMIT 100";
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
           if($channel ==="RENEWAL"){   
               $table = "tbl_payment_renewal_notifications_".date("Ymd");
           }
           if($channel ==="RENEWAL_TEST"){
               $table = "tbl_payment_test_renewal_notifications_20210203";
           }
           if($channel ==="WEB"){    
               $table = "tbl_payment_web_notifications_".date("Ymd");
           }
           if($channel ==="MO_RETRY_RENEWAL"){
               
               $table = "tbl_payment_renewal_mo_notifications";
           }
		 if($channel ==="MO_RETRY"){
               
               $table = "tbl_payment_retry_mo_notifications";
           }
           
        $sql = "SELECT *  from `{$table}` where IS_OUTGOING = '1'   LIMIT 10000";
	
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
    }


function updatePayment($table,$where,$value,$updateArray=[],$msisdn){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}' AND MSISDN = '$msisdn' order by id desc limit 1";
        
        $this->dbConn->query($sql);
        return $this->dbConn->affected_rows;
}

    
    
function update($table,$where,$value,$updateArray=[]){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}'";
        
        try{
        $this->dbConn->query($sql);
        $rows = $this->dbConn->affected_rows;
        if($rows <=0){
            
          $this->dbConn->query($sql);  
          $rows = $this->dbConn->affected_rows;
        }
        return $rows;
        }
        catch(Exception $ex){
            file_put_contents("QueryError.txt", "SQL :".$sql." Error :".$ex->getMessage(),FILE_APPEND);
            return 0;
        }
}

function clearBoomCustomerBase(){
    $sql = "TRUNCATE tbl_boom_customer_base";
    $this->dbConn->query($sql);
    return $this->dbConn->affected_rows;
}

function loadBoomCustomerBase(){
    $sql = "INSERT INTO tbl_boom_customer_base(MSISDN) SELECT MSISDN FROM tbl_qn_subscribers WHERE CUSTOMER_TYPE='BOOM_CUSTOMER'";
    $this->dbConn->query($sql);
    return $this->dbConn->affected_rows;
}

function answerDetails($txnID){
    
    //$sql = "SELECT EARNED_POINTS,BILLING_STATUS from tbl_qn_answers where TXN_ID = ".$txnID;
    	$sql = "SELECT EARNED_POINTS,BILLING_STATUS,TXN_ID,ANSWER,ANSWER_STATUS,QN_ID from tbl_qn_answers where TXN_ID = '".$txnID."'";
        $resultArray = $this->dbConn->query($sql);
        $result = $resultArray->fetch_array();
        return $result;
}

function transactionDetails($requestID,$msisdn){
    
    $sql = "SELECT * FROM vas_transaction_trivia WHERE REQUEST_ID = '{$requestID}' AND MSISDN = '{$msisdn}' ORDER BY ID DESC limit 1";
    
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
$sql = "SELECT {$columnName},timeline from tigo_soka_string_append where  (timeline <= '{$time}'  or timeline= 'default') and {$columnName} is not null  and status  = 'ENABLED'order by timeline asc limit 1 ";
}else{
$prevTime = date("H:i:s",strtotime("-3 Hours"));
$sql = "SELECT {$columnName},timeline from tigo_soka_string_append where  (timeline <= '{$time}'  or timeline= 'default') and timeline > '{$prevTime}' and {$columnName} is not null and status  = 'ENABLED' order by timeline asc limit 1 ";
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
    $sql = "SELECT ACCESS_TOKEN FROM user_access_token order by id desc limit 1";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result['ACCESS_TOKEN'];
}

function _update($table,$where,$value,$updateArray=[]){
    $updateString = $this->arrayToString($updateArray);
        $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}'";
	
        try{
        $this->dbConn->query($sql);
        $rows = $this->dbConn->affected_rows;
        if($rows <=0){
            
        $this->dbConn->query($sql);
        $rows = $this->dbConn->affected_rows;
            
        }
        return $rows;
        }
        catch(Exception $ex){
              file_put_contents("QueryError.txt", "SQL :".$sql." Error :".$ex->getMessage(),FILE_APPEND);
            return 0;
        }
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

function updateRenewal($table,$where,$value,$updateArray=[],$msisdn){
    $updateString = $this->arrayToString($updateArray);
    $sql = "UPDATE `{$table}` SET {$updateString} WHERE {$where} = '{$value}' AND MSISDN = '$msisdn'";
	$this->dbConn->query($sql);
    return $this->dbConn->affected_rows;
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

function getSmsDetails($smsID){
    
    $sql = "SELECT TEXT,SMS_TYPE,MO_ID,TXN_ID,STATUS_CODE from trivia_interaction_mt_logs where ID = ".$smsID;
    
    $resultArray = $this->dbConn->query($sql);
    $row = $resultArray->fetch_assoc();
    return $row;
}



function getSmsReprocess(){
    
    $sql = "SELECT * FROM sms_reprocess where IS_OUTGOING = '1' and RETRY_TIME <= now() and date(RETRY_TIME) = curdate()";
    $resultArray = $this->dbConn->query($sql);
    $result = [];
    while($row = $resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;    
}


function getActivationbase(){   
    $sql = "SELECT MSISDN,SUB_ID from soka_subs_activate_process where IS_PROCESSED = '1' limit 30";
    $resultArray = $this->dbConn->query($sql);
    $result = [];
    while($row = $resultArray->fetch_assoc()){
        $result[] = $row;
    }
    return $result;
    
}


function getOpenQuestion($scheduleID){
    $sql = "SELECT ID,CONTENT FROM  openquestion_schedule where ID = $scheduleID order by id desc limit 1";
    $resultArray = $this->dbConn->query($sql);
    $result = $resultArray->fetch_assoc();
    return $result;
    
}


function getOpenquestionNotifications(){
        //$sql = "SELECT ID,MSISDN,SCHEDULE_ID  from tbl_openquestion_notifications  where IS_OUTGOING = '1' LIMIT 20";
        $sql = "SELECT noti.ID as  'ID' ,noti.msisdn as  'MSISDN' ,noti.SCHEDULE_ID as  'SCHEDULE_ID',sch.content as 'CONTENT'  from tbl_openquestion_notifications noti inner join openquestion_schedule sch on sch.ID = noti.SCHEDULE_ID  where IS_OUTGOING = '1'    LIMIT 20";
        $resultArray = $this->dbConn->query($sql);
        $result = array();
        while ($row = $resultArray->fetch_assoc()) {
            $result[] = $row;
        }
        return $result;
}


function getPromtionStatus(){
    
$sql = "SELECT ID,STATUS FROM tbl_tigo_soka_promo_renewal_status";
$resultArray=$this->dbConn->query($sql);
$result = $resultArray->fetch_array();

    return $result;
}

function arrayToString($arrayData):string{
array_walk($arrayData, function(&$value, $key){
        $value = "{$key} = '{$value}'";
    });
$string = implode(',',array_values($arrayData)); 
    
return $string;
        
    }

function fetchRow($sql) {
    $result = $this->dbConn->query($sql);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

function fetchAll($sql) {
    $result = $this->dbConn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function getTransactionById($txnID, $msisdn) {
    $txnID = (int) $txnID;
    $sql = "SELECT * FROM vas_transaction_trivia WHERE ID = {$txnID} AND MSISDN = '{$msisdn}' LIMIT 1";
    return $this->fetchRow($sql);
}

function execSql($sql) {
    return $this->dbConn->query($sql);
}
    
    
}
