<?php

error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once 'config.php';
require_once 'IshikistaaLib.php';
require_once 'IshikistaaServices.php';

$msisdn = "255655807727";

$ishikistaaServicesObj = new ishikistaaServices("consumer");
$subDetails = $ishikistaaServicesObj->getSubscriberDetails($msisdn);

echo $subDetails['TOTAL_POINTS'];

$points = 100;

$subscriberArray = [];
$subscriberArray['TOTAL_POINTS'] = 3900;
$totalPoints = $subDetails['TOTAL_POINTS'] + $points;
$ishikistaaServicesObj->_update("tbl_qn_subscribers", "MSISDN", $msisdn, $subscriberArray);