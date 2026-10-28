#!/bin/bash


subject="Tigo Soka Application Alert"
from="Server Alert <support@greentelecom.co.tz>"
## sending mail to



to="ARUN<arunpariyanampatta@gmail.com>"

while [ : ]
do


mopubcnt=`ps -eaflc --sort stime | grep   SokaMoNotificationPublisher.php |grep -v grep | wc -l`

if(test $mopubcnt -lt 1) ;
then
    echo "Soka Mo Publisher id Down ..."
    nohup php SokaMoNotificationPublisher.php &
    echo "Publisher Started...."


else
    echo "TigoSoka Mo Publisher is Running ....."
fi


paymentpubcnt=`ps -eaflc --sort stime | grep SokaPaymentNotificationPublisher.php |grep -v grep | wc -l`

if(test $paymentpubcnt -lt 1) ;
then
    echo "Soka PaymentCallback Publisher is Down ..."
    nohup php SokaPaymentNotificationPublisher.php  &
    echo "PaymentCallback Publisher Started...."

else
    echo "Soka PaymentCallback Publisher is Running"
fi


boomcreditpubcnt=`ps -eaflc --sort stime | grep BoomCustomerCreditAwardPublisher.php |grep -v grep | wc -l`

if(test $boomcreditpubcnt -lt 1) ;
then
    echo "Soka BoomCustomerCreditAward Publisher is Down ..."
    nohup php BoomCustomerCreditAwardPublisher.php  &
    echo "BoomCustomerCreditAward Publisher Started...."

else
    echo "Soka BoomCustomerCreditAward Publisher is Running"
fi


smsreppub=`ps -eaflc --sort stime | grep SokaSmsReprocessPublisher.php |grep -v grep | wc -l`

if(test $smsreppub -lt 1) ;
then
    echo "Soka SMS Reprocess Publisher is Down ..."
    nohup php SokaSmsReprocessPublisher.php  &
    echo "Soka SMS Reprocess  Publisher Started...."

else
    echo "Soka SMS Reprocess  ContentPublisher is Running"
fi


openqnschcnt=`ps -eaflc --sort stime | grep SokaOpenQuestionScheduler.php |grep -v grep | wc -l`

if(test $openqnschcnt -lt 1) ;
then
    echo "Soka Open Question Scheduler is Down ..."
    nohup php SokaOpenQuestionScheduler.php  &
    echo "Soka Open Question  Scheduler Started...."

else
    echo "Soka Open Question  Scheduler is Running"
fi



openqnpubcnt=`ps -eaflc --sort stime | grep SokaOpenQuestionPubliser.php |grep -v grep | wc -l`

if(test $openqnpubcnt -lt 1) ;
then
    echo "Soka Open Question Publisher is Down ..."
    nohup php SokaOpenQuestionPubliser.php  &
    echo "Soka Open Question  Publisher Started...."

else
    echo "Soka Open Question  ContentPublisher is Running"
fi

webpaypubcnt=`ps -eaflc --sort stime | grep SokaWebPaymentNotificationPublisher.php |grep -v grep | wc -l`

if(test $webpaypubcnt -lt 1) ;
then
    echo "Soka Web Payment Publisher is Down ..."
    nohup php SokaWebPaymentNotificationPublisher.php  &
    echo "Soka Web Payment  Publisher Started...."

else
    echo "Soka Web Payment Publisher is Running"
fi

sleep 5
done

