<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
require_once 'DbConfig.php';
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn = connect_db();


if($conn){

 $hour = date('H', strtotime('-1 hour'));


$date = date("Ymd");
$sql = "SELECT SUM(CASE WHEN TEXT  in ('A','B','a','b') THEN 1  ELSE 0 END) AS 'ANSWERD_A_B',SUM(CASE WHEN TEXT  not in('B','A','a','b') THEN 1 ELSE 0 END) AS 'OTHER_MESSAGES', count(*)  AS 'TOTAL_INTERACTIONS' FROM  trivia_mo_$date WHERE HOUR(date) = '".$hour."'";
$result =  mysqli_query($conn,$sql);

$request = array();
$row = mysqli_fetch_assoc($result);





$sms = "Total Interactions at ".$hour.":00 : ".$row['TOTAL_INTERACTIONS']." \n";
$sms .= "Total A/B : ".$row["ANSWERD_A_B"] ." \n";
$sms .= "Other Messages : ".$row  ["OTHER_MESSAGES"];

processSms($sms);


}



function processSms($sms){
$text = $sms;
$sms = urlencode($sms);
file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255747002002&sms=$sms&service=TIGOSOKA");
file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255742114755&sms=$sms&service=TIGOSOKA");
file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255712505004&text=$sms&from=15670&dlr-mask=0&priority=3");
file_get_contents("http://192.168.168.3:13013/cgi-bin/sendsms?username=Green_Telecom&password=123456&to=255655807727&text=$sms&from=15670&dlr-mask=0&priority=3");
return TRUE;
}
function connect_db(){
    $true = mysqli_connect(DB_HOST, DB_CONSUMER_USER, PASSWORD,"trivia_tigotz_vas");
    if($true){

        return $true;
    }
    else{

        return false;
    }

}

