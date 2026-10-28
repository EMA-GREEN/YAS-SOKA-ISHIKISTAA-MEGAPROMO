<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
require_once 'DbConfig.php';
$conn = connect_db();
$tempTable = 'truncate tbl_renewal_content_notifications_temp';
$conn->query($tempTable);


$prevTable = "tbl_payment_renewal_notifications_".date("Ymd",strtotime("-1 days"));
$currTable = "tbl_payment_renewal_notifications_".date("Ymd");
$prevsql = "INSERT INTO tbl_renewal_content_notifications_temp(`MSISDN`,`REQUEST_TYPE`)(select MSISDN,'RENEWAL' from `{$prevTable}` where hour(created_at) >=23 and PAYMENT_STATUS = 'SUCCESS' and PROMO_STATUS = 'OFF')";
$conn->query($prevsql);
$cursql = "INSERT INTO tbl_renewal_content_notifications_temp(`MSISDN`,`REQUEST_TYPE`)(select MSISDN,'RENEWAL' from `{$currTable}` where hour(created_at) <=6 and PAYMENT_STATUS = 'SUCCESS' and  PROMO_STATUS = 'OFF')";
$conn->query($cursql);
//reload notification table 

$notification = "INSERT INTO tbl_renewal_content_notifications(`MSISDN`,`REQUEST_TYPE`,`CNT`)(SELECT MSISDN,'RENEWAL',count(msisdn) as `cnt` from tbl_renewal_content_notifications_temp group by msisdn order by cnt desc)";
$conn->query($notification);

function connect_db(){
    $conn = new mysqli(DB_HOST, DB_CONSUMER_USER, PASSWORD, TRIVIA_DB);
    if($conn){
        return $conn;
    }
    else{
        return false;
    }

}

