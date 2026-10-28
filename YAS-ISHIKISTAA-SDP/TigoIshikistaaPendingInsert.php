<?php

require 'DbConfig.php';

$conn = connect_db();

for($i=0;$i<=90;$i++){

$txnDate = date("Ymd",strtotime("- ".$i."days"));

$sql = "insert into vas_transaction_trivia_".$txnDate."(`MO_ID`,`TXN_ID`,`MSISDN`,`AMOUNT`,`TXN_STATUS`,`RESPONSE_RECEIVED`,`RESP_TIME`,`TXN_DATE`)(select txn.mo_id,txn.id,txn.msisdn,txn.amount,txn.txn_status,txn.payment_details,txn.txn_date,txn.txn_date   from tbl_payment_interaction_notifications_".$txnDate." notif inner join vas_transaction_trivia txn on notif.request_id = txn.request_id  where PAYMENT_STATUS = 'SUCCESS' and notif.PAYMENT_DETAILS not in(select RESPONSE_RECEIVED from vas_transaction_trivia_".$txnDate."))";

echo $sql. "\n \n";

$conn->query($sql);
echo $conn->affected_rows . "\n";


}





function connect_db(){


    $conn =  new mysqli(DB_HOST,DB_CONSUMER_USER,PASSWORD,TRIVIA_DB);

    if($conn){

        return $conn;
    }
    else{

        return false;
    }

}

