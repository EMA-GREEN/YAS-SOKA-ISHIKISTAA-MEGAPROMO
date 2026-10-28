<?php
set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Africa/Dar_Es_Salaam');


function connect_db(){
    
    $mysqli = new mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
    if($mysqli){
        
        return $mysqli;
    }
    else{
        return false;
    }
}

$conn = connect_db();
if($conn){
$sql = "insert into sokatrivia_analysis(`ID`,`REQUEST_ID`,`AMOUNT`,`TXN_STATUS`,`RESP_TIME`,`TXN_DATE`) SELECT `ID`,`REQUEST_ID`,`AMOUNT`,`TXN_STATUS`,`RESP_TIME`,`TXN_DATE` from  vas_transaction_trivia where ID >= 67441497  and ID <=78804598";
$conn->query($sql);

}
else{

echo "Error";
}
