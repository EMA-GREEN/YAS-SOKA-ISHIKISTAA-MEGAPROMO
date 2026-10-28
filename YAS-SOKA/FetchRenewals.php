<?php

//$connet = connect_db();



$paytable  =  "tbl_payment_renewal_notifications_";

$startDate = "2023-08-01";
$endDate   = "2023-09-13";

 //$days_between = ceil(abs(strtotime($endDate) - strtotime($startDate)) / 86400);
 //$noDays =  $days_between;

 $conn = connect_db();
 $step = "+1 day";
    $dates = array();
    $current = strtotime($startDate);
    $last = strtotime($endDate);
    while ($current <= $last) {
        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }


     foreach ($dates as $table) {


        $tbl = $paytable.date("Ymd", strtotime($table));
        $sql = "INSERT INTO  tbl_payment_renewal_success_202308_202309(`MSISDN`,`SERVICE`,`CREATED_AT`)(SELECT MSISDN,CASE WHEN REQUEST_ID LIKE '%WF%'THEN 'FALLBACK' ELSE 'SOKA'  END AS SERVICE, `CREATED_AT` FROM `".$tbl."` WHERE PAYMENT_STATUS = 'SUCCESS')";
        echo $sql. "\n";
        $conn->query($sql);

     }


function connect_db(){

    $conn = new Mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}

