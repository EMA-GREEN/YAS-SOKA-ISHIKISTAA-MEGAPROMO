<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
require_once 'DbConfig.php';
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn = connect_db();
require_once '../PHPMailer/MailerScript.php';

if($conn){
$date = date("Ymd",strtotime("-1 days"));
$sql = "SELECT hour(txn_date) as 'HOUR',SUM(CASE WHEN  AMOUNT in (99) THEN 1  ELSE 0 END) AS '99',sum(CASE WHEN  AMOUNT in (99) THEN 1*99  ELSE 0 END) AS 'SUM_99',SUM(CASE WHEN AMOUNT in(45) THEN 1 ELSE 0 END) AS '45',sum(CASE WHEN  AMOUNT in (45) THEN 1*45  ELSE 0 END) AS 'SUM_45', count(*)  AS 'TOTAL_PAYMENT',sum(AMOUNT) AS 'TOTAL_REVENUE' FROM  vas_transaction_trivia_$date group by HOUR(txn_date)";
$result =  mysqli_query($conn,$sql);

$mo = [];
while($row = mysqli_fetch_assoc($result)){

$mo[] = $row;

}
$message = '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><thead><tr style="color:White;background-color:green;font-weight:bold"><th>Hour</th><th>99</th><th>SUM99</th><th>45</th><th>SUM45</th><th>Total Payments</th><th>Revenue</th></tr></thead><tbody>';

foreach($mo as $key=>$value){

$hour = $mo[$key]['HOUR'];
$n9 = $mo[$key]['99'];
$sum99 = $mo[$key]['SUM_99'];
$f5 = $mo[$key]['45'];
$sum45 = $mo[$key]['SUM_45'];
$total = $mo[$key]['TOTAL_PAYMENT'];
$revenue = $mo[$key]['TOTAL_REVENUE'];
$message .= "<tr><td>".$hour."</td><td>".$n9."</td><td>".$sum99."</td><td>".$f5."</td><td>".$sum45."</td><td>".$total."</td><td>".$revenue."</td></tr>";
}

$message .="</tbody></table>";

processMail($message);

}

function processMail($message){
$subject = "Tigo-Soka-Transaction-Report".date("Y-m-d",strtotime("-1 Days"));

$to = [];    
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']  	=  "Emanuel Julius";
$cc [0]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc [0]['Name']         = 'Hemedi Ali Hemedi';
sendEmail($message, $subject,$to,$cc);
$message = str_replace("<td><u></u></td>","",$message);
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

