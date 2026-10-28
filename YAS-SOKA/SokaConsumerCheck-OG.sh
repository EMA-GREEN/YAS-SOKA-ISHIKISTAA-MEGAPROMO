#!/bin/bash


subject="Tigo Soka Application Alert"
from="Server Alert <support@greentelecom.co.tz>"

## sending mail to



to="Arun <arunpariyanampatta@gmail.com>"
pc=50
mc=15
rc=200
rcontent=10
openqn=10
webcon=5
mt=5
while [ : ]
do

pycnt=`ps -eaflc --sort stime | grep   SokaPaymentConsumers.php |grep -v grep | wc -l`
if(test $pycnt -lt 50) ;
then
    echo "Running With less Soka Payment consumers $cnt..."
    val=`expr $pc - $pycnt`

for((i=0;i<"$val";i++))
do nohup php SokaPaymentConsumers.php &
done
else
    echo "All $pycnt Paymant Consumers  Running"
fi


mtcnt=`ps -eaflc --sort stime | grep   SokaSmsReprocessConsumer.php |grep -v grep | wc -l`
if(test $mtcnt -lt 5) ;
then
    echo "Running With less Soka Payment consumers $cnt..."
    val=`expr $mt - $mtcnt`

for((i=0;i<"$val";i++))
do nohup php SokaSmsReprocessConsumer.php &
done
else
    echo "All $mtcnt SokaSmsReprocessConsumers  Running"
fi

mocnt=`ps -eaflc --sort stime | grep   SokaMoConsumer.php |grep -v grep | wc -l`
if(test $mocnt -lt 15) ;
then
    echo "Running With less Soka Mo consumers $cnt..."
    val=`expr $mc - $mocnt`

for((i=0;i<"$val";i++))
do nohup php SokaMoConsumer.php &
done
else
    echo "All $mocnt Mo Consumers Running"
fi


rencnt=`ps -eaflc --sort stime | grep   SokaNewRenewalConsumer.php |grep -v grep | wc -l`
if(test $rencnt -lt 200) ;
then
    echo "Running With less Soka Renewal consumers $cnt..."
    val=`expr $rc - $rencnt`

for((i=0;i<"$val";i++))
do nohup php SokaNewRenewalConsumer.php &
done
else
    echo "All $rencnt Soka Renewal Consumers Running"
fi


rencontentcnt=`ps -eaflc --sort stime | grep   SokaRenewalContentConsumer.php |grep -v grep | wc -l`
if(test $rencontentcnt -lt 10) ;
then
    echo "Running With less Soka Renewal content consumers $cnt..."
    val=`expr $rcontent - $rencontentcnt`

for((i=0;i<"$val";i++))
do nohup php SokaRenewalContentConsumer.php &
done
else
    echo "All $rencontentcnt Soka Renewal Content Consumers Running"
fi



openqncnt=`ps -eaflc --sort stime | grep   SokaOpenQuestionConsumer.php |grep -v grep | wc -l`
if(test $openqncnt -lt 10) ;
then
    echo "Running With less  SokaOpenQuestion consumers $openqncnt..."
    val=`expr $openqn - $openqncnt`

for((i=0;i<"$val";i++))
do nohup php SokaOpenQuestionConsumer.php &
done
else
    echo "All $openqncnt Soka Open Question Consumers Running"
fi


webpaycnt=`ps -eaflc --sort stime | grep   SokaWebPaymentConsumers.php |grep -v grep | wc -l`
if(test $webpaycnt -lt 5) ;
then
    echo "Running With less  Web Payment Consumers $webpaycnt..."
    val=`expr $webcon - $webpaycnt`

for((i=0;i<"$val";i++))
do nohup php SokaWebPaymentConsumers.php &
done
else
    echo "All $webpaycnt Soka Web Payment Consumers Running"
fi


sleep 5
done


