package sokagame

import (
	"regexp"
	"strconv"
	"strings"
)

// Predict-and-Win constants from config.php.
const (
	PredictCharge   int64 = 99
	predictExamples       = "M2-1 au M2,1 au M2;1 au M2:1"

	PredictListHeader    = "Ubashiri wa leo:"
	PredictFixtureChosen = "Asante kwa kuchagua mechi. Tuma ubashiri wako kwa mfano " + predictExamples + "."
	PredictInvalidScore  = "Tuma ubashiri kwa mfano " + predictExamples + " (tumia - , ; au : kati ya magoli)."
	PredictReceived      = "Ndugu mteja, Ubashiri wako umepokelewa. Tutakujulisha matokeo baada ya mechi kumalizika."
	PredictLowBalance    = "Ndugu Mteja huna salio la kutosha kubashiri. Tafadhali ongeza salio na ujaribu tena."
	PredictInactive      = "Tuma neno SOKA kwanza ujiunge na huduma ya SOKA ili kushiriki Ubashiri na Ushindi."
	PredictNoFixtures    = "Hakuna mechi za ubashiri leo. Tafadhali jaribu tena baadaye."
	PredictInvalidSlot   = "Nambari ya mechi si sahihi. Tuma PREDICT kupata orodha ya mechi."
	PredictFirst         = "Tuma neno PREDICT kwanza ili kupata orodha ya mechi."
	PredictAfterKickoff  = "Muda wa kutuma ubashiri umeisha kwa mechi hii."
	PredictAlready       = "Tayari umeshatuma ubashiri kwa mechi hii. Chagua mechi nyingine."
	PredictAllDone       = "Umeshatuma ubashiri kwa mechi zote za leo. Asante!"
	PredictExpired       = "Kipindi chako kimeisha. Tuma PREDICT tena."
	PredictWrongState    = "Tafadhali fuata hatua: PREDICT, kisha chagua mechi (mfano M2), kisha tuma matokeo (mfano " + predictExamples + ")."
	PredictError         = "Ndugu Mteja, Tafadhali tuma ubashiri wako tena. Asante."
	predictRequestPrefix = "PD"
	legacyPredictPrefix  = "PW"
)

// IsPredictRequest matches PHP's PD prefix and the older PW one.
func IsPredictRequest(requestID string) bool {
	upper := strings.ToUpper(requestID)
	return strings.HasPrefix(upper, predictRequestPrefix) || strings.HasPrefix(upper, legacyPredictPrefix)
}

func PredictRequestID(callbackID int64, msisdn string) string {
	return predictRequestPrefix + strconv.FormatInt(callbackID, 10) + msisdn
}

type PredictStep int

const (
	PredictEntry PredictStep = iota
	PredictSlot
	PredictScore
	PredictBadScore
	PredictOther
)

var (
	scorePattern = regexp.MustCompile(`^M\s*(\d+)\s*[-,;:]\s*(\d+)$`)
	slotPattern  = regexp.MustCompile(`^M\s*(\d+)$`)
	spacedScore  = regexp.MustCompile(`^M\s*\d+\s+\d+$`)
)

// ParsePredict classifies predict traffic in PHP's order. For a score, a and b
// are the home and away goals; for a slot, a is the slot.
func ParsePredict(text string) (step PredictStep, a, b int) {
	upper := strings.ToUpper(strings.TrimSpace(text))
	if upper == "PREDICT" {
		return PredictEntry, 0, 0
	}
	if m := scorePattern.FindStringSubmatch(upper); m != nil {
		a, errA := strconv.Atoi(m[1])
		b, errB := strconv.Atoi(m[2])
		if errA == nil && errB == nil {
			return PredictScore, a, b
		}
	}
	if m := slotPattern.FindStringSubmatch(upper); m != nil {
		if n, err := strconv.Atoi(m[1]); err == nil {
			return PredictSlot, n, 0
		}
	}
	if spacedScore.MatchString(upper) {
		return PredictBadScore, 0, 0
	}
	return PredictOther, 0, 0
}
