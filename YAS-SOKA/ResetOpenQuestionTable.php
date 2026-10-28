<?php
require_once 'DbConfig.php';

function connect_db(){

    $conn = new mysqli(DB_HOST, PUBLISHER_USER, PASSWORD, TRIVIA_DB);
    if($conn){

        return  $conn;
    }else{

        return false;
    }

}
$conn = connect_db();
if($conn){
    $checkTable  = "select count(*) as  'TOTAL' from tbl_openquestion_notifications";
    $result = $conn->query($checkTable);
    $resultArray =  $result->fetch_assoc();
   if(empty($resultArray)||$resultArray['TOTAL']==="0"){
        exit;
        }
    else{
    $checkPending = "select count(*) as 'PENDING' from tbl_openquestion_notifications where is_outgoing = '1'";
    
    $resultPending = $conn->query($checkPending);
    $resultPendingArray =  $resultPending->fetch_assoc();
    if(empty($resultPendingArray)||$resultPendingArray['PENDING']==="0"){
    $sql = "TRUNCATE tbl_openquestion_notifications";
       $conn->query($sql);
	echo "Truncating Table"; 
	exit;
    }else{
        echo "Still Progressing".$resultPendingArray['PENDING'];
	exit;
        
    }
  }
}











