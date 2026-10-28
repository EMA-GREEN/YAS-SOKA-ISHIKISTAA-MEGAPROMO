package sokagame

import (
	"strconv"
	"strings"
	"time"
)

// Profile holds what differs between the trivia products that share the
// Soka engine. See docs/ishikistaa-differences.md.
type Profile struct {
	Code      string // products.code and callback product
	ServiceID string // renewal-system serviceID
	// processed_events consumer names for the MO and payment workers.
	MOConsumer, PaymentConsumer string

	ChargeOffer, BoomOffer string
	GoldenEnd              int // last golden hour, inclusive
	StaffPrefixes          []string
	// Renewal endpoints staff are still reported to; Soka reports none.
	StaffRenewalTargets []string
	TestOfferCode       string

	ReactivationWords, UnsubscribeWords []string

	PointAppend, PointMsg, HelpMsg, OndoaMsg, LowBalance, ErrorMsg string
	FirstAppend, TermsSuffix, NonCustomer, OpenQuestionContent     string
	// InactiveReply answers other text from an inactive or unknown subscriber.
	InactiveReply, InactiveReplyKind string
	// UnsubSMS, when set, is sent on every unsubscription; Soka unsubscribes silently.
	UnsubSMS string
	// CreditSeparator surrounds "KETE n Buree!" in the free-credit reply.
	CreditPrefix, CreditSuffix string
	// QuestionJoin goes between the points and the next question after a paid answer.
	QuestionJoin string

	Predict, Boom, LevelBonuses, LevelOnPayment bool
	// PlainReactivation sends just "question." when an activation reactivates.
	PlainReactivation bool
}

var Soka = &Profile{
	Code: "soka", ServiceID: "SOKA", MOConsumer: "soka-point-mo", PaymentConsumer: "soka-point-purchase",
	ChargeOffer: NormalOffer, BoomOffer: "017008600272", GoldenEnd: 23,
	StaffPrefixes:     []string{"255715123", "255713123", "255658123", "255673123", "255678123"},
	TestOfferCode:     TestOfferCode,
	ReactivationWords: []string{"soka"}, UnsubscribeWords: []string{"ondoa soka", "ondoasoka", "stop"},
	PointAppend: PointAppend, PointMsg: PointMsg, HelpMsg: HelpMsg, OndoaMsg: OndoaMsg, LowBalance: LowBalance, ErrorMsg: ErrorMsg,
	FirstAppend: FirstAppend, TermsSuffix: TermsSuffix, NonCustomer: NonCustomer, OpenQuestionContent: OpenQuestionContent,
	InactiveReply: InactiveSub, InactiveReplyKind: KindInactiveSub,
	Predict: true, Boom: true, LevelBonuses: true, LevelOnPayment: true,
}

// Ishikistaa follows YAS-ISHIKISTAA-SDP/config.php and IshikistaaLib.php.
var Ishikistaa = &Profile{
	Code: "ishikistaa", ServiceID: "ISHIKISTAA", MOConsumer: "ishikistaa-mo", PaymentConsumer: "ishikistaa-payment",
	ChargeOffer: "017008600007", GoldenEnd: 21,
	StaffPrefixes:       []string{"255715123", "255713123"},
	StaffRenewalTargets: []string{"SUBS_MANAGE"},
	ReactivationWords:   []string{"staa", "star"},
	UnsubscribeWords:    []string{"ondoa ishikistaa", "ondoaishikistaa", "stop"},
	PointAppend:         "Una pointi %s!",
	PointMsg:            "Point zako ni %s. Cheza USHINDE droo ya siku, wiki na ZAWADI KUU ya Tsh.5,000,000! Jishindie ZAWADI hadi Tsh.11,500,000!CHEZA ZAIDI USHINDE ZAIDI!!",
	HelpMsg:             "Jibu maswali ya burudani kwa Tsh99/sms, kusanya ALAMA ubahatishe hadi Tsh 50,000,000. Jibu sahihi unapata ALAMA 100, lisilo sahihi ALAMA 25. Kujitoa tuma ONDOA.",
	OndoaMsg:            "UNAKOSA MAMILIONI ya sehemu ya Tsh 4,900,000. Umejitoka katika Ishi Kistaa. Kujiunga tena, tuma neno STAA kwenda 15633 USHINDE!",
	LowBalance:          "Ndugu mteja hauna salio la kutosha kucheza ISHI KISTAA, tafadhali ongeza salio kisha tuma JIBU LAKO tena.Pole kwa usumbufu, bado USHINDI UKO MIKONONI MWAKO!!",
	ErrorMsg:            ErrorMsg,
	FirstAppend:         "Jibu swali ushinde zaidi ya 12,000,000 msimu huu. Anza sasa!",
	NonCustomer:         "Hujajiunga na huduma ya ISHI KISTAA!Jiunge SASA Ushinde hadi Tsh.11,500,000!Tsh.1,000,000 KILA WIKI!Tuma neno STAA kwa Tsh.99/SMS!CHEZA ZAIDI USHINDE ZAIDI!",
	OpenQuestionContent: "Hongera sana umejikusanyia alama %s hadi sasa. Ongeza alama kidogo tu unaweza kubahatika na sehemu ya Tsh 4,500,000;",
	InactiveReplyKind:   KindNonCustomer,
	UnsubSMS:            "Wewe ni mmoja wa wachezaji BORA!Usipoteze fursa ya kushinda hadi Tsh.11,500,000!KUJIUNGA tena tuma STAA kwenda 15633.CHEZA ZAIDI USHINDE ZAIDI!",
	CreditPrefix:        "\n ", CreditSuffix: "\n", QuestionJoin: " ",
	PlainReactivation: true,
}

