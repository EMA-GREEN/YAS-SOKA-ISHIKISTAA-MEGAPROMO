<?php

require_once 'config.php';

/**
 * Predict And Win MO + payment handling (FIFA World Cup 2026).
 * MO: PREDICT → M{slot} → M{H-A} (charged via sendCharging PREDICT / PD request IDs).
 */
class PredictAndWin
{
    private static $blockingStatuses = "'PENDING_PAYMENT','CONFIRMED','WON','LOST'";

    /** Legacy payment prefix (in-flight txns before PD migration). */
    private static $legacyRequestPrefix = 'PW';

    private static function isPredictRequestPrefix($requestID)
    {
        $requestID = (string) $requestID;
        if (stripos($requestID, PREDICT_REQUEST_PREFIX) === 0) {
            return true;
        }
        if (stripos($requestID, self::$legacyRequestPrefix) === 0) {
            return true;
        }
        return false;
    }

    /** Payment / renewal routing: PD request id or linked predict submission row. */
    public static function isPredictWinCharge(SokaServices $svc, $requestID, $msisdn, $txnID = 0)
    {
        if (self::isPredictRequestPrefix($requestID)) {
            return true;
        }
        return self::resolveSubmission($svc, $requestID, $msisdn, (int) $txnID) !== null;
    }

    /** Skip renewal promo while a predict score charge is in flight. */
    public static function hasPendingPredictPayment(SokaServices $svc, $msisdn)
    {
        $row = $svc->fetchRow(
            "SELECT ID FROM tbl_predict_submissions WHERE MSISDN = '{$msisdn}' AND STATUS = 'PENDING_PAYMENT' AND CREATED_AT >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY ID DESC LIMIT 1"
        );
        return !empty($row);
    }

    public static function handleMo($msisdn, $rawSms, $moID, SokaLib $sokaLib, SokaServices $svc, $subDetails, $paymentType)
    {
        $sms = strtoupper(trim(stripslashes($rawSms)));
        if (!self::isPredictTraffic($sms)) {
            return false;
        }

        if ($sms === 'PREDICT') {
            return self::handlePredictEntry($msisdn, $moID, $sokaLib, $svc, $subDetails);
        }

        $session = self::loadSession($svc, $msisdn);
        if (empty($session)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_SEND_PREDICT_FIRST, 'PREDICT_HINT');
            return true;
        }

