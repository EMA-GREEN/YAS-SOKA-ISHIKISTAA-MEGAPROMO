<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
require_once '../PHPMailer/MailerScript.php';
$conn = connect_db();
if(!$conn){

exit;
}


$table = "vas_transaction_trivia_".date("Ymd");

$sql = "SELECT MAX(resp_time) AS 'LAST_SUCCESS_TIME' FROM `".$table."` WHERE  TXN_STATUS = 'SUCCESS' ";
$result  = mysqli_query($conn,$sql);
$successTime = mysqli_fetch_assoc($result);
$lastSuccess = strtotime($successTime["LAST_SUCCESS_TIME"]);
$currentTime = strtotime(date("Y-m-d H:i:s"));
$timeDiff = $currentTime - $lastSuccess;
if(date("H") < 8){

$interval = 900;
}
else{

$interval = 60;
}
if($timeDiff >= $interval){
$sms = "PLEASE CHECK  Trivia Charging. LAST SUCCESSFULL CHARGING WAS  AT  ".$successTime["LAST_SUCCESS_TIME"]; 
processSms($sms);
sendEmail($sms);
}


function processSms($sms){
$text = $sms;	
$sms = urlencode($sms);


//file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255742114755&sms=$sms&service=TIGOSOKA");
file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255656692469&text=$sms&from=15670&dlr-mask=0&priority=3");
file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
}

function connect_db(){
	
$conn = mysqli_connect("localhost","trivia_alert","xc28FhrWV7wtXVeg@@@","trivia_tigotz_vas");

if($conn){
	
	return $conn;
}
else{
	
	return false;
}

}


function processMail($sms){
$subject = "Tigo Soka Trivia Charging - ALERT ".date("Y-m-d");
$to = [];    
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']  	=  "Emanuel Julius";
$to[1]['Mail']          = "rahul@greentelecom.co.tz";
$to[1]['Name']  	=  "Rahul Sahni";
$cc [0]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc [0]['Name']         = 'Hemedi Ali Hemedi';
sendEmail($sms, $subject,$to,$cc);
}
