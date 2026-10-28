<?php
date_default_timezone_set('Africa/Dar_Es_Salaam');

require_once 'DbConfig.php';
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


$conn = connect_db();
$tbl = date("Ymd",strtotime("-1 days"));
$date = date("Y-m-d H:i:s");
$truncsql = "truncate trivia_mo_retry_process";
$conn->query($truncsql);
$sql = "insert into trivia_mo_retry_process(`MSISDN`,`COUNT`,`RETRY_TIME`)(select distinct msisdn,3,'{$date}' from  trivia_mo_$tbl where msisdn not in(select msisdn from vas_transaction_trivia_$tbl)  and msisdn in(select msisdn from tbl_qn_subscribers where activity_status = 'ACTIVE' and dnd_status = 'OFF'))";

$conn->query($sql);


function connect_db() {

    $conn = new Mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
    if ($conn) {

        return $conn;
    } else {

        return FALSE;
    }
}