        if (self::isSessionExpired($session)) {
            self::deleteSession($svc, $msisdn);
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_SESSION_EXPIRED, 'PREDICT_EXPIRED');
            return true;
        }

        $scoreGoals = self::parseScoreMo($sms);
        if ($scoreGoals !== null) {
            return self::handleScore(
                $msisdn,
                $sms,
                $scoreGoals[0],
                $scoreGoals[1],
                $moID,
                $sokaLib,
                $svc,
                $subDetails,
                $session,
                $paymentType
            );
        }

        if (preg_match('/^M\s*(\d+)$/', $sms, $m)) {
            return self::handleSlotSelect($msisdn, $sms, (int) $m[1], $moID, $sokaLib, $svc, $subDetails, $session);
        }

        if (preg_match('/^M\s*\d+\s+\d+$/', $sms)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_INVALID_SCORE, 'PREDICT_INVALID_SCORE');
            return true;
        }

        self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_WRONG_STATE, 'PREDICT_WRONG_STATE');
        return true;
    }

    public static function handlePayment($msisdn, $txnStatus, $requestID, $paymentDetails, $txndetails, SokaLib $sokaLib, SokaServices $svc)
    {
        $txnID = (int) $txndetails['ID'];
        $moID = (int) $txndetails['MO_ID'];
        $submission = self::resolveSubmission($svc, $requestID, $msisdn, $txnID);
        if (empty($submission)) {
            return true;
        }

        $paymentArray = [];
        $paymentArray['RESP_TIME'] = date('Y-m-d H:i:s');
        $paymentArray['PAYMENT_DETAILS'] = $paymentDetails;

        if ($txnStatus === 'SUCCESS') {
            $paymentArray['TXN_STATUS'] = 'SUCCESS';
            $paymentArray['RESPONSE_RECEIVED'] = 'SUCCESS : ' . $paymentDetails;
            $svc->updatePayment('vas_transaction_trivia', 'ID', $txnID, $paymentArray, $msisdn);

            $subUpdate = [];
            $subUpdate['STATUS'] = 'CONFIRMED';
            $subUpdate['CONFIRMED_AT'] = date('Y-m-d H:i:s');
            $subUpdate['TXN_ID'] = $txnID;
            $svc->_update('tbl_predict_submissions', 'ID', $submission['ID'], $subUpdate);

            self::upsertSession($svc, $msisdn, [
                'STATE' => 'SUBMITTED',
                'PENDING_SUBMISSION_ID' => null,
                'SELECTED_SLOT' => null,
                'SELECTED_FIXTURE_ID' => null,
                'LAST_MO_ID' => $moID,
            ]);

            self::recordTxnDaily($svc, $txndetails, $txnID, $msisdn, 'SUCCESS', $paymentDetails);
            self::sendSms($sokaLib, $svc, $msisdn, $moID, $txnID, PREDICT_RECEIVED, 'PREDICT_RECEIVED');
            return true;
        }

        $isLowBalance = strpos(strtolower((string) $paymentDetails), 'low balance') !== false
            || strpos(strtolower((string) $paymentDetails), 'insufficient balance') !== false;

        if ($isLowBalance) {
            $paymentArray['TXN_STATUS'] = 'LOW_BALANCE_FAILED';
            $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails;
            $svc->updatePayment('vas_transaction_trivia', 'ID', $txnID, $paymentArray, $msisdn);

            $svc->_update('tbl_predict_submissions', 'ID', $submission['ID'], ['STATUS' => 'LOW_BALANCE_FAILED']);
            self::restoreFixtureChosenSession($svc, $msisdn, $submission, $moID);
            self::recordTxnDaily($svc, $txndetails, $txnID, $msisdn, 'LOW_BALANCE_FAILED', $paymentDetails);
            self::sendSms($sokaLib, $svc, $msisdn, $moID, $txnID, PREDICT_LOW_BALANCE, 'PREDICT_LOW_BALANCE');
            return true;
        }

        $paymentArray['TXN_STATUS'] = 'BILLING_ERROR';
        $paymentArray['RESPONSE_RECEIVED'] = $paymentDetails ?: 'Billing failed';
        $svc->updatePayment('vas_transaction_trivia', 'ID', $txnID, $paymentArray, $msisdn);

        $svc->_update('tbl_predict_submissions', 'ID', $submission['ID'], ['STATUS' => 'PAYMENT_FAILED']);
        self::restoreFixtureChosenSession($svc, $msisdn, $submission, $moID);
        self::recordTxnDaily($svc, $txndetails, $txnID, $msisdn, 'BILLING_ERROR', $paymentDetails);
        self::sendSms($sokaLib, $svc, $msisdn, $moID, $txnID, PREDICT_ERROR_MSG, 'PREDICT_ERROR');
        return true;
    }

    private static function handlePredictEntry($msisdn, $moID, SokaLib $sokaLib, SokaServices $svc, $subDetails)
    {
        if (empty($subDetails) || ($subDetails['ACTIVITY_STATUS'] ?? '') !== 'ACTIVE') {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_INACTIVE_SUB, 'PREDICT_INACTIVE');
            return true;
        }

        $ctx = self::loadPublishedContext($svc);
        if (empty($ctx)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_NO_FIXTURES, 'PREDICT_NO_FIXTURES');
            return true;
        }

        $fixtures = $ctx['fixtures'];
        $maxSlot = count($fixtures);
        if ($maxSlot === 0) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_NO_FIXTURES, 'PREDICT_NO_FIXTURES');
            return true;
        }

        $available = self::filterAvailableFixtures($svc, $msisdn, $fixtures);
        if (count($available) === 0) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_ALL_DONE, 'PREDICT_ALL_DONE');
            return true;
        }

        $listText = self::buildFixtureList($ctx['match_day'], $available);
        self::upsertSession($svc, $msisdn, [
            'CAMPAIGN_ID' => $ctx['campaign']['ID'],
            'MATCH_DAY_ID' => $ctx['match_day']['ID'],
            'MAX_SLOT' => $maxSlot,
            'STATE' => 'LIST_SENT',
            'PENDING_SUBMISSION_ID' => null,
            'SELECTED_SLOT' => null,
            'SELECTED_FIXTURE_ID' => null,
            'LAST_MO_ID' => $moID,
            'EXPIRES_AT' => self::sessionExpiry(),
        ]);

        self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, $listText, 'PREDICT_LIST');
        return true;
    }

    private static function handleSlotSelect($msisdn, $rawSms, $slot, $moID, SokaLib $sokaLib, SokaServices $svc, $subDetails, $session)
    {
        if (($session['STATE'] ?? '') !== 'LIST_SENT' && ($session['STATE'] ?? '') !== 'SUBMITTED') {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_WRONG_STATE, 'PREDICT_WRONG_STATE');
            return true;
        }

        $maxSlot = (int) $session['MAX_SLOT'];
        if ($slot < 1 || $slot > $maxSlot) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_INVALID_SLOT, 'PREDICT_INVALID_SLOT');
            return true;
        }

        $fixture = self::getFixtureBySlot($svc, (int) $session['MATCH_DAY_ID'], $slot);
        if (empty($fixture)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_INVALID_SLOT, 'PREDICT_INVALID_SLOT');
            return true;
        }

        if (self::hasBlockingSubmission($svc, $msisdn, (int) $fixture['ID'])) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_ALREADY_SUBMITTED, 'PREDICT_ALREADY');
            return true;
        }

        if (strtotime($fixture['KICKOFF_AT']) <= time()) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_AFTER_KICKOFF, 'PREDICT_AFTER_KICKOFF');
            return true;
        }

        self::upsertSession($svc, $msisdn, [
            'STATE' => 'FIXTURE_CHOSEN',
            'SELECTED_SLOT' => $slot,
            'SELECTED_FIXTURE_ID' => $fixture['ID'],
            'PENDING_SUBMISSION_ID' => null,
            'LAST_MO_ID' => $moID,
            'EXPIRES_AT' => self::sessionExpiry(),
        ]);

        self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_FIXTURE_CHOSEN, 'PREDICT_FIXTURE_CHOSEN');
        return true;
    }

    private static function handleScore($msisdn, $rawSms, $homeGoals, $awayGoals, $moID, SokaLib $sokaLib, SokaServices $svc, $subDetails, $session, $paymentType)
    {
        if (($session['STATE'] ?? '') !== 'FIXTURE_CHOSEN') {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_WRONG_STATE, 'PREDICT_WRONG_STATE');
            return true;
        }

        if (empty($subDetails) || ($subDetails['ACTIVITY_STATUS'] ?? '') !== 'ACTIVE') {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_INACTIVE_SUB, 'PREDICT_INACTIVE');
            return true;
        }

        $fixtureId = (int) $session['SELECTED_FIXTURE_ID'];
        $slot = (int) $session['SELECTED_SLOT'];
        $fixture = self::getFixtureById($svc, $fixtureId);
        if (empty($fixture)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_WRONG_STATE, 'PREDICT_WRONG_STATE');
            return true;
        }

        if (strtotime($fixture['KICKOFF_AT']) <= time()) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_AFTER_KICKOFF, 'PREDICT_AFTER_KICKOFF');
            return true;
        }

        if (self::hasBlockingSubmission($svc, $msisdn, $fixtureId)) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_ALREADY_SUBMITTED, 'PREDICT_ALREADY');
            return true;
        }

        $campaignId = (int) $session['CAMPAIGN_ID'];
        $matchDayId = (int) $session['MATCH_DAY_ID'];
        $amount = PREDICT_CHARGE_AMOUNT;

        $submission = [
            'CAMPAIGN_ID' => $campaignId,
            'FIXTURE_ID' => $fixtureId,
            'MSISDN' => $msisdn,
            'MATCH_DAY_ID' => $matchDayId,
            'SLOT' => $slot,
            'PRED_HOME_GOALS' => $homeGoals,
            'PRED_AWAY_GOALS' => $awayGoals,
            'RAW_TEXT' => substr($rawSms, 0, 32),
            'MO_ID' => $moID,
            'AMOUNT' => $amount,
            'STATUS' => 'PENDING_PAYMENT',
        ];
        $submissionId = $svc->recordData('tbl_predict_submissions', $submission);
        if ($submissionId <= 0) {
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_ERROR_MSG, 'PREDICT_ERROR');
            return true;
        }

        self::upsertSession($svc, $msisdn, [
            'STATE' => 'BILLING_PENDING',
            'PENDING_SUBMISSION_ID' => $submissionId,
            'LAST_MO_ID' => $moID,
            'EXPIRES_AT' => self::sessionExpiry(),
        ]);

        $charge = $sokaLib->sendCharging($moID, $amount, false, 'PREDICT', $paymentType);
        if (empty($charge['isResponse']) || empty($charge['txnID'])) {
            $svc->_update('tbl_predict_submissions', 'ID', $submissionId, ['STATUS' => 'PAYMENT_FAILED']);
            self::upsertSession($svc, $msisdn, [
                'STATE' => 'FIXTURE_CHOSEN',
                'PENDING_SUBMISSION_ID' => null,
            ]);
            self::sendSms($sokaLib, $svc, $msisdn, $moID, 0, PREDICT_ERROR_MSG, 'PREDICT_CHARGE_FAIL');
            return true;
        }

        $requestId = $charge['requestID'] ?? '';
        if ($requestId === '') {
            $txn = $svc->getTransactionById((int) $charge['txnID'], $msisdn);
            $requestId = $txn['REQUEST_ID'] ?? '';
        }
        $svc->_update('tbl_predict_submissions', 'ID', $submissionId, [
            'TXN_ID' => (int) $charge['txnID'],
            'REQUEST_ID' => $requestId,
        ]);

        return true;
    }

    private static function isPredictTraffic($sms)
    {
        if ($sms === 'PREDICT') {
            return true;
        }
        return (bool) preg_match('/^M\s*\d+/', $sms);
    }

    /** Regex for score MO; separators from PREDICT_SCORE_SEPARATORS in config.php. */
    private static function scoreMoPattern()
    {
        $sepClass = preg_quote(PREDICT_SCORE_SEPARATORS, '/');
        return '/^M\s*(\d+)\s*[' . $sepClass . ']\s*(\d+)$/';
    }

    /** @return array{0: int, 1: int}|null [homeGoals, awayGoals] */
    private static function parseScoreMo($sms)
    {
        if (preg_match(self::scoreMoPattern(), $sms, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return null;
    }

    private static function loadPublishedContext(SokaServices $svc)
    {
        $today = date('Y-m-d');
        $campaign = $svc->fetchRow(
            "SELECT * FROM tbl_predict_campaign WHERE STATUS = 'ACTIVE' AND START_DATE <= '{$today}' AND END_DATE >= '{$today}' ORDER BY ID DESC LIMIT 1"
        );
        if (empty($campaign)) {
            return null;
        }

        $campaignId = (int) $campaign['ID'];
        $matchDay = $svc->fetchRow(
            "SELECT * FROM tbl_predict_match_day WHERE CAMPAIGN_ID = {$campaignId} AND MATCH_DATE = '{$today}' AND STATUS = 'PUBLISHED' LIMIT 1"
        );
        if (empty($matchDay)) {
            return null;
        }

        $fixtures = $svc->fetchAll(
            "SELECT * FROM tbl_predict_fixtures WHERE MATCH_DAY_ID = " . (int) $matchDay['ID'] . " AND STATUS = 'SCHEDULED' ORDER BY SLOT ASC"
        );

        return [
            'campaign' => $campaign,
            'match_day' => $matchDay,
            'fixtures' => $fixtures,
        ];
    }

    private static function buildFixtureList($matchDay, $fixtures)
    {
        if (!empty($matchDay['LIST_SMS_TEXT'])) {
            return $matchDay['LIST_SMS_TEXT'];
        }
        $parts = [];
        foreach ($fixtures as $f) {
            $parts[] = $f['SLOT'] . '. ' . $f['DISPLAY_TEXT'];
        }
        return PREDICT_LIST_HEADER . ' ' . implode(' ', $parts);
    }

    private static function filterAvailableFixtures(SokaServices $svc, $msisdn, $fixtures)
    {
        $out = [];
        foreach ($fixtures as $f) {
            if (!self::hasBlockingSubmission($svc, $msisdn, (int) $f['ID'])) {
                $out[] = $f;
            }
        }
        return $out;
    }

    private static function hasBlockingSubmission(SokaServices $svc, $msisdn, $fixtureId)
    {
        $row = $svc->fetchRow(
            "SELECT ID FROM tbl_predict_submissions WHERE MSISDN = '{$msisdn}' AND FIXTURE_ID = {$fixtureId} AND STATUS IN (" . self::$blockingStatuses . ") LIMIT 1"
        );
        return !empty($row);
    }

    private static function getFixtureBySlot(SokaServices $svc, $matchDayId, $slot)
    {
        return $svc->fetchRow(
            "SELECT * FROM tbl_predict_fixtures WHERE MATCH_DAY_ID = {$matchDayId} AND SLOT = {$slot} LIMIT 1"
        );
    }

    private static function getFixtureById(SokaServices $svc, $fixtureId)
    {
        return $svc->fetchRow("SELECT * FROM tbl_predict_fixtures WHERE ID = {$fixtureId} LIMIT 1");
    }

    private static function resolveSubmission(SokaServices $svc, $requestID, $msisdn, $txnID = 0)
    {
        $msisdn = addslashes($msisdn);
        if ($txnID > 0) {
            $row = $svc->fetchRow(
                "SELECT * FROM tbl_predict_submissions WHERE MSISDN = '{$msisdn}' AND TXN_ID = " . (int) $txnID . " ORDER BY ID DESC LIMIT 1"
            );
            if (!empty($row)) {
                return $row;
            }
        }
        $requestID = addslashes((string) $requestID);
        if ($requestID !== '') {
            return $svc->fetchRow(
                "SELECT * FROM tbl_predict_submissions WHERE REQUEST_ID = '{$requestID}' AND MSISDN = '{$msisdn}' ORDER BY ID DESC LIMIT 1"
            );
        }
        return null;
    }

    private static function loadSession(SokaServices $svc, $msisdn)
    {
        return $svc->fetchRow("SELECT * FROM tbl_predict_session WHERE MSISDN = '{$msisdn}' LIMIT 1");
    }

    private static function deleteSession(SokaServices $svc, $msisdn)
    {
        $svc->execSql("DELETE FROM tbl_predict_session WHERE MSISDN = '{$msisdn}'");
    }

    private static function isSessionExpired($session)
    {
        if (empty($session['EXPIRES_AT'])) {
            return true;
        }
        return strtotime($session['EXPIRES_AT']) < time();
    }

    private static function sessionExpiry()
    {
        return date('Y-m-d H:i:s', strtotime('+24 hours'));
    }

    private static function upsertSession(SokaServices $svc, $msisdn, array $fields)
    {
        $existing = self::loadSession($svc, $msisdn);
        if (empty($existing)) {
            if (!isset($fields['CAMPAIGN_ID'], $fields['MATCH_DAY_ID'], $fields['MAX_SLOT'])) {
                return;
            }
            $fields['MSISDN'] = $msisdn;
            if (!isset($fields['EXPIRES_AT'])) {
                $fields['EXPIRES_AT'] = self::sessionExpiry();
            }
            $svc->recordData('tbl_predict_session', $fields);
            return;
        }

        $svc->_update('tbl_predict_session', 'MSISDN', $msisdn, $fields);
    }

    private static function restoreFixtureChosenSession(SokaServices $svc, $msisdn, $submission, $moID)
    {
        self::upsertSession($svc, $msisdn, [
            'STATE' => 'FIXTURE_CHOSEN',
            'SELECTED_SLOT' => $submission['SLOT'],
            'SELECTED_FIXTURE_ID' => $submission['FIXTURE_ID'],
            'PENDING_SUBMISSION_ID' => null,
            'LAST_MO_ID' => $moID,
            'EXPIRES_AT' => self::sessionExpiry(),
        ]);
    }

    private static function recordTxnDaily(SokaServices $svc, $txndetails, $txnID, $msisdn, $status, $paymentDetails)
    {
        $txnData = [];
        $txnData['MO_ID'] = $txndetails['MO_ID'];
        $txnData['TXN_ID'] = $txnID;
        $txnData['MSISDN'] = $msisdn;
        $txnData['AMOUNT'] = $txndetails['AMOUNT'];
        $txnData['TXN_STATUS'] = $status;
        $txnData['RESPONSE_RECEIVED'] = $paymentDetails;
        $txnData['RESP_TIME'] = date('Y-m-d H:i:s');
        $svc->recordData('vas_transaction_trivia_' . date('Ymd'), $txnData);

        $paymentUpdateArray = [];
        $paymentUpdateArray['TXN_STATUS'] = $status;
        $paymentUpdateArray['RESP_TIME'] = date('Y-m-d H:i:s');
        $paymentUpdateArray['PAYMENT_DETAILS'] = $paymentDetails;
        $svc->_update('vas_transaction_trivia_new', 'TXN_ID', $txnID, $paymentUpdateArray);
    }

    private static function sendSms(SokaLib $sokaLib, SokaServices $svc, $msisdn, $moID, $txnID, $text, $smsType)
    {
        $status = $sokaLib->interactiveRequest($text, $moID, $txnID, $smsType);
        if (function_exists('updateSMSStatus')) {
            updateSMSStatus($svc, $status, $msisdn);
        }
    }
}
