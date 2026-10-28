<?php


$conn = connect_db();

if($conn){

    
    $data = [];

 
        $dates = array();
        $current = strtotime("2022-10-01");
        $last = strtotime("2022-11-30");
        $step = "+1 day";
    while ($current <= $last) {
        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }
    $sql = "";
    foreach($dates as $table){
        $txntable = "vas_transaction_trivia_".date("Ymd", strtotime($table));
        $sql .= "SELECT sum(amount)as 'AMOUNT',date(TXN_DATE) as 'TXN_DATE' from $txntable  UNION ALL ";

    }

    $sql .= "END";
    $sql = str_replace("UNION ALL END", "", $sql);
    $sumResult = $conn->query($sql);
    $sumData = [];
    $amount = 0;
    $dailyAmount = 0;
    while($row = $sumResult->fetch_assoc()){
        $sumData[] = $row;

    }
    foreach($sumData as $key=>$value){
        $amount += $sumData[$key]['AMOUNT'];
        $dailyAmount = $sumData[$key]['AMOUNT'];
        $date = $sumData[$key]['TXN_DATE'];
        echo "Revenue ".$dailyAmount."|".$date."\n";
    }
    
        
    echo "Total Amount "."|".$amount;

    
}


function connect_db(){

$conn = new mysqli("localhost","trivia_publisher","bJ++r5tL>xQdG:7H","trivia_tigotz_vas");
 if($conn){
     return $conn;
 }
    else{
        return false;
    }
}



