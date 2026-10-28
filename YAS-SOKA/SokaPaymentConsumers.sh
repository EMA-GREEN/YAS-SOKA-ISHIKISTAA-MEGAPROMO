#!/bin/bash

val="$(grep -oE '\$worker = .*;' config.php | tail -1 | sed 's/$worker = //g;s/;//g')"

for ((i = 0;i < "$val";i++))
do
nohup php SokaPaymentConsumers.php &
done

echo All "$val"  Wokers  Started

