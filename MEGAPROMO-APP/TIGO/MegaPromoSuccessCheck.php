<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');

$conn = __connect();

$table = "tbl_promo_billing_".date("Ymd");

$sql = "SELECT `DATE` AS 'LAST_SUCCESS_TIME'  FROM `tbl_promo_billing` WHERE  STATUS_CODE = 'SUCCESS' order by id desc limit 1";
$result  = $conn->query($sql);
$successTime = $result->fetch_assoc();
$lastSuccess = strtotime($successTime["LAST_SUCCESS_TIME"]);
$currentTime = strtotime(date("Y-m-d H:i:s"));
$timeDiff = $currentTime - $lastSuccess;

if(date("H") < 8){
$interval = 900;
}
else{
$interval = 900;
}
if($timeDiff >= $interval){
$sms = "PLEASE CHECK ZALI Charging. LAST SUCCESSFULL CHARGING WAS  AT  ".$successTime["LAST_SUCCESS_TIME"];
processSms($sms);
sendEmail($sms);
}


function processSms($sms){
$text = $sms;
$sms = urlencode($sms);

//file_get_contents("http://10.4.9.3:13013/cgi-bin/sendsms?username=airtelTX&password=greentx&to=255687450515&text=$sms&from=15670&dlr-mask=31");
file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255656692469&text=$sms&from=15670&dlr-mask=0&priority=3");
//file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
}


function sendEmail($sms){



        $subject = "Tigo TestZali Charging - ALERT ";

$to = "Koushik Govind Raju<koushik@greentelecom.co.tz>";

$message = $sms;


$header = "From:Green Telecom Server <noreply-server@greentelecom.co.tz> \r\n";

        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-type: text/html\r\n";
        $retval = mail ($to,$subject,$message,$header);
}



function __connect() {
        $mysql = new \mysqli("localhost", "megapromo_publisher", "22MbfSJLue4vsgnkL#@", "tigo_megapromo");
        return $mysql;
    }

