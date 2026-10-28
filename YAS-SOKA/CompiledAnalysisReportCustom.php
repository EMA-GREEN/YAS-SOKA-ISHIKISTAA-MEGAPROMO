<?php
date_default_timezone_set('Africa/Dar_ES_Salaam');

require_once '../PHPMailer/MailerScript.php';



$days = "-3";
$halotelIshikistaaConn = connect("localhost", "vas");
$date = date("Y-m-d", strtotime($days." Days"));





//halotelsoka renewal

$tbldate  = date("Ymd", strtotime($days." Days"));
$halotelSokaConn          = connect("102.218.75.3", "trivia_halotel_vas");


$halotelSokamonfeeRenewal       = "SELECT sum(AMOUNT) AS 'SOKA_MONFEERENEWAL' FROM vas_transaction_trivia_renewal_monfee_".$tbldate." WHERE txn_status = 'SUCCESS'";
$halotelSokamonfeeRenewalArray  = $halotelSokaConn->query($halotelSokamonfeeRenewal);
$halotelSokamonfeeRenewalResult = $halotelSokamonfeeRenewalArray->fetch_assoc();
$monfeerenewalAmount            = $halotelSokamonfeeRenewalResult['SOKA_MONFEERENEWAL'];



$halotelIshikistaaConn = connect("102.218.75.3", "halotel_vas");
$date = date("Y-m-d", strtotime($days." Days"));
$tbldate  = date("Ymd", strtotime($days." Days"));

$halotelIshikistaaSubScriberQuery = "SELECT count(*) AS 'SUB_TOTAL'  from halotel_subscribers where date(created_at) = '{$date}' ";
$halotelSubscriberArray = $halotelIshikistaaConn->query($halotelIshikistaaSubScriberQuery);
$halotelSubscriberResult = $halotelSubscriberArray->fetch_assoc();


$halotelIshikistaaUnSubScriberQuery = "SELECT count(*) AS 'UNSUB_TOTAL'  from halotel_unsubscription where date(created_at) = '{$date}' ";
$halotelUnSubscriberArray = $halotelIshikistaaConn->query($halotelIshikistaaUnSubScriberQuery);
$halotelUnSubscriberResult = $halotelUnSubscriberArray->fetch_assoc();





$halotelishitriviaConn          = connect("102.218.75.3", "ishikistaa_halotel_vas");


$halotelIshikistaamonfeeRenewal       = "SELECT sum(AMOUNT) AS 'ISHIKISTAA_MONFEERENEWAL' FROM vas_transaction_trivia_renewal_monfee_".$tbldate." WHERE txn_status = 'SUCCESS'";
$halotelIshikistaamonfeeRenewalArray  = $halotelishitriviaConn->query($halotelIshikistaamonfeeRenewal);
$halotelIshikistaamonfeeRenewalResult = $halotelIshikistaamonfeeRenewalArray->fetch_assoc();
$IshimonfeerenewalAmount              = $halotelIshikistaamonfeeRenewalResult['ISHIKISTAA_MONFEERENEWAL'];



//vodacom trivia

$vodacomishitriviaConn          = connect("102.218.75.3", "trivia_vodacom_vas");
$vodacomishitriviaRenewal       = "SELECT count(*) AS 'ISHIKISTAA_RENEWAL' FROM vas_transaction_trivia_renewal_".$tbldate." WHERE TXN_STATUS = 'SUCCESS'";

$vodacomishitriviaRenewalArray  = $vodacomishitriviaConn->query($vodacomishitriviaRenewal);
$vodacomishitriviaRenewalResult = $vodacomishitriviaRenewalArray->fetch_assoc();
$VodacomIshikistaaTriviarenewalAmount  = $vodacomishitriviaRenewalResult['ISHIKISTAA_RENEWAL'] * 99;





//zantel ishikistaa

$ishiRevenueConn  = connect("localhost", "vas");





//tigo Ishikistaa




/*$tigosokaConn = connect("localhost", "trivia_tigotz_vas");
$tigosokaRenewRevenueQuery = "SELECT SUM(BILLED_AMOUNT)  AS 'REVENUE' FROM  tbl_payment_renewal_notifications_$tbldate  where PAYMENT_STATUS = 'success' ";
$tigosokaRenewRevenueArray = $tigosokaConn->query($tigosokaRenewRevenueQuery);
$tigosokaRenewRevenueResult = $tigosokaRenewRevenueArray->fetch_assoc();*/




$tigoboliConn = connect("localhost", "boli_tigo_vas");
$prevdate = date("Y-m-d",strtotime($days." Days"));
$tigoboliRenewRevenueQuery = "SELECT sum(billed_amount)as 'REVENUE' from  tbl_payment_renewal_notifications  where PAYMENT_STATUS = 'success' and date(created_at) = '{$prevdate}'";
$tigoboliRenewRevenueArray = $tigoboliConn->query($tigoboliRenewRevenueQuery);
$tigoboliRenewRevenueResult = $tigoboliRenewRevenueArray->fetch_assoc();

$tigoboliSubscriberCount   = "SELECT count(MSISDN) as 'ACTIVE' from tbl_boli_subscribers where activity_status = 'ACTIVE'";
$tigoboliSubscriberCountArray = $tigoboliConn->query($tigoboliSubscriberCount);
$tigoboliSubscriberCountResult = $tigoboliSubscriberCountArray->fetch_assoc();




