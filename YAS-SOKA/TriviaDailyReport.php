<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn = connect_db();


if($conn){
$date = date("Ymd",strtotime("-1 Days"));
$txDate = date("Y-m-d",strtotime("-1 Days"));
$sql = "SELECT COUNT(*) AS 'CHARGED_NUMBER',count(distinct MSISDN) as 'UNIQUE_CHARGED' ,SUM(AMOUNT) AS 'REVENUE'  FROM vas_transaction_trivia_".$date." WHERE  TXN_STATUS like '%SUCCESS%'";

$result =  mysqli_query($conn,$sql);

$row = mysqli_fetch_assoc($result);

$sms = "Total charged : ".$row['CHARGED_NUMBER'] . " \n";
$sms .= "Total Unique charged : ".$row['UNIQUE_CHARGED']. " \n";
$sms .=  "Total Revenue Collected : ".$row['REVENUE']. " \n";




$sqlSub = "SELECT COUNT(*) AS 'SUBSCRIBERS' FROM  tbl_qn_subscribers WHERE date(RECORD_DATE) = '{$txDate}'";
$result =  mysqli_query($conn,$sqlSub);


$sqlunSub = "SELECT COUNT(distinct MSISDN) AS 'UNSUBS' FROM  tbl_unsubs WHERE date(CREATED_AT) =  '{$txDate}' ";
$unsubresult =  mysqli_query($conn,$sqlunSub);


$subrow = mysqli_fetch_assoc($result);
$unsubrow = mysqli_fetch_assoc($unsubresult);
$sms .= "Subscription-Un Subscription Report for" . $txDate;
$sms .= "Subscribers Gained  : ".$subrow['SUBSCRIBERS'] . " \n";
$sms .= "Unsubscriptions  : ".$unsubrow['UNSUBS']. " \n";
processSms($sms);
}

function processSms($sms){
$text = $sms;
$sms = urlencode($sms);
file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255747002002&sms=$sms&service=TIGOSOKA");
file_get_contents("http://10.4.9.2/VODACOMSMS-GATEWAY/?msisdn=255742114755&sms=$sms&service=TIGOSOKA");
file_get_contents("http://192.168.168.6/chargingservice/Tigo-Timwe.php?msisdn=255656692469&amount=0&key=grEenYcHarge12&content=".$sms."&application=SOKA_SHINDA");
file_get_contents("http://192.168.168.6/chargingservice/Tigo-Timwe.php?msisdn=255712505004&amount=0&key=grEenYcHarge12&content=".$sms."&application=SOKA_SHINDA");
file_get_contents("http://192.168.168.6/chargingservice/Tigo-Timwe.php?msisdn=255656692469&amount=0&key=grEenYcHarge12&content=".$sms."&application=SOKA_SHINDA");
}

function connect_db(){
$conn = mysqli_connect("localhost","analysis_email","xc28FhrWV7wtXVeg@@@","trivia_tigotz_vas");
if($conn){
    return $conn;
}
    else{
        return false;
    }
}

