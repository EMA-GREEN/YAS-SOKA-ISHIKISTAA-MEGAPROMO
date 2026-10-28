<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');
$database = __connect();


$date = date("Ymd",strtotime("+1 days"));

$billingSuccessTable = "CREATE TABLE `tbl_promo_billing_$date` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BILLING_ID` bigint(20) DEFAULT NULL,
  `MSISDN` bigint(20) NOT NULL,
  `MO_ID` bigint(20) DEFAULT NULL,
  `ACK_RESPONSE` text,
  `RESPONSE` text,
  `HEADERS` text,
  `STATUS_CODE` varchar(50) NOT NULL,
  `AMOUNT` decimal(10,0) NOT NULL,
  `TXN_ID` varchar(100) DEFAULT NULL,
  `PAYMENT_METHOD` varchar(20) DEFAULT NULL,
  `TXN_TYPE` varchar(100) DEFAULT 'SMS',
  `DATE` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `REQUEST_TYPE` varchar(100) DEFAULT 'PLAY',
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `data_indx` (`DATE`,`TXN_ID`,`STATUS_CODE`,`MO_ID`),
  KEY `txnID` (`TXN_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1";

$database->query($billingSuccessTable);

$renewalTable = "CREATE TABLE `tbl_payment_renewal_notifications_$date` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `PAYMENT_STATUS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `CHANNEL` enum('SMS','WEB','ANDROID','RENEWAL') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'SMS',
  `PAYMENT_DETAILS` varchar(1000) COLLATE utf8_unicode_ci DEFAULT NULL,
  `REQUEST_ID` varchar(1000) COLLATE utf8_unicode_ci NOT NULL,
  `BILLED_AMOUNT` int(11) NOT NULL DEFAULT '0',
  `IS_OUTGOING` int(11) NOT NULL DEFAULT '1',
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `_dt_idx` (`CHANNEL`,`IS_OUTGOING`,`REQUEST_ID`,`MSISDN`,`CREATED_AT`),
  KEY `req` (`REQUEST_ID`),
  KEY `outgoing` (`IS_OUTGOING`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";


$database->query($renewalTable);

$mpushSubsInitNotificationsTable = "CREATE TABLE `tbl_mpush_subs_init_notifications_".$date."` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `MSISDN` bigint(20) DEFAULT NULL,
  `CHANNEL` enum('SMS','USSD','APP') NOT NULL DEFAULT 'USSD',
  `IS_OUTGOING` int(11) NOT NULL DEFAULT 1,
  `CREATED_AT` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `MSISDN` (`MSISDN`),
  KEY `CHANNEL` (`CHANNEL`),
  KEY `IS_OUTGOING` (`IS_OUTGOING`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$database->query($mpushSubsInitNotificationsTable);


function __connect() {
    $mysql = new \mysqli("localhost", "megapromo_publisher", "22MbfSJLue4vsgnkL#@", "tigo_megapromo");
    return $mysql;
}
