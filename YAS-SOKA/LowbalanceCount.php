<?php

$i=1;


$sql = ""; 
for($i=101;$i>0;$i--){

$tblDate = date("Ymd",strtotime("-".$i." days"));
$sql .= "select count(distinct msisdn) as 'Unique Low balance',count(*) as 'total low balance',date(CREATED_AT) as 'Date' from tbl_payment_interaction_notifications_".$tblDate." where payment_details like '%low%' Union all ";

}

$sql .= ";";


$sql = str_replace("Union all ;","",$sql);

$sql .= " into outfile '/var/lib/mysql-files/sokalowbalancecount.txt' FIELDS TERMINATED BY '|'  LINES TERMINATED BY '\n'";


echo  $sql;

$conn = connect_db();

$conn->query($sql);


function connect_db(){
$conn = new Mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
if($conn){

return  $conn;
}
else{
return false;
}
}


