#!/bin/bash

# Log file
logfile="/var/log/tigo_soka_monitor.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a $logfile
}

while true; do
    check_and_restart() {
        local script_name=$1
        local process_name=$2

        if ! pgrep -f "$script_name" > /dev/null ; then
            log "$process_name is Down ..."
            nohup php "$script_name" &>/dev/null &
            log "$process_name Started...."
        else
            log "$process_name is Running"
        fi
    }

    check_and_restart "SokaMoNotificationPublisher.php" "Soka Mo Publisher"
    check_and_restart "SokaPaymentNotificationPublisher.php" "Soka PaymentCallback Publisher"
    check_and_restart "BoomCustomerCreditAwardPublisher.php" "Soka BoomCustomerCreditAward Publisher"
    check_and_restart "SokaSmsReprocessPublisher.php" "Soka SMS Reprocess Publisher"
    check_and_restart "SokaOpenQuestionScheduler.php" "Soka Open Question Scheduler"
    check_and_restart "SokaOpenQuestionPubliser.php" "Soka Open Question Publisher"
    check_and_restart "SokaWebPaymentNotificationPublisher.php" "Soka Web Payment Publisher"
    check_and_restart "SokaMpushYasSubsInitNotificationPublisher.php" "Soka Mpush YAS Subs Init Publisher"

    sleep 5
done
