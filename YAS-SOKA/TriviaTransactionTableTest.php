<?php
$i=3;
date_default_timezone_set("Africa/Dar_Es_Salaam");

$date = date("Ymd", strtotime("+".$i." DAY "));

$mpushYasSokaSubsInitNotificationsTable = "CREATE TABLE `tbl_mpush_subs_init_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `CHANNEL` enum('SMS','USSD','APP') NOT NULL DEFAULT 'USSD',
  `USSD_DATA` text,
  `IS_OUTGOING` int(11) NOT NULL DEFAULT 1,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$mpushSubsInitActivationLogsTable = "CREATE TABLE `tbl_mpush_subs_init_activation_logs_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `REQUEST_ID` varchar(1000) DEFAULT NULL,
  `REQUEST_SENT` text NOT NULL,
  `RESPONSE_RECEIVED` text,
  `RESP_TIME` datetime DEFAULT NULL,
  `USSD_DATA` text,
  `CONFIRMED_STATUS` int(11) NOT NULL DEFAULT 0,
  `CONFIRMED_AT` datetime DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `REQUEST_ID` (`REQUEST_ID`),
  KEY `CONFIRMED_STATUS` (`CONFIRMED_STATUS`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";


$conn = connect_db();
if($conn){
    mysqli_query($conn,$mpushYasSokaSubsInitNotificationsTable);
    mysqli_query($conn,$mpushSubsInitActivationLogsTable);
}

function connect_db(){

    $conn = mysqli_connect("localhost","ishikistaa_tablecreat","K9Nef7k4FPXNCwVm@","trivia_tigotz_vas");
    if($conn){

        return $conn;
    }
    else{

        return false;
    }
}
