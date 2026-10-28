<?php

require_once 'DbConfig.php';
date_default_timezone_set('Africa/Dar_ES_Salaam');
$conn  = connect_db();
if(!$conn){
    
    exit;
}
else{
$i = "1";	
    $txnTable  = "vas_transaction_trivia_".date("Ymd",strtotime("-".$i." Days"));
    $sql  = "update tbl_qn_answers ans inner join `{$txnTable}` txn on ans.TXN_ID = txn.txn_id set BILLING_STATUS = 'SUCCESS' where  txn.txn_status = 'SUCCESS' and ans.billing_status <>'SUCCESS'";
    echo $sql;
    $conn->query($sql);
    echo "Rows Affected : ".$conn->affected_rows;

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




