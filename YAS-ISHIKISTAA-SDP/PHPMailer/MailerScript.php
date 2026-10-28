<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';



function sendEmail($message,$subject,$to=[],$cc=[]){
    $mail = new PHPMailer(true);
    $smtp = new \PHPMailer\PHPMailer\SMTP();
    
    
    try {
    //Server settings
    $mail->SMTPDebug = $smtp::DEBUG_SERVER;                      //Enable verbose debug output
    $mail->isSMTP();                                            //Send using SMTP
    $mail->Host       = 'smtp.gmail.com';                     //Set the SMTP server to send through
    $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
    $mail->Username   = 'arun@greentelecom.co.tz';                     //SMTP username
    $mail->Password   = 'AlphaAlpha@143';                               //SMTP password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;            //Enable implicit TLS encryption
    $mail->Port       = 587;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

    //Recipients
    $mail->setFrom('support@greentelecom.co.tz', 'Support');
    //Name is optional
    if(!empty($to)){
    for($i=0;$i<sizeof($to);$i++){  
        $mail->addAddress("{$to[$i]['Mail']}","{$to[$i]['Name']}");
    }
    }
    if(!empty($cc)){
        for($i=0;$i<sizeof($cc);$i++){  
        $mail->addCC("{$cc[$i]['Mail']}","{$cc[$i]['Name']}");
        }
    }   
    //Content
    $mail->isHTML(true);                                  //Set email format to HTML
    $mail->Subject = $subject;
    $mail->Body    = $message;
    

    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
    
}