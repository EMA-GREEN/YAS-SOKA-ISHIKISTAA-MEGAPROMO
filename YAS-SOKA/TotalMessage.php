<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn = connect_db();


if($conn){
$hour = date('H', strtotime('-1 hour'));
$date = date("Ymd");
$sql = "SELECT SUM(CASE WHEN TEXT  in ('A','B','a','b') THEN 1  ELSE 0 END) AS 'ANSWERD_A_B',SUM(CASE WHEN TEXT  not in('B','A','a','b') THEN 1 ELSE 0 END) AS 'OTHER_MESSAGES', count(*)  AS 'TOTAL_INTERACTIONS' FROM  tbl_mo_notifications WHERE HOUR(created_at) = '{$hour}' and date(created_at) = curdate()";

$result =  mysqli_query($conn,$sql);

$request = array();
$row = mysqli_fetch_assoc($result);





$sms = "Total Interactions at ".$hour."00 : ".$row['TOTAL_INTERACTIONS']." \n";
$sms .= "Total A/B : ".$row["ANSWERD_A_B"] ." \n";
$sms .= "Other Messages : ".$row  ["OTHER_MESSAGES"];

processSms($sms);


}



function processSms($sms){
$text = $sms;

$sms = urlencode($sms);
file_get_contents("http://192.168.168.2/sendsms/api/?sms=".$sms."&Msisdn=255788949496&securityKey=servSms12Gr33ntice&OPERATOR=AIRTEL");
file_get_contents("http://localhost/chargingservice/Tigo-Timwe.php?msisdn=255712505004&amount=0&key=grEenYcHarge12&content=".$sms."&application=SOKA_SHINDA");
file_get_contents("http://192.168.168.2/sendsms/api/?sms=".$sms."&Msisdn=255747007009&securityKey=servSms12Gr33ntice&OPERATOR=VODACOM");
}
function connect_db(){
    $true = mysqli_connect("localhost", "trivia_consumer", "bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
    if($true){

        return $true;
    }
    else{

        return false;
    }

}

