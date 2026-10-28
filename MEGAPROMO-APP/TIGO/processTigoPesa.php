<?php
require_once 'TigoMegaPromoDatabaseServices.php';

use MegaPromo\DatabaseServices AS DatabaseServices;
$dbfile = loadfile('db.config');
$db = new DatabaseServices\TigoMeaPromoDatabaseServices($dbfile['PUBLISHER_USER'], $dbfile['DB_PASSWORD'], $dbfile['DB_NAME'], $dbfile['DB_HOST']);

$amount = $_GET['AMOUNT'];
$msisdn = $_GET['MSISDN'];
$txnID = "GTL".$_GET['txnID'];
$logsID = $_GET['logsID'];
$ticket = $_GET['tickets'];


$logsArray = [];
$logsArray['MSISDN'] = $msisdn;
$logsArray['TXN_ID'] = $txnID;
$logsArray['SESSION_ID'] = $txnID;
$logsArray['LOGS_ID'] = $logsID;
$logsArray['STATUS'] = "SUBMITTING";
$logsArray['AMOUNT'] = $amount;
$logsArray['TICKET_COUNT'] = $ticket;
$logsArray['OPERATOR'] = "TIGOPESA";
$recordID = $db->__insert("tbl_promo_mobilemoney_push_transactions",$logsArray);




    function loadfile($file) {
        $result = file_get_contents($file);
        $data = parse_ini_string($result);
        return $data;
    }
