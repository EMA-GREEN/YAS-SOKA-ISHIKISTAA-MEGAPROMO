#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=30
mc=10
rc=30
rcc=5
opnqncc=5
while [ : ]
do

pycnt=`ps -eaflc --sort stime | grep   IshikistaaPaymentConsumers.php |grep -v grep | wc -l`
if(test $pycnt -lt 30) ;
then
    echo "Running With less Ishikistaa Payment consumers $cnt..."
    val=`expr $pc - $pycnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaPaymentConsumers.php &
done
else
    echo "All $pycnt Paymant Consumers  Running"
fi


mocnt=`ps -eaflc --sort stime | grep   IshikistaaMoConsumer.php |grep -v grep | wc -l`
if(test $mocnt -lt 10) ;
then
    echo "Running With less Ishikistaa Mo consumers $cnt..."
    val=`expr $mc - $mocnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaMoConsumer.php &
done
else
    echo "All $mocnt Mo Consumers Running"
fi


rencnt=`ps -eaflc --sort stime | grep   IshikistaaNewRenewalConsumers.php |grep -v grep | wc -l`
if(test $rencnt -lt 30) ;
then
    echo "Running With less Ishikistaa Renewal consumers $rencnt..."
    val=`expr $rc - $rencnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaNewRenewalConsumers.php &
done
else
    echo "All $rencnt Ishikistaa Renewal Consumers Running"
fi



rencontcnt=`ps -eaflc --sort stime | grep   IshikistaaRenewalContentConsumer.php |grep -v grep | wc -l`
if(test $rencontcnt -lt 5) ;
then
    echo "Running With less Ishikistaa Renewal Content consumers $rencnt..."
    val=`expr $rcc - $rencontcnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaRenewalContentConsumer.php &
done
else
    echo "All $rencontcnt Ishikistaa Renewal Content Consumers Running"
fi




openqnconscnt=`ps -eaflc --sort stime | grep   IshikistaaOpenQuestionConsumer.php |grep -v grep | wc -l`
if(test $openqnconscnt -lt 5) ;
then
    echo "Running With less Ishikistaa Open Question consumers $openqnconscnt..."
    val=`expr $opnqncc - $openqnconscnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaOpenQuestionConsumer.php &
done
else
    echo "All $opnqncc Ishikistaa Open Question  Consumers Running"
fi


sleep 5
done


