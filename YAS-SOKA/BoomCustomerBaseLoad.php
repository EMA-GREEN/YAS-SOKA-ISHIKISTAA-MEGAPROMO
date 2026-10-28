<?php
error_reporting(E_ALL);
set_time_limit(0);
ini_set("memory_limit", "-1");
date_default_timezone_set("Africa/Dar_Es_Salaam");

require_once "SokaServices.php";

$sokaServicesObj = new SokaServices("consumer");

$sokaServicesObj->clearBoomCustomerBase();

$sokaServicesObj->loadBoomCustomerBase();

$sokaServicesObj->closeDB();