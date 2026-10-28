<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');

$dbfile = loadfile('db.config');
$conn = __connect($dbfile);


$tempTable = 'truncate tbl_renewal_content_notifications_temp';
$conn->query($tempTable);


$prevTable = "tbl_payment_renewal_notifications_".date("Ymd",strtotime("-1 days"));
$currTable = "tbl_payment_renewal_notifications_".date("Ymd");



$prevsql = "INSERT INTO tbl_renewal_content_notifications_temp(`MSISDN`,`REQUEST_TYPE`)(select MSISDN,'RENEW' from `{$prevTable}` where hour(created_at) > 22 and PAYMENT_STATUS = 'SUCCESS')";
$conn->query($prevsql);
$cursql = "INSERT INTO tbl_renewal_content_notifications_temp(`MSISDN`,`REQUEST_TYPE`)(select MSISDN,'RENEWAL' from `{$currTable}` where hour(created_at) < 8 and PAYMENT_STATUS = 'SUCCESS')";

$conn->query($cursql);

$notification = "INSERT INTO tbl_renewal_content_notifications(`MSISDN`,`REQUEST_TYPE`,`CNT`)(SELECT MSISDN,'RENEWAL',count(msisdn) as `cnt` from tbl_renewal_content_notifications_temp group by msisdn order by cnt desc)";
$conn->query($notification);

function __connect($dbfile=[]) {
        $mysql = new \mysqli($dbfile['DB_HOST'], $dbfile['PUBLISHER_USER'],$dbfile['DB_PASSWORD'] , $dbfile['DB_NAME']);
        return $mysql;
    }

function loadfile($file) {
        $result = file_get_contents($file);
        $data = parse_ini_string($result);
        return $data;
    }

