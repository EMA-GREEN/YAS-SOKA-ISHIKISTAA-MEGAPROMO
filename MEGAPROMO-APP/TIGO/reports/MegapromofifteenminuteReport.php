<?php
date_default_timezone_set('Africa/Dar_ES_Salaam');

require_once 'MailerScript.php';
$conn = __connect();

$fifteenMinutes = date("Y-m-d H:i:s",strtotime("-15 minutes"));

if($conn){
    $AirtimerevenueQuery = "SELECT count(*) AS 'TOTAL',AMOUNT,SUM(AMOUNT) AS 'REVENUE' FROM tbl_promo_billing_".date("Ymd")." GROUP BY AMOUNT"; //fetch revenue
    
    $mobilemoneyRevenueQuery = "SELECT COUNT(*) AS 'TOTAL',AMOUNT,SUM(AMOUNT) AS 'REVENUE' FROM tbl_promo_mobilemoney_push_transactions push INNER JOIN tbl_promo_tigopesapush_callbacknotif notif ON notif.REF_ID = push.TXN_ID WHERE notif.STATUS = 'SUCCESS' AND DATE(push.DATE) = CURDATE() GROUP BY AMOUNT";
    
    $moQuery = "SELECT count(*) AS TOTAL_MO,sum(case when created_at >  '{$fifteenMinutes}' then 1 else 0  end) as  'FIF_MIN' from tbl_tigo_promo_mo_logs where date(created_at)  = CURDATE()";
    

    $airtimeRevenue = $conn->query($AirtimerevenueQuery);
    $mobileMobinemoneyRevenue = $conn->query($mobilemoneyRevenueQuery);
    $moCount = $conn->query($moQuery);

}

$airtimeRevenueArray = [];
$mobileMobinemoneyRevenueArray = [] ;
while($row=$airtimeRevenue->fetch_array()){
    $airtimeRevenueArray[] = $row;
}


while($row=$mobileMobinemoneyRevenue->fetch_array()){
    $mobileMobinemoneyRevenueArray[] = $row;
}

$moArray = $moCount->fetch_assoc();



$message = '<u><b>Airtime Transaction </b></u> <br/><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Amount</th><th>Total Charged</th><th>Revenue</th></tr>';
$totalAirtimecount = 0;
$totalAirtimeRevenue = 0;
foreach($airtimeRevenueArray as $index=>$value){
     $totalAirtimecount +=$airtimeRevenueArray[$index]['TOTAL'];
     $totalAirtimeRevenue +=$airtimeRevenueArray[$index]['REVENUE'];
     $message .="<tr><td>".$airtimeRevenueArray[$index]['AMOUNT']."</td><td>".$airtimeRevenueArray[$index]['TOTAL']."</td><td>".$airtimeRevenueArray[$index]['REVENUE']."</td>";   
    
}
$message .="</tr></table> <br/>";

$mobileMobinemoneycount = 0;
$mobileMobinemoneyRevenue = 0;
$message .= '<u><b>Mobilemoney Transaction </b></u> <br/><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Amount</th><th>Total Charged</th><th>Revenue</th></tr>';
foreach($mobileMobinemoneyRevenueArray as $index=>$value){
    $message .="<tr><td>".$mobileMobinemoneyRevenueArray[$index]['AMOUNT']."</td><td>".$mobileMobinemoneyRevenueArray[$index]['TOTAL']."</td><td>".$mobileMobinemoneyRevenueArray[$index]['REVENUE']."</td>";   
    
}

$message .="</tr></table> <br/>";


$message .= '<u><b>Mo Report</b></u> <br/><table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Total MO</th><th>Mo Past 15Minute</th></tr>';

$message .="<tr><td>".$moArray['TOTAL_MO']."</td><td>".$moArray['FIF_MIN']."</td>"."</tr></table>";

processMail($message);

function processMail($message){
    
    $subject = "Megapromo Report -".date("Y-m-d");
    $to[0]['Mail']          = "emanuel@greentelecom.co.tz";
    $to[0]['Name']  	=  "Emanuel Julius";
    $to[1]['Mail']          = 'reports@greentelecom.co.tz';
    $to[1]['Name']  	= 'Reports';
    $cc[0]['Mail']         = 'richard@greentelecom.co.tz';
    $cc[0]['Name']         = 'Richard Matemba Joseph';
    $cc[1]['Mail']         = 'hemedi@greentelecom.co.tz';
    $cc[1]['Name']         = 'Hemedi Ali Hemedi';
    $cc[2]['Mail']         ='emanuel@greentelecom.co.tz';
    $cc[2]['Name']         = 'Emanuel Julius';

    sendEmail($message, $subject,$to,$cc);
    
}
    function __connect() {
        $mysql = new \mysqli("localhost", "megapromo_publisher", "22MbfSJLue4vsgnkL#@", "tigo_megapromo");
        return $mysql;
    }

