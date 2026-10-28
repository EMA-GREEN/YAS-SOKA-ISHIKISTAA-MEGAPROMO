<?php

date_default_timezone_set('Africa/Dar_Es_Salaam');
require_once '../PHPMailer/MailerScript.php';
$conn = connect_db("trivia_consumer","bJ++r5tL>xQdG:7H","ishikistaa_tigo_vas");

if($conn){
    if(date("H")==="00"){
      $date = date('Y-m-d',strtotime("-1 day"));
      $sql = "select count(*) as  'Total','TotalRequest Fired' as 'Status',hour(txn_date) 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}'  group by hour(txn_date)   union all select sum(case when txn_status = 'SUCCESS' then 1 end) as 'Total','SUCCESS' as 'Status',hour(txn_date) as 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}'  group by hour(txn_date)  union all  select count(*) as  'Total',payment_details as 'Status',hour(txn_date) 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}' and txn_status <> 'SUCCESS' group by hour(TXN_DATE),PAYMENT_DETAILS";
    }
    else{
        //$table  = "tbl_payment_interaction_notification_".date("Ymd");
      $date = date('Y-m-d');
      $hour = date("H");
      $sql = "select count(*) as  'Total','TotalRequest Fired' as 'Status',hour(txn_date) 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}' and hour(txn_date) <  '{$hour}' group by hour(txn_date)   union all select sum(case when txn_status = 'SUCCESS' then 1 end) as 'Total','SUCCESS' as 'Status',hour(txn_date) as 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}' and hour(txn_date) <  '{$hour}' group by hour(txn_date)  union all  select count(*) as  'Total',payment_details as 'Status',hour(txn_date) 'Hour' from vas_transaction_trivia where date(txn_date) = '{$date}' and hour(txn_date) <  '{$hour}' and txn_status <> 'SUCCESS' group by hour(TXN_DATE),PAYMENT_DETAILS";
    }
    
    
    $result = $conn->query($sql);
    $ishikistaa = [];
    $message = '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Hour</th><th>Description</th><th>Count</th></tr><tr>';
    while($resultArray = $result->fetch_assoc()){

        $ishikistaa [] = $resultArray;
    }
    $hour = [];
    foreach($ishikistaa as $index=>$key){


        $hour[] =  $ishikistaa[$index]['Hour'];
    }
    $hours  = array_unique($hour);
    $report = [];


    for($i=0;$i<sizeof($hours);$i++){
        foreach($ishikistaa as $index=>$key){
            if($hour[$i]===$ishikistaa[$index]['Hour']){
                $hourly = $hour[$i];
                $status = $ishikistaa[$index]['Status'];
                $report[$hourly][$status] = $ishikistaa[$index]['Total'];


            }

        }
    }
$h = 0;

foreach($report as $key=>$value){

    if($h < 10){

        $time = "0".$h."-"."0".$h.":59:59";
    }else{

        $time = $h.":00"."-".$h.":59:59";
    }
   $keys = array_keys($report[$key]);
   $val = array_values($report[$key]);
   $hour = $report[$key];
   for($j=0;$j<sizeof($keys);$j++){
        $statusValue = $keys[$j];
        $count = $report[$key][$statusValue];
        $message .= "<tr><td>".$time."</td><td>" . $statusValue . "</td><td>" . $count . "</td></tr>";

    }

$h++;
}



    $message .= "</tr></table>";
    $body = "</pre><div>";
$body .= $message;
$body .= "</div>" ;




$subject = "Ishikistaa Hourly Interaction Transaction Report - ".$date;

processMail($body, $subject);
}
function  processMail($message,$subject){
$to = [];
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']          =  "Emanuel Julius";
sendEmail($message, $subject,$to,$cc);



}

function connect_db($user,$password,$database){

$mysql  = new mysqli("localhost",$user,$password,$database);
    if($mysql){

        return $mysql;
    }else{

        return false;
    }


}

