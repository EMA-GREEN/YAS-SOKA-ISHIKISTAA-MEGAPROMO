#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=20

while [ : ]
do

cnt=`ps -eaflc --sort stime | grep   TigoMegaPromoMoconsumers.php |grep -v grep | wc -l`
echo $cnt
if(test $cnt -lt 20) ;
then
    echo "Mo Running With less consumers $cnt..."
    val=`expr $pc - $cnt`
    
for((i=0;i<"$val";i++))
do nohup php TigoMegaPromoMoconsumers.php &
done
else
    echo "All $cnt Running"
fi
sleep 5
done




