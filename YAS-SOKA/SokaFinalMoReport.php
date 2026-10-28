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
$sql = "SELECT SUM(CASE WHEN TEXT  in ('A','B','a','b') THEN 1  ELSE 0 END) AS 'ANSWERD_A_B',SUM(CASE WHEN TEXT  not in('B','A','a','b') THEN 1 ELSE 0 END) AS 'OTHER_MESSAGES', count(*)  AS 'TOTAL_INTERACTIONS',hour(date) as 'HOUR' FROM  trivia_mo_$date group by HOUR(date)";
$result =  mysqli_query($conn,$sql);

$mo = [];
while($row = mysqli_fetch_assoc($result)){

$mo[] = $row;

}
$message = '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><thead><tr style="color:White;background-color:green;font-weight:bold"><th>Hour</th><th>A/B</th><th>Other Messages</th><th>Total</th></tr></thead><tbody>';

foreach($mo as $key=>$value){

$hour = $mo[$key]['HOUR'];
$ansAB = $mo[$key]['ANSWERD_A_B'];
$othmessages = $mo[$key]['OTHER_MESSAGES'];
$totalMessage = $mo[$key]['TOTAL_INTERACTIONS'];

$message .= "<tr><td>".$hour."</td><td>".$ansAB."</td><td>".$othmessages."</td><td>".$totalMessage."</td></tr>";
}

$message .="</tbody></table>";

processMail($message);

}

function processMail($message){
$subject = "Tigo-Soka-MoReport".date("Y-m-d",strtotime("-1 Days"));

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

