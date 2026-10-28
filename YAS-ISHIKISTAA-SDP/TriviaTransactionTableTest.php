<?php
$i=3;
date_default_timezone_set("Africa/Dar_Es_Salaam");

$date = date("Ymd", strtotime("+".$i." DAY "));

$pointpurchasetable = "CREATE TABLE `tbl_point_purchase_".$date."` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `MO_ID` bigint(20) DEFAULT NULL,
  `MSISDN` bigint(20) DEFAULT NULL,
  `REQUEST_ID` varchar(1000) DEFAULT NULL,
  `AMOUNT` int(11) DEFAULT NULL,
  `POINTS` int(11) DEFAULT NULL,
  `CHANNEL` varchar(100) DEFAULT 'SMS',
  `STATUS` varchar(1000) DEFAULT NULL,
  `RECORD_DATE` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MO_ID` (`MO_ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `REQUEST_ID` (`REQUEST_ID`),
  KEY `RECORD_DATE` (`RECORD_DATE`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8";

$mpushYasIshikistaaSubsInitNotificationsTable = "CREATE TABLE `tbl_mpush_subs_init_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `CHANNEL` enum('SMS','USSD','APP') NOT NULL DEFAULT 'USSD',
  `USSD_DATA` text DEFAULT NULL,
  `IS_OUTGOING` int(11) NOT NULL DEFAULT 1,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$mpushYasIshikistaaSubsInitActivationLogsTable = "CREATE TABLE `tbl_mpush_subs_init_activation_logs_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `REQUEST_ID` varchar(1000) DEFAULT NULL,
  `REQUEST_SENT` text NOT NULL,
  `RESPONSE_RECEIVED` text,
  `RESP_TIME` datetime DEFAULT NULL,
  `USSD_DATA` text DEFAULT NULL,
  `CALLBACK_STATUS` int(11) NOT NULL DEFAULT 0,
  `CALLBACK_AT` datetime DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `REQUEST_ID` (`REQUEST_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$conn = connect_db();
if($conn){
	mysqli_query($conn,$mpushYasIshikistaaSubsInitNotificationsTable);
	mysqli_query($conn,$mpushYasIshikistaaSubsInitActivationLogsTable);
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