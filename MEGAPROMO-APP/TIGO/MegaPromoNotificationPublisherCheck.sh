#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"
## sending mail to



to="ARUN<arunpariyanampatta@gmail.com>"

while [ : ]
do


mopubcnt=`ps -eaflc --sort stime | grep   TigoMegaPromoMoPublisher.php |grep -v grep | wc -l`

if(test $mopubcnt -lt 1) ;
then
    echo "MegaPromoMoPublisher id Down ..."
    nohup php TigoMegaPromoMoPublisher.php &
    echo "Publisher Started...."


else
    echo "TestZali Mo Publisher is Running ....."
fi


paymentpubcnt=`ps -eaflc --sort stime | grep TigoMegaPromoPaymentNotificationPublisher.php |grep -v grep | wc -l`

if(test $paymentpubcnt -lt 1) ;
then
    echo "MegaPromoPaymentCallbackPublisher id Down ..."
    nohup php TigoMegaPromoPaymentNotificationPublisher.php &
    echo "PaymentCallback Publisher Started...."

else
    echo "PaymentCallback Running"
fi


tigpesapushpub=`ps -eaflc --sort stime | grep TigoMegaPromoTigPesaPushPublisher.php |grep -v grep | wc -l`

if(test $tigpesapushpub -lt 1) ;
then
    echo "TigoMegaPromoTigPesaPushPublisher  is Down ..."
    nohup php TigoMegaPromoTigPesaPushPublisher.php &
    echo "TigoMegaPromoTigPesaPushPublisher  Publisher Started...."

else
    echo "TigoMegaPromoTigPesaPushPublisher Running...."
fi




sleep 5
done

