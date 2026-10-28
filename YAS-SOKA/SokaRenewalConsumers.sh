#!/bin/bash

val="$(grep -oE '\$renewalworker = .*;' config.php | tail -1 | sed 's/$renewalworker = //g;s/;//g')"

for ((i = 0;i < "$val";i++))
do
nohup php SokaNewRenewalConsumer.php &
done

echo All "$val"  Wokers  Started

