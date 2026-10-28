#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=10
rpc=100
tigopesapushcc=5
while [ : ]
do

cnt=`ps -eaflc --sort stime | grep   TigoMegaPromoPaymentCallbackConsumer.php |grep -v grep | wc -l`
if(test $cnt -lt 10) ;
then
    echo "Running With less consumers $cnt..."
    val=`expr $pc - $cnt`
    
for((i=0;i<"$val";i++))
do nohup php TigoMegaPromoPaymentCallbackConsumer.php &
done
else
    echo "All $cnt Running"
fi


renewcnt=`ps -eaflc --sort stime | grep   TigoMegapromoRenewalCallbackconsumers.php |grep -v grep | wc -l`

if(test $renewcnt -lt 100) ;
then
    echo "Running With less consumers $renewcnt..."
    val=`expr $rpc - $renewcnt`

for((i=0;i<"$val";i++))
do nohup php TigoMegapromoRenewalCallbackconsumers.php &
done
else
    echo "All $cnt Running"
fi



tigopesacnt=`ps -eaflc --sort stime | grep   TigoMegaPromoTigoPesaPushConsumer.php |grep -v grep | wc -l`

if(test $tigopesacnt -lt 5) ;
then
    echo "Running With less consumers $cnt..."
    val=`expr $tigopesapushcc - $tigopesacnt`
    
for((i=0;i<"$val";i++))
do nohup php TigoMegaPromoTigoPesaPushConsumer.php &
done
else
    echo "All $tigopesacnt Running"
fi

sleep 5
done



