<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
$conn = __connect();
$interactionTable = "tbl_payment_interaction_notifications";
$renewalTable     = "tbl_payment_renewal_notifications_".date("Ymd");
$prevTime = date("Y-m-d H:i:s",strtotime("-1 Minute"));


$interactionsql = "SELECT MSISDN,REQUEST_ID,PAYMENT_DETAILS,'InteractiveService' AS 'SERVICE_TYPE',CREATED_AT FROM  {$interactionTable} where payment_status <> 'SUCCESS' and PAYMENT_DETAILS not like '%low%' and PAYMENT_DETAILS not like '%diameter%'order by id desc limit 10";
$result  = $conn->query($interactionsql);
$resultArray = [];

while($row=$result->fetch_assoc()){
    $resultArray[] = $row;
}
$renewalSql = "SELECT MSISDN,REQUEST_ID,PAYMENT_DETAILS,'AdvanceCredit' AS 'SERVICE_TYPE',CREATED_AT FROM  {$renewalTable} where payment_status <> 'SUCCESS' and PAYMENT_DETAILS not like '%low%' and PAYMENT_DETAILS not like '%diameter%' order by id desc limit 10";
$results  = $conn->query($renewalSql);
$resultArrays = [];
while($rows=$results->fetch_assoc()){
    $resultArrays[] = $rows;
}
if(empty($resultArrays) && empty($resultArray)){
    exit;
}
$message = "";



$message .= "<table><thead><th>Request ID</th><th>MSISDN</th><th>ERROR</th><th>SERVICE TYP</th><th>TIME</th><tbody>";

foreach($resultArray as $key=>$value){
    if(strtotime($resultArray[$key]['CREATED_AT']) >= strtotime($prevTime)){
        $message .="<td>";
        $message .= "REQUEST ID:".$resultArray[$key]['REQUEST_ID']."</td>";
        $message .="<td>".$resultArray[$key]['MSISDN']."</td>";
        $message .="<td>".$resultArray[$key]['PAYMENT_DETAILS']."<td>";
        $message .= "<td>".$resultArray[$key]['SERVICE_TYPE']."</td>";
        $message .= "<td>".$resultArray[$key]['CREATED_AT']."</td>";
        $message .= "</tr>";
        
    }
}



foreach($resultArrays as $key=>$value){
    if(strtotime($resultArrays[$key]['CREATED_AT']) >= strtotime($prevTime)){
        $message .="<tr>";
        $message .= "<td>".$resultArrays[$key]['REQUEST_ID']."</td>";
        $message .= "<td>".$resultArrays[$key]['MSISDN']."</td>";
        $message .= "<td>".$resultArrays[$key]['PAYMENT_DETAILS']."</td>";
        $message .= "<td>".$resultArrays[$key]['SERVICE_TYPE']."</td>";
        $message .= "<td>".$resultArrays[$key]['CREATED_AT']."</td>";
        $message .="</tr>";
    }
}

$message .="</tbody></thead></table>";


if(empty($message)||$message===""||$message==="<table><thead><th>Request ID</th><th>MSISDN</th><th>ERROR</th><th>SERVICE TYP</th><th>TIME</th><tbody></tbody></thead></table>"){

 exit;   
    
}

sendEmail($message);


echo $message;

function sendEmail($sms){



        $subject = "Tigo TestZali Charging Error- ALERT ";

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

