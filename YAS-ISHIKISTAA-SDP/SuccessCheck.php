<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
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
if($timeDiff >= 60){
$sms = "PLEASE CHECK Tigo Ishikista Trivia Charging. LAST SUCCESSFULL CHARGING WAS  AT  ".$successTime["LAST_SUCCESS_TIME"]; 
processSms($sms);
sendEmail($sms);
}


function processSms($sms){
$text = $sms;	
$sms = urlencode($sms);
//file_get_contents("http://192.168.168.2/sendsms/api/?sms=".$sms."&Msisdn=255684696995&securityKey=servSms12Gr33ntice&OPERATOR=AIRTEL");
#file_get_contents("http://192.168.168.2/sendsms/api/?sms=".$sms."&Msisdn=255788949496&securityKey=servSms12Gr33ntice&OPERATOR=AIRTEL");
#file_get_contents("http://192.168.168.2/sendsms/api/?sms=".$sms."&Msisdn=255747007009&securityKey=servSms12Gr33ntice&OPERATOR=VODACOM");
file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255659256870&text=$sms&from=15670&dlr-mask=0&priority=3");
#file_get_contents("http://192.168.168.6:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
}

function connect_db(){
	
$conn = mysqli_connect("localhost","trivia_alert","xc28FhrWV7wtXVeg@@@","ishikistaa_tigo_vas");

if($conn){
	
	return $conn;
}
else{
	
	return false;
}

}


function sendEmail($sms){



	$subject = "Trivia Charging - ALERT ";

$to = "Koushik Govind Raju<koushik@greentelecom.co.tz>";

$message = $sms;
         

$header = "From:Green Telecom Server <noreply-server@greentelecom.co.tz> \r\n";
    
	$header .= "Cc:Rahul Sahni <rahul@greentelecom.co.tz> \r\n";
	$header .= "Cc:Hemedi <hemedi@greentelecom.co.tz> \r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-type: text/html\r\n";
        $retval = mail ($to,$subject,$message,$header);
}


?>
