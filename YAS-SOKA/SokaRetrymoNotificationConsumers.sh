#!/bin/bash

val="$(grep -oE '\$moretryworker = .*;' config.php | tail -1 | sed 's/$moretryworker = //g;s/;//g')"

for ((i = 0;i < "$val";i++))
do
nohup php TigoSokaMoRetryPaymentNotificationConsumers.php &
done

echo All "$val"  Wokers  Started

