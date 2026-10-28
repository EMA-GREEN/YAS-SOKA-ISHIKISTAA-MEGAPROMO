<?php
$i=3;
date_default_timezone_set("Africa/Dar_Es_Salaam");

$date = date("Ymd", strtotime("+".$i." DAY "));

$txntable = "CREATE TABLE `vas_transaction_trivia_".$date."` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `MO_ID` bigint(20) NULL DEFAULT 0,
  `TXN_ID` varchar(50) NOT NULL,
  `MSISDN` bigint(20) NOT NULL,
  `AMOUNT` int(11) NOT NULL,
  `CHANNEL` varchar(100) NOT NULL DEFAULT 'SMS',
  `REQUEST_SENT` text NOT NULL,
  `TXN_STATUS` varchar(1000) NOT NULL DEFAULT 'SENT',
  `RESPONSE_RECEIVED` text,
  `RESP_TIME` datetime NOT NULL,
  `TXN_DATE` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx` (`AMOUNT`,`CHANNEL`,`MSISDN`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1";

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

$smsTable = "CREATE TABLE `trivia_sms_logs_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MO_ID` bigint(20) NULL DEFAULT 0,
  `TXN_ID` bigint(20) NULL DEFAULT 0,
  `MSISDN` bigint(20) NOT NULL,
  `TEXT` varchar(500) NOT NULL,
  `REQUEST_SENT` text NOT NULL,
  `RESPONSE_RECEIVED` text NULL,
  `RESPONSE_TIME` datetime DEFAULT NULL, 
  `SMS_TYPE` varchar(50) NOT NULL,
  `STATUS_CODE` varchar(100)  NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1";


$moTable = "CREATE TABLE `trivia_mo_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) NOT NULL,
  `TEXT` text NOT NULL,
  `RETRY_STATUS` enum('0','1') NOT NULL DEFAULT '0',
  `RETRY_COUNT` int(11) NOT NULL DEFAULT '0',
  `NEXT_RETRIAL_PERIOD` datetime DEFAULT NULL,
  `DATE` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1
";

/*$renewalsTable = "CREATE TABLE `trivia_renewals_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) NOT NULL,
  `REQUEST` text NOT NULL,
  `AMOUNT` int(11) NOT NULL,
  `TRANSACTION_UUID` varchar(50) NOT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1";
*/

$renewalNotificationTable = "CREATE TABLE `tbl_payment_renewal_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `PAYMENT_STATUS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `CHANNEL` enum('SMS','WEB','ANDROID','RENEWAL') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'SMS',
  `PAYMENT_DETAILS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `BILLED_AMOUNT` int(11) DEFAULT 0, 
  `REQUEST_ID` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `IS_OUTGOING` int(11) NOT NULL DEFAULT '1',
  `PROMO_STATUS `varchar(10) DEFAULT 'OFF',
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`),
  KEY `REQUEST_ID` (`REQUEST_ID`),
 KEY `MSISDN` (`MSISDN`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
 
$interactionPaymentNotificationTable = "CREATE TABLE `tbl_payment_interaction_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `PAYMENT_STATUS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `CHANNEL` enum('SMS','WEB','ANDROID','RENEWAL') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'SMS',
  `PAYMENT_DETAILS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `BILLING_SYSTEM` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `REQUEST_ID` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `IS_OUTGOING` int(11) NOT NULL DEFAULT '1',
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`),
  KEY `REQUEST_ID` (`REQUEST_ID`),
  KEY `BILLING_SYSTEM` (`BILLING_SYSTEM`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$webpaymentNotificationTable = "CREATE TABLE `tbl_payment_web_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `PAYMENT_STATUS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `CHANNEL` enum('SMS','WEB','ANDROID','RENEWAL') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'SMS',
  `PAYMENT_DETAILS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `REQUEST_ID` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `IS_OUTGOING` int(11) NOT NULL DEFAULT '1',
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`),
  KEY `REQUEST_ID` (`REQUEST_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=80899 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

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
mysqli_query($conn,$interactionPaymentNotificationTable);
mysqli_query($conn,$renewalNotificationTable);
mysqli_query($conn,$webpaymentNotificationTable);
mysqli_query($conn,$moTable);
mysqli_query($conn,$txntable);
mysqli_query($conn,$smsTable);
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


