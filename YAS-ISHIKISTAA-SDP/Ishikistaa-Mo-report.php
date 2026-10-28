<?php
$fromdate = "2022-09-01";
$todate  = date("Y-m-d",strtotime("-1 Days"));

$step = "+1 day";
     $dates = array();
    $current = strtotime($fromdate);
    $last = strtotime($todate);

    while( $current <= $last ) {
        $dates[] = date("Y-m-d", $current);
        $current = strtotime($step, $current);
    }

    $mosql = '';
    foreach($dates as $table){
        
        $table = date("Ymd",strtotime($table));
        $txndate = date("Y-m-d",strtotime($table)); 
        $mosql .="SELECT count(*) AS 'TOTAL','{$txndate}' AS 'MO_DATE' from trivia_mo_{$table}  UNION ALL ";
        
    }
    $mosql .= "END";
    $mosqls = str_replace("UNION ALL END",";",$mosql);
   
    
    $conn = connect_db();
    $result = $conn->query($mosqls);
    $moRow = [];
    while($row = $result->fetch_assoc()){
        
        $moRow [] = $row;
        
    }
    
     $to = "Koushik Govind Raju<koushik@greentelecom.co.tz>";
    
        $subject = "TIGO-ISHIKISTAA-MO REPORT-".date("Y-m-d",strtotime("-1 Days"));       
         $message = "Hi Team, ";
         $message .= "<br/>";
         $message .= "Please See the details below";
         $message .= "<br/>";
         $message .= '<b><u>MO</u></b><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:#999999;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:#000084;font-weight:bold"><th>Date</th><th>Count</th>';

for($i=0;$i<sizeof($moRow);$i++){
         $message .= '<tr style="background-color:#eeeeee"><td>'.$moRow[$i]['MO_DATE'].'</td><td>'.$moRow[$i]['TOTAL'];        
         $message .='</tr>';
}
$message .= '</tbody></table>';
$message .= "<br/> <br/>";

       
          $message .='</tbody></table>';
          $header  = "From:Green Telecom Reporter <support@greentelecom.co.tz> \r\n";
          //$header .= "Cc:Said <said@greentelecom.co.tz> \r\n";
          //$header .= "Cc:Rahul Sahni<rahul@greentelecom.co.tz> \r\n";
          $header .= "Cc:Koushik Govind Raju <koushik@greentelecom.co.tz> \r\n";
          //$header .= "Cc:Hemedi Ali Hemedi <hemedi@greentelecom.co.tz> \r\n";
         
          
          $header .= "MIME-Version: 1.0\r\n";
          $header .= "Content-type: text/html\r\n";
          $retval  = mail ($to,$subject,$message,$header);



          
          
          
function connect_db(){
    
    $conn =  new mysqli("localhost","trivia_consumer","bJ++r5tL>xQdG:7H","ishikistaa_tigo_vas");
    if($conn){
        return $conn;
    }
    else{
        
        return false;
    }
}
