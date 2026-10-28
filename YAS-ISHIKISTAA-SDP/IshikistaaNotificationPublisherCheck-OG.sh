#!/bin/bash


subject="Tigo Ishikistaa Application Alert"
from="Server Alert <support@greentelecom.co.tz>"
## sending mail to



to="ARUN<arunpariyanampatta@gmail.com>"

while [ : ]
do


mopubcnt=`ps -eaflc --sort stime | grep   IshikistaaMoNotificationPublisher.php |grep -v grep | wc -l`

if(test $mopubcnt -lt 1) ;
then
    echo "TigIshikistaa Mo Publisher id Down ..."
    nohup php IshikistaaMoNotificationPublisher.php &
    echo "Publisher Started...."


else
    echo "TigoIshikistaa Mo Publisher is Running ....."
fi


paymentpubcnt=`ps -eaflc --sort stime | grep IshikistaaPaymentNotificationPublisher.php |grep -v grep | wc -l`

if(test $paymentpubcnt -lt 1) ;
then
    echo "Ishikistaa PaymentCallback Publisher is Down ..."
    nohup php IshikistaaPaymentNotificationPublisher.php  &
    echo "PaymentCallback Publisher Started...."

else
    echo "PaymentCallback Running"
fi


renpaymentpubcnt=`ps -eaflc --sort stime | grep IshikistaaNewRenewalPublisher.php |grep -v grep | wc -l`

if(test $renpaymentpubcnt -lt 1) ;
then
    echo "Ishikistaa Renewal PaymentCallback Publisher is Down ..."
    nohup php IshikistaaNewRenewalPublisher.php  &
    echo "Ishikistaa Renewal PaymentCallback Publisher Started...."

else
    echo "Renewal PaymentCallback Running"
fi


rencontpub=`ps -eaflc --sort stime | grep   IshikistaaRenewalContentPublisher.php |grep -v grep | wc -l`  
if(test $rencontpub -lt 1);
then
    echo "Ishikistaa Renewal Content Publisher is down...."
    nohup php IshikistaaRenewalContentPublisher.php &
    echo "Ishikistaa Renewal Content Publisher Started...."    
else
    echo "Ishikistaa Renewal Content Publisher is running"
fi


openqnpub=`ps -eaflc --sort stime | grep   IshikistaaOpenQuestionPublisher.php |grep -v grep | wc -l`
if(test $openqnpub -lt 1);
then
    echo "Ishikistaa Open Question  Publisher is down...."
    nohup php IshikistaaOpenQuestionPublisher.php &
    echo "Ishikistaa Open Question Content Publisher Started...."
else
    echo "Ishikistaa OpenQuestion Publisher Running"
fi



openqnsch=`ps -eaflc --sort stime | grep   IshikistaaOpenQuestionScheduler.php |grep -v grep | wc -l`
if(test $openqnsch -lt 1);
then
    echo "Ishikistaa Open Question  Scheduler is down...."
    nohup php IshikistaaOpenQuestionScheduler.php &
    echo "Ishikistaa Open Question Scheduler  Started...."
else
    echo "Ishikistaa OpenQuestion Scheduler is Running"
fi

sleep 5
done

