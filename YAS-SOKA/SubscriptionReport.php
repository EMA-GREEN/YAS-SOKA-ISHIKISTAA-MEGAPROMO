<?php
date_default_timezone_set('Africa/Dar_ES_Salaam');


$conn = connect_db();

$date = date("Y-m-d",strtotime("-1 Days"));

$subsql = "SELECT COUNT(MSISDN) AS 'TOTAL',date(record_date) AS 'DATE' from tbl_qn_subscribers where date(record_date) >='2019-08-01' AND date(record_date) <='$date' group by date(record_date) "; 


$result = $conn->query($subsql);


$subRow = [];
while($row = $result->fetch_assoc())
{
    
    $subRow[] = $row;
    
}


$unsubsql = "SELECT COUNT(distinct MSISDN) AS 'TOTAL',date(created_at) AS 'DATE' from tbl_unsubs where date(created_at) >='2019-08-01' AND date(created_at) <='$date' group by date(created_at) "; 


$unsubresult = $conn->query($unsubsql);


$unsubRow = [];
while($unsubrow = $unsubresult->fetch_assoc())
{
    
    $unsubRow[] = $unsubrow;
    
}



        $to = "Ikunda Ngowi <ikunda.ngowi@tigo.co.tz>";
        $subject = "TIGO-SOKA-SUBSCRIPTION V/S UNSUBSCRIPTION - ".date("Y-m-d",strtotime("-1 Days"));       
         $message = "Hi Team, ";
         $message .= "<br/>";
         $message .= "Please See the details below";
         $message .= "<br/>";
         $message .= '<b><u>Subscription</u></b><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:#999999;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:#000084;font-weight:bold"><th>Date</th><th>Count</th>';

for($i=0;$i<sizeof($subRow);$i++){
         $message .= '<tr style="background-color:#eeeeee"><td>'.$subRow[$i]['DATE'].'</td><td>'.$subRow[$i]['TOTAL'];        
         $message .='</tr>';
}
$message .= '</tbody></table>';
$message .= "<br/> <br/>";

         $message .= '<b><u>UnSubscription</u></b><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:#999999;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:#000084;font-weight:bold"><th>Date</th><th>Count</th>';
         for($i=0;$i<sizeof($unsubRow);$i++){
         $message .= '<tr style="background-color:#eeeeee"><td>'.$unsubRow[$i]['DATE'].'</td><td>'.$unsubRow[$i]['TOTAL'];
         $message .='</tr>';
         }
          $message .='</tbody></table>';
          $header  = "From:Green Telecom Reporter <support@greentelecom.co.tz> \r\n";
          $header .= "Cc:Said <said@greentelecom.co.tz> \r\n";
          $header .= "Cc:Rahul Sahni<rahul@greentelecom.co.tz> \r\n";
          $header .= "Cc:Emanuel Julius <emanuel@greentelecom.co.tz> \r\n";
          $header .= "Cc:Hemedi Ali Hemedi <hemedi@greentelecom.co.tz> \r\n";
          $header .= "Cc:Richard <richard@greentelecom.co.tz> \r\n";         
          $header .= "MIME-Version: 1.0\r\n";
          $header .= "Content-type: text/html\r\n";
          $retval  = mail ($to,$subject,$message,$header);



          
          
          
function connect_db(){
    
    $conn =  new mysqli("localhost","revenue_script","95E8VCqWbEdA9fJf@","trivia_tigotz_vas");
    if($conn){
        return $conn;
    }
    else{
        
        return false;
    }
    
    
}          


