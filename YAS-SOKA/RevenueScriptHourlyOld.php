<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn = connect_db();


if($conn){
$date = date("Ymd");  
$time = date("Y-m-d H:i:s");
$sql = "SELECT COUNT(*) AS 'CHARGED_NUMBER',count(distinct MSISDN) as 'UNIQUE_CHARGED' ,SUM(AMOUNT) AS 'REVENUE'  FROM vas_transaction_trivia_".$date." WHERE  TXN_STATUS like '%SUCCESS%' and TXN_DATE < '{$time}'";     
$result =  mysqli_query($conn,$sql);

$row = mysqli_fetch_assoc($result);

$sms = $time."\n";
$sms .= "Total charged : ".$row['CHARGED_NUMBER'] . " \n";
$sms .= "Total Unique charged : ".$row['UNIQUE_CHARGED']. " \n";
$sms .=  "Total Revenue Collected : ".$row['REVENUE']. " \n";
$internalError = date('Y-m-d H:i:s', strtotime('-15 minutes'));   
$date = date("Ymd");    
$sql = "SELECT count(*) AS 'TOTAL_ERROR'  FROM vas_transaction_trivia_".$date."  WHERE  TXN_STATUS = 'INTERNAL_ERROR' AND resp_time >= '".$internalError."'";     
$result =  mysqli_query($conn,$sql);
if(empty($result)){   
    $sms .= "Internal Error :  0 \n";
}
else{
$requests = array();
$errorrow = mysqli_fetch_assoc($result);
$requests = $errorrow;
$sms .= "InternalError  :".$requests['TOTAL_ERROR']." \n"; 
}
processSms($sms);
senEmail($row['CHARGED_NUMBER'],$row['UNIQUE_CHARGED'],$row['REVENUE']);
}
function senEmail($totalCharged,$unique,$revenue){
    
$subject = "Tigo-SokaTrivia-".date("Y-m-d");

$to = "Rahul Sahni <rahul@greentelecom.co.tz>";
$message = '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Charged</th><th>Unique Charged</th><th>Revenue</th></tr>';

$message .="<tr><td>".$totalCharged."</td><td>".$unique."</td><td>".$revenue."</td></tr></table>";



$header = "From:Green Telecom Server <noreply@greentelecom.co.tz> \r\n";
$header .="Cc:Hemedi <hemedi@greentelecom.co.tz>";
$header .= "Cc:Koushik Govind Raju <koushik@greentelecom.co.tz> \r\n";
$header .="Cc:Sai Krishna <krithik@greentelecom.co.tz>";
$header .= "Cc:Richard <richard@greentelecom.co.tz> \r\n";
$header .= "MIME-Version: 1.0\r\n";
$header .= "Content-type: text/html\r\n";
$retval = mail ($to,$subject,$message,$header);

    
    
    
}
function processSms($sms){
$text = $sms;
$sms = urlencode($sms);

file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255747002002&sms=$sms&service=TIGOSOKA");
file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255742114755&sms=$sms&service=TIGOSOKA");
file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255655807727&text=$sms&from=15670&dlr-mask=0&priority=3");
}

function connect_db(){
$conn = mysqli_connect("localhost", "analysis_email", "xc28FhrWV7wtXVeg@@@","trivia_tigotz_vas");
if($conn){  
    return $conn;
}
    else{
        return false;
    }
}
