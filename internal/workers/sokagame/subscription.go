package sokagame

import (
	"strconv"
	"strings"
)

// TestOfferCode marks provider test traffic. SokaTrivia.php stores those MOs in
// tbl_interaction_mo_notifications_test, which no live worker reads.
const TestOfferCode = "100000014600308"

// Subscription messages, verbatim from config.php and SokaMoConsumer.php.
const (
	FirstAppend      = "Una point %s. PAPO HAPO unabahatika na SOKA;"
	TermsSuffix      = ". Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions"
	NonCustomer      = "Hongera! Hii ni SOKA, MAMILIONI yanakungoja wewe! Shinda hadi Tsh 12,000,000 TASLIM! Sehemu ya Tsh 9,000,000 KILA SIKU na sehemu ya Tsh 6,000,000 KILA WIKI! Jibu ujumbe kwa neno SOKA USHINDE!"
	InactiveSub      = "Asante kwa ujumbe wako! Tuma neno SOKA rudi mchezoni ujizolee sehemu ya Tsh 20,300,000, Vocha na zawadi kibao kutoka Tigo Soka sasa hivi."
	BoomCreditNotify = "Ndugu mteja, Umezawadiwa nafasi 1 ya kujibu swali la SOKA buree. Itumie fursa hii kujibu na uingie kwenye Droo ya sehemu ya Tsh 10,000,000 sasa."
)

const (
	KindBoomNotify  = "BOOM_CREDIT_NOTIFY"
	KindNonCustomer = "NON_SUBSCRIBER"
	KindInactiveSub = "INACTIVE_SUB"
)

// staffPrefixes are SokaLib::staffSeries. PHP matches them anywhere in the
// MSISDN; staff skip the renewal system and get do-not-disturb.
var staffPrefixes = []string{"255715123", "255713123", "255658123", "255673123", "255678123"}

func IsStaff(msisdn string) bool {
	for _, prefix := range staffPrefixes {
		if strings.Contains(msisdn, prefix) {
			return true
		}
	}
	return false
}

// IsBoomTag reports the BOOM_CUSTOMER-SOKA activation text PHP builds from the
// provider's CustomerType.
func IsBoomTag(customerType string) bool { return customerType == "BOOM_CUSTOMER" }

// IsReactivation is the word an inactive subscriber sends to rejoin.
func IsReactivation(text string) bool { return asciiLower(strings.TrimSpace(text)) == "soka" }

func firstAppend(points int64) string {
	return strings.Replace(FirstAppend, "%s", strconv.FormatInt(points, 10), 1)
}

// WelcomeQuestion is the first question after an activation callback, with the
// terms link.
func WelcomeQuestion(points int64, question string) string {
	return firstAppend(points) + question + TermsSuffix
}

// ReactivationQuestion follows an inactive subscriber's SOKA. PHP sends the
// FIRST_APPEND template without substituting %s; Go fills in the points.
func ReactivationQuestion(points int64, question string) string {
	return firstAppend(points) + question + "."
}

// CreditQuestion is sent after a free-credit answer. PHP adds no full stop.
func CreditQuestion(total, remaining int64, question string) string {
	body := strings.Replace(PointAppend, "%s", strconv.FormatInt(total, 10), 1)
	if remaining >= 1 {
		body += "KETE " + strconv.FormatInt(remaining, 10) + " Buree!"
	}
	return body + question
}
