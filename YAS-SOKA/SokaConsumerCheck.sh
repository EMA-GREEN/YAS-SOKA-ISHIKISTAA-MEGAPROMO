#!/bin/bash

# Email alert setup
subject="Tigo Soka Application Alert"
from="Server Alert <support@greentelecom.co.tz>"
to="Arun <arunpariyanampatta@gmail.com>"

# Thresholds for various consumers
pc=50
mc=15
bmcrc=10
openqn=10
webcon=5
mt=5
mpush=5

# Function to manage consumers
manage_consumers() {
    local service=$1
    local script_name=$2
    local required_count=$3

    local running_count=$(ps -eaflc --sort stime | grep "$script_name" | grep -v grep | wc -l)

    if [ "$running_count" -lt "$required_count" ]; then
        echo "Running with less $service consumers ($running_count)..."
        local val=$((required_count - running_count))
        for ((i=0; i<val; i++)); do
            nohup php "$script_name" &
        done
    else
        echo "All $running_count $service consumers running"
    fi
}

# Infinite loop to keep the script running and checking
while :; do
    manage_consumers "Soka Payment" "SokaPaymentConsumers.php" $pc
    manage_consumers "Soka Sms Reprocess" "SokaSmsReprocessConsumer.php" $mt
    manage_consumers "Soka Mo" "SokaMoConsumer.php" $mc
    manage_consumers "Boom Customer Credit Award" "BoomCustomerCreditAwardConsumer.php" $bmcrc
    manage_consumers "Soka Open Question" "SokaOpenQuestionConsumer.php" $openqn
    manage_consumers "Soka Web Payment" "SokaWebPaymentConsumers.php" $webcon
    manage_consumers "Soka Mpush YAS Subs Init" "SokaMpushYasSubsInitNotificationConsumer.php" $mpush

    sleep 5
done
