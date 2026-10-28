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

// IsBoomTag reports the BOOM_CUSTOMER-SOKA activation text PHP builds from the
// provider's CustomerType.
func IsBoomTag(customerType string) bool { return customerType == "BOOM_CUSTOMER" }

// OpenQuestionContent is OPEN_QN_CONTENT, sent when the provider reports that
// no interactive session is open for the subscriber's reply.
const OpenQuestionContent = "Hongera sana umejikusanyia alama %s hadi sasa. Ongeza alama kidogo tu unaweza kubahatika na sehemu ya Tsh 5,500,000;"

const KindOpenQuestion = "OPEN_QUESTION"

// accentFolds is SokaLib::convert's character map.
var accentFolds = strings.NewReplacer(
	"Š", "S", "š", "s", "Ð", "Dj", "Ž", "Z", "ž", "z", "À", "A", "Á", "A", "Â", "A", "Ã", "A", "Ä", "A",
	"Å", "A", "Æ", "A", "Ç", "C", "È", "E", "É", "E", "Ê", "E", "Ë", "E", "Ì", "I", "Í", "I", "Î", "I",
	"Ï", "I", "Ñ", "N", "Ń", "N", "Ò", "O", "Ó", "O", "Ô", "O", "Õ", "O", "Ö", "O", "Ø", "O", "Ù", "U", "Ú", "U",
	"Û", "U", "Ü", "U", "Ý", "Y", "Þ", "B", "ß", "Ss", "à", "a", "á", "a", "â", "a", "ã", "a", "ä", "a",
	"å", "a", "æ", "a", "ç", "c", "è", "e", "é", "e", "ê", "e", "ë", "e", "ì", "i", "í", "i", "î", "i",
	"ï", "i", "ð", "o", "ñ", "n", "ń", "n", "ò", "o", "ó", "o", "ô", "o", "õ", "o", "ö", "o", "ø", "o", "ù", "u",
	"ú", "u", "û", "u", "ü", "u", "ý", "y", "þ", "b", "ÿ", "y", "ƒ", "f",
	"ă", "a", "ș", "s", "ț", "t", "Ă", "A", "Ș", "S", "Ț", "T",
)

// OpenQuestionBody joins the content and the current question and fills in
// %msisdn and %s (points), as the PHP senders do. PHP also runs utf8_encode
// first, which double-encodes text that is already UTF-8; Go only folds accents.
func OpenQuestionBody(content, question, msisdn string, points int64) string {
	body := content + " " + question
	body = strings.ReplaceAll(body, "%msisdn", msisdn)
	body = strings.ReplaceAll(body, "%s", strconv.FormatInt(points, 10))
	return accentFolds.Replace(body)
}