$tigoIshikistaaConn = connect("localhost", "ishikistaa_tigo_vas");




/*$tigoishikistaaRenewRevenueQuery = "SELECT SUM(BILLED_AMOUNT) AS 'REVENUE' FROM  tbl_payment_renewal_notifications_$tbldate  where PAYMENT_STATUS = 'success'";
$tigoishikistaaRenewRevenueArray =  $tigoIshikistaaConn->query($tigoishikistaaRenewRevenueQuery);
$tigoishikistaaRenewRevenueResult = $tigoishikistaaRenewRevenueArray->fetch_assoc();*/


$message .= "<b><u>HALOTEL</u></b>";
$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>ISHIKISTAA</td><td>" . $halotelSubscriberResult['SUB_TOTAL'] . "</td><td>" . $halotelUnSubscriberResult['UNSUB_TOTAL'] . "</td><td>0</td></tr>";
$message .= "<tr><td>SOKA-MONFEE-RENEWAL</td><td></td><td></td><td>" .$monfeerenewalAmount . "</td></tr>";
$message .= "<tr><td>HALOTEL-ISHIKISTAA-TRIVIA-MONFEE-RENEWAL</td><td></td><td></td><td>" .$IshimonfeerenewalAmount. "</td></tr>";
$message .= "</table>";
$message .= "<br/> <br/>";


$message .= "<b><u>VODACOM-TRIVIA-RENEWAL</u></b>";
$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>VODACOM-ISHIKISTAA-TRIVIA-RENEWAL</td><td></td><td></td><td>" . $VodacomIshikistaaTriviarenewalAmount . "</td></tr>";
$message .= "</table>";
$message .= "<br/> <br/>";


//$message .= "<b><u>VODACOM-CONTENT</u></b>";
//$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
//$message .= "<tr><td>ISHIKISTAA</td><td>" . $vodacomArray['ishikistaa_subscriber'] . "</td><td>" . $vodacomArray['ishikistaa_unsub'] . "</td><td>" . $vodacomArray['ishikistaa_revenue'] . "</td></tr>";

$message .= "</table>";
$message .= "<br/> <br/>";



/*$message .= "<b><u>TIGO</u></b>";
$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>ISHIKISTAA-RENEWALS</td><td></td><td></td><td>" . $tigoishikistaaRenewRevenueResult['REVENUE'] . "</td></tr>";
$message .= "<tr><td>SOKA-RENEWALS</td><td></td><td></td><td>" . $tigosokaRenewRevenueResult['REVENUE'] . "</td></tr>";
$message .= "<tr><td>BOLI-RENEWALS</td><td>{$tigoboliSubscriberCountResult['ACTIVE']}</td><td></td><td>" . $tigoboliRenewRevenueResult ['REVENUE'] . "</td></tr></table>";


$message .= "<br/> <br/>";*/



//zantel

/*$message .= "<b><u>ZANTEL</u></b>";
$message .= '<table border="1" cellspacing="0" cellpadding="3" style="width:620px;background-color:White;border-color:green;border-width:1px;border-style:None;border-collapse:collapse;font:100% Verdana,Tahoma,Arial,Helvetica;font-size:8.5pt"><tbody><tr style="color:White;background-color:green;font-weight:bold"><th>Service</th><th>Subscriber Gained</th><th>Unsubscriptions</th><th>Revenue Generated</th></tr>';
$message .= "<tr><td>ISHIKISTAA</td><td>" . $zantelSubscriberResult['SUB_TOTAL'] . "</td><td>" . $zantelUnSubscriberResult['UNSUB_TOTAL'] . "</td><td>" . $zantelIshikistaaRevenueResult['REVENUE'] . "</td></tr>";
$message .= "<tr><td>BOLI</td><td>" . $zantelboliSubscriberResult['SUB_TOTAL'] . "</td><td>" . $zantelboliUnSubscriberResult['UNSUB_TOTAL'] . "</td><td>" . $zantelboliRevenueResult['REVENUE'] . "</td></tr>";
*/




$body = "</pre>
<div>";
$body .= $message;
$body .= "</div>" ;

// And the absolute required configurations for sending HTML with attachement





$subject = "Compiled Service Analysis Report - ". date("Y-m-d", strtotime($days." Days"));

processMail($body, $subject);





$halotelIshikistaaConn->close();

function Connect($host, $database) {

    $conn = new Mysqli($host, "analysis_email", "xc28FhrWV7wtXVeg@@@", $database);
    if ($conn) {

        return $conn;
    } else {

        return FALSE;
    }
}

function  processMail($message,$subject){
$to = [];
$cc= [];
$to[0]['Mail']          = "emanuel@greentelecom.co.tz";
$to[0]['Name']          =  "Emanuel Julius";
$to[1]['Mail']          = 'reports@greentelecom.co.tz';
$to[1]['Name']          = 'Reports';
$cc[0]['Mail']         = 'richard@greentelecom.co.tz';
$cc[0]['Name']         = 'Richard Matemba Joseph';
$cc[1]['Mail']         = 'hemedi@greentelecom.co.tz';
$cc[1]['Name']         = 'Hemedi Ali Hemedi';
$cc[2]['Mail']         =  'emanuel@greentelecom.co.tz';
$cc[2]['Name']         = 'Emanuel Julius Mpinga';


sendEmail($message, $subject,$to,$cc);
}