#!/bin/bash


subject="Tigo MegaPromo Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=30
mc=10
opnqncc=5
mpushyascc=5
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

mpushyasconscnt=`ps -eaflc --sort stime | grep IshikistaaMpushYasSubsInitNotificationConsumer.php |grep -v grep | wc -l`
if(test $mpushyasconscnt -lt 5) ;
then
    echo "Running With less Ishikistaa Mpush Yas Subs Init consumers $mpushyasconscnt..."
    val=`expr $mpushyascc - $mpushyasconscnt`

for((i=0;i<"$val";i++))
do nohup php IshikistaaMpushYasSubsInitNotificationConsumer.php &
done
else
    echo "All $mpushyascc Ishikistaa Mpush Yas Subs Init Consumers Running"
fi

sleep 5
done


