// Package sokagame holds the Soka answer rules shared by the MO and payment
// workers. Values come from YAS-SOKA/config.php and the active-subscriber
// branch of SokaMoConsumer.php (the copy after the INACTIVE block, which PHP
// can actually reach) and SokaPaymentConsumers.php.
package sokagame

import (
	"regexp"
	"strconv"
	"strings"
	"time"
)

// AnswerCharge is the PLAY price in whole TZS.
const AnswerCharge int64 = 99

// NormalOffer is the offer PHP uses for answer fallbacks, whatever the
// customer's billing segment: sendCharging is called without a payment type.
const NormalOffer = "017008600005"

// Golden hours are 18:00 to 23:59 in Dar es Salaam (date("H") 18..23).
const goldenStart, goldenEnd = 18, 23

const (
	wrongPoints       = 25
	wrongPointsGolden = 50
)

// Customer messages, verbatim from config.php.
const (
	PointAppend = "Unapoint %s!"
	PointMsg    = "Point zako ni %s. Cheza sasa USHINDE droo ya siku, wiki na ZAWADI KUU ya Tsh.5,000,000! Jishindie ZAWADI za jumla ya Tsh.20,000,000!CHEZA ZAIDI USHINDE ZAIDI!"
	HelpMsg     = "Shinda hadi Tsh 100,000,000. Tsh 1,000,000/mwezi, Tsh 500,000/Wiki na Tsh 50,000/Siku. Jibu sahihi unapata ALAMA 100 lisilo sahihi ALAMA 25. Kujitoa tuma ONDOA."
	OndoaMsg    = "Ndugu mteja, kujitoa katika huduma ya SOKA tuma ONDOA SOKA kwenda 15670."
	LowBalance  = "Ndugu mteja hauna salio la kutosha kucheza SOKA, tafadhali ongeza salio kisha tuma JIBU LAKO tena.Pole kwa usumbufu, bado USHINDI UKO MIKONONI MWAKO!"
	ErrorMsg    = "Ndugu Mteja, Tafadhali tuma jibu lako tena. Asante."
)

// Outbound message kinds, named after the PHP SMS_TYPE values.
const (
	KindQuestion   = "QUESTION"
	KindReward     = "REWARD_MSG"
	KindPoints     = "POINT_MSG"
	KindHelp       = "HELP_MESSAGE"
	KindOndoa      = "ONDOA_UNSUB_SMS"
	KindLowBalance = "LOW_BALANCE"
	KindError      = "ERROR"
)

// Reasons the provider attaches to an MO when it has nothing to answer;
// PHP then sends the open question instead of treating the text as an answer.
const (
	noOpenQuestion       = "No open question for the subscriber"
	noInteractiveRequest = "no interactive request sent to subscriber today"
)

type Class int

const (
	Answer Class = iota
	Purchase
	PurchaseMenu
	Points
	Help
	OndoaInfo
	Unsubscribe  // ONDOA SOKA / STOP: needs the renewal system; not ported
	Predict      // Predict-and-Win: not ported
	OpenQuestion // provider says no question is open: not ported
)

var purchaseKeywords = map[string]int64{"P300": 300, "P600": 500, "P1500": 1000, "P2500": 2000, "P10000": 5000}
var similarPurchase = regexp.MustCompile(`(?i)^P[0-9]+`)
var predictTraffic = regexp.MustCompile(`^M\s*\d+`)

// PurchaseAmount returns the TZS price for an exact purchase keyword.
func PurchaseAmount(text string) (int64, bool) {
	amount, ok := purchaseKeywords[strings.ToUpper(strings.TrimSpace(text))]
	return amount, ok
}

// Classify follows the order PHP checks an active subscriber's MO in.
// Anything not recognized is an answer and is charged.
func Classify(text, reason string) Class {
	trimmed := strings.TrimSpace(text)
	upper := strings.ToUpper(trimmed)
	if upper == "PREDICT" || predictTraffic.MatchString(upper) {
		return Predict
	}
	lowerReason := strings.ToLower(reason)
	if strings.Contains(lowerReason, strings.ToLower(noOpenQuestion)) || strings.Contains(lowerReason, strings.ToLower(noInteractiveRequest)) {
		return OpenQuestion
	}
	if _, ok := PurchaseAmount(trimmed); ok {
		return Purchase
	}
	if similarPurchase.MatchString(trimmed) {
		return PurchaseMenu
	}
	switch asciiLower(trimmed) {
	case "ondoa":
		return OndoaInfo
	case "pointi", "points", "point":
		return Points
	case "help", "mzaada", "zaidi", "mzada", "msaada", "msada":
		return Help
	case "ondoa soka", "ondoasoka", "stop":
		return Unsubscribe
	}
	return Answer
}

func IsGoldenHour(t time.Time) bool {
	h := t.In(darEsSalaam).Hour()
	return h >= goldenStart && h <= goldenEnd
}

// Score decides an answer's result and the points it earns once charged.
// A correct answer earns the question's points, doubled in golden hours; a
// wrong one earns 25, or 50 in golden hours. The level bonus applies to both.
func Score(answerText, answerKey string, questionPoints int64, golden bool, levelBonus int64) (string, int64) {
	result, points := "WRONG", int64(wrongPoints)
	if golden {
		points = wrongPointsGolden
	}
	if asciiLower(strings.TrimSpace(answerText)) == asciiLower(answerKey) {
		result, points = "CORRECT", questionPoints
		if golden {
			points *= 2
		}
	}
	return result, points + levelBonus
}

// Fallback gives the cheaper charge PHP tries after a low-balance failure and
// the request ID prefix it uses: 99 → 45 ("F"), 45 → 30 ("FF"). Wallet
// payments get no fallback.
func Fallback(amount int64, billingSystem string) (next int64, prefix string, ok bool) {
	if billingSystem == "WALLET_ACCOUNT" {
		return 0, "", false
	}
	switch amount {
	case 99:
		return 45, "F", true
	case 45:
		return 30, "FF", true
	}
	return 0, "", false
}

// QuestionMessage is the next-question SMS after a successful answer charge.
// PHP also inserts rotating marketing text between the two parts; that content
// is not migrated.
func QuestionMessage(totalPoints int64, question string) string {
	return strings.Replace(PointAppend, "%s", strconv.FormatInt(totalPoints, 10), 1) + question + "."
}

func PointsMessage(totalPoints int64, msisdn string) string {
	return strings.ReplaceAll(strings.ReplaceAll(PointMsg, "%s", strconv.FormatInt(totalPoints, 10)), "%msisdn", msisdn)
}

// asciiLower matches PHP 8 strtolower, which changes only ASCII letters.
func asciiLower(s string) string {
	b := []byte(s)
	for i, c := range b {
		if 'A' <= c && c <= 'Z' {
			b[i] = c + 'a' - 'A'
		}
	}
	return string(b)
}

var darEsSalaam = func() *time.Location {
	if loc, err := time.LoadLocation("Africa/Dar_es_Salaam"); err == nil {
		return loc
	}
	return time.FixedZone("EAT", 3*60*60)
}()

// DarDayStart is midnight in Dar es Salaam on t's local date.
func DarDayStart(t time.Time) time.Time {
	local := t.In(darEsSalaam)
	return time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, darEsSalaam)
}

// DarDate is the Dar es Salaam calendar date used for dated level bonuses.
func DarDate(t time.Time) string { return t.In(darEsSalaam).Format("2006-01-02") }
