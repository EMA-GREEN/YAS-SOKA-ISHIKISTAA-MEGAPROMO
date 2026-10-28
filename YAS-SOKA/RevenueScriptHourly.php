<?php

date_default_timezone_set('Africa/Dar_ES_Salaam');
require_once '../PHPMailer/MailerScript.php';
require_once "SokaLib.php";
require_once "SokaServices.php";
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
$sql = "SELECT count(*) AS 'TOTAL_ERROR' FROM vas_transaction_trivia_".$date."  WHERE  TXN_STATUS = 'INTERNAL_ERROR' AND resp_time >= '".$internalError."'";     
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
processMail($row['CHARGED_NUMBER'],$row['UNIQUE_CHARGED'],$row['REVENUE']);

}


function processSms($sms){
    $text = $sms;
    $sms = urlencode($sms);

    file_get_contents("http://102.218.75.3/VODACOMSMS-GATEWAY/?msisdn=255747002002&sms=$sms&service=TIGOSOKA");
    file_get_contents("http://102.218.75.3/VODACOMSMS-GATEWAY/?msisdn=255742114755&sms=$sms&service=TIGOSOKA");
    /*file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
    file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255655807727&text=$sms&from=15670&dlr-mask=0&priority=3");
    file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255656692469&text=$sms&from=15670&dlr-mask=0&priority=3");*/
    sendSMS("255712505004", $text);
    sendSMS("255655807727", $text);
    sendSMS("255656692469", $text);
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

function processMail($totalCharged,$unique,$revenue){
    $subject = "Tigo-SokaTrivia-".date("Y-m-d");
    $message = '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total Charged</th><th>Unique Charged</th><th>Revenue</th></tr>';
    $message .="<tr><td>".$totalCharged."</td><td>".$unique."</td><td>".$revenue."</td></tr></table>";
    $to = [];
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']  	=  "Emanuel Julius";
$to[1]['Mail']          = 'reports@greentelecom.co.tz';
$to[1]['Name']  	= 'Reports';
$cc [0]['Mail']         = 'richard@greentelecom.co.tz';
$cc [0]['Name']         = 'Richard Matemba Joseph';
$cc [1]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc [1]['Name']         = 'Hemedi Ali Hemedi';
$cc [2]['Mail']         = 'emanuel@greentelecom.co.tz';
$cc [2]['Name']         = 'Emanuel Julius';

sendEmail($message, $subject,$to,$cc);
}

function sendSMS($msisdn, $sms){
    $requestID = "E" . date("Ymdhis") . $msisdn;
    $sokaServicesObj = new SokaServices("consumer");
    $userAccessToken = $sokaServicesObj->userAccessToken();
    $sokaLib = new SokaLib($msisdn, $userAccessToken, $requestID, 0);
    $response = $sokaLib->sendReportSMS($sms);
    $sokaServicesObj->closeDB();
}