func init() { Ishikistaa.InactiveReply = Ishikistaa.NonCustomer }

// ByCode returns the profile for a product code, or nil.
func ByCode(code string) *Profile {
	for _, p := range []*Profile{Soka, Ishikistaa} {
		if p.Code == code {
			return p
		}
	}
	return nil
}

// Classify follows the order PHP checks an active subscriber's MO in.
// Anything not recognized is an answer and is charged. Products without
// Predict-and-Win charge predict-looking text as an answer, as PHP does.
func (p *Profile) Classify(text, reason string) Class {
	trimmed := strings.TrimSpace(text)
	upper := strings.ToUpper(trimmed)
	if p.Predict && (upper == "PREDICT" || predictTraffic.MatchString(upper)) {
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
	lower := asciiLower(trimmed)
	switch lower {
	case "ondoa":
		return OndoaInfo
	case "pointi", "points", "point":
		return Points
	case "help", "mzaada", "zaidi", "mzada", "msaada", "msada":
		return Help
	}
	for _, w := range p.UnsubscribeWords {
		if lower == w {
			return Unsubscribe
		}
	}
	return Answer
}

func (p *Profile) IsGoldenHour(t time.Time) bool {
	h := t.In(darEsSalaam).Hour()
	return h >= goldenStart && h <= p.GoldenEnd
}

func (p *Profile) IsStaff(msisdn string) bool {
	for _, prefix := range p.StaffPrefixes {
		if strings.Contains(msisdn, prefix) {
			return true
		}
	}
	return false
}

func (p *Profile) IsReactivation(text string) bool {
	lower := asciiLower(strings.TrimSpace(text))
	for _, w := range p.ReactivationWords {
		if lower == w {
			return true
		}
	}
	return false
}

func (p *Profile) points(total int64) string {
	return strings.Replace(p.PointAppend, "%s", strconv.FormatInt(total, 10), 1)
}

func (p *Profile) firstAppend(points int64) string {
	return strings.Replace(p.FirstAppend, "%s", strconv.FormatInt(points, 10), 1)
}

// QuestionMessage is the next-question SMS after a successful paid answer.
func (p *Profile) QuestionMessage(total int64, question string) string {
	return p.points(total) + p.QuestionJoin + question + "."
}

func (p *Profile) PointsMessage(total int64, msisdn string) string {
	return strings.ReplaceAll(strings.ReplaceAll(p.PointMsg, "%s", strconv.FormatInt(total, 10)), "%msisdn", msisdn)
}

// WelcomeQuestion is the first question after an activation callback.
func (p *Profile) WelcomeQuestion(points int64, question string) string {
	if p.TermsSuffix == "" {
		return p.firstAppend(points) + question + "."
	}
	return p.firstAppend(points) + question + p.TermsSuffix
}

// ActivationReactivationQuestion follows an activation of an inactive subscriber.
func (p *Profile) ActivationReactivationQuestion(points int64, question string) string {
	if p.PlainReactivation {
		return question + "."
	}
	return p.WelcomeQuestion(points, question)
}

// ReactivationQuestion follows the reactivation keyword from an inactive subscriber.
func (p *Profile) ReactivationQuestion(points int64, question string) string {
	return p.firstAppend(points) + question + "."
}

// CreditQuestion is sent after a free-credit answer.
func (p *Profile) CreditQuestion(total, remaining int64, question string) string {
	body := p.points(total)
	if remaining >= 1 {
		body += p.CreditPrefix + "KETE " + strconv.FormatInt(remaining, 10) + " Buree!" + p.CreditSuffix
	}
	return body + question
}

// Soka wrappers kept for the existing callers and tests.

func Classify(text, reason string) Class { return Soka.Classify(text, reason) }
func IsGoldenHour(t time.Time) bool      { return Soka.IsGoldenHour(t) }
func IsStaff(msisdn string) bool         { return Soka.IsStaff(msisdn) }
func IsReactivation(text string) bool    { return Soka.IsReactivation(text) }
func QuestionMessage(total int64, question string) string {
	return Soka.QuestionMessage(total, question)
}
func PointsMessage(total int64, msisdn string) string { return Soka.PointsMessage(total, msisdn) }
func WelcomeQuestion(points int64, question string) string {
	return Soka.WelcomeQuestion(points, question)
}
func ReactivationQuestion(points int64, question string) string {
	return Soka.ReactivationQuestion(points, question)
}
func CreditQuestion(total, remaining int64, question string) string {
	return Soka.CreditQuestion(total, remaining, question)
}
