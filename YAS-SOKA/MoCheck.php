<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
$conn = connect_db();
if(!$conn){
exit;
}


$table = "tbl_interaction_mo_notifications";
$sql = "SELECT MAX(CREATED_AT) AS 'LAST_MO_TIME' FROM `".$table."`";
$result  = mysqli_query($conn,$sql);
$successTime = mysqli_fetch_assoc($result);
$lastSuccess = strtotime($successTime["LAST_MO_TIME"]);
$currentTime = strtotime(date("Y-m-d H:i:s"));
$timeDiff = $currentTime - $lastSuccess;
if(date("H") < 8){

$interval = 90;
}
else{
$interval = 60;
}
if($timeDiff >= $interval){
$sms = "PLEASE CHECK MO LAST MO RECEIVED AT ".$successTime["LAST_MO_TIME"];
processSms($sms);
sendEmail($sms);
}


function processSms($sms){
$text = $sms;
echo $text;
$sms = urlencode($sms);
//file_get_contents("http://10.4.9.3:13013/cgi-bin/sendsms?username=airtelTX&password=greentx&to=255687450515&text=$sms&from=15670&dlr-mask=31");
//file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255659256870&text=$sms&from=15670&dlr-mask=0&priority=3");
//file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
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


function sendEmail($sms){



        $subject = "Mo - ALERT ";

$to = "Emanuel Julius<emanuel@greentelecom.co.tz>";

$message = $sms;


$header = "From:Green Telecom Server <noreply-server@greentelecom.co.tz> \r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-type: text/html\r\n";
        $retval = mail ($to,$subject,$message,$header);
}

