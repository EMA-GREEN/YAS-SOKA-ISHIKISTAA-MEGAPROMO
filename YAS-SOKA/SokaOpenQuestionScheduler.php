<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
require_once 'DbConfig.php';
while(true){
    $conn = connect_db();    
    
    $sql = "SELECT * FROM openquestion_schedule where status = 'PENDING'and date(schedule) = curdate() and schedule <= now() order by id desc limit 1";
    $result = $conn->query($sql);
    $resultArray = $result->fetch_array();
    
    if(empty($resultArray)){
        $conn->close();
        sleep(5);
        
    }
    else{
     $scheduleID = $resultArray['ID'];
     $baseType = $resultArray['BASE_TYPE'];
     if($baseType ==="PLAYING"){
         $plaingbaseDate = date("Y-m-d H:i:s",strtotime("-7 days"));
         $sql = "insert into tbl_openquestion_notifications(`MSISDN`,`SCHEDULE_ID`) (SELECT MSISDN,{$scheduleID} from tbl_qn_subscribers where activity_status  = 'ACTIVE' AND DND_STATUS = 'OFF' and (date(LAST_CHARGING) >='$plaingbaseDate' OR date(RECORD_DATE) >= '$plaingbaseDate' OR date(REACT_DATE) >='$plaingbaseDate') order by LAST_CHARGING desc )";
         }   
     elseif($baseType==="TOP"){
             $topbaseDate = date("Y-m-d H:i:s",strtotime("-59 days"));
             $playingbaseDate = date("Y-m-d H:i:s",strtotime("-7 days"));
             $sql = "insert into tbl_openquestion_notifications(`MSISDN`,`SCHEDULE_ID`) SELECT MSISDN,{$scheduleID} from tbl_qn_subscribers where activity_status  = 'ACTIVE' AND DND_STATUS = 'OFF' and (date(LAST_CHARGING) >= '$topbaseDate' and  date(LAST_CHARGING) <  '$plaingbaseDate') OR (date(RECORD_DATE)  >= '$topbaseDate' and date(RECORD_DATE) < '$plaingbaseDate')  OR (date(REACT_DATE)  >= '$topbaseDate' and date(REACT_DATE) < '$plaingbaseDate') order by LAST_CHARGING desc";
         
     }else{//complete base
         
         $sql = "insert into tbl_openquestion_notifications(`MSISDN`,`SCHEDULE_ID`) SELECT MSISDN,{$scheduleID} from tbl_qn_subscribers where activity_status  = 'ACTIVE' AND DND_STATUS = 'OFF'  order by LAST_CHARGING desc";
     }   
     
     $conn->query($sql);
     if($conn->affected_rows > 0){//records inserted
         $sql = "update openquestion_schedule SET status = 'PROCESSED' where ID = $scheduleID";
         $conn->query($sql);
     }
     $conn->close();
    }
    
    sleep(5);
}




function connect_db(){

    $conn = new mysqli(DB_HOST, DB_CONSUMER_USER, PASSWORD, TRIVIA_DB);
    if($conn){
        return $conn;
    }
    else{
        return false;
    }
    
}


