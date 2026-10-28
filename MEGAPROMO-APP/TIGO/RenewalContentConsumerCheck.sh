#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=5

while [ : ]
do

cnt=`ps -eaflc --sort stime | grep   TigoMegaPromoRenewalContentConsumers.php|grep -v grep | wc -l`
echo $cnt
if(test $cnt -lt 5) ;
then
    echo "RenewalContent Running With less consumers $cnt..."
    val=`expr $pc - $cnt`

for((i=0;i<"$val";i++))
do nohup php TigoMegaPromoRenewalContentConsumers.php &
done
else
    echo "All RenewalContentConsumer $cnt Running"
fi
sleep 5
done
