package sokagame

import (
	"testing"
	"time"
)

func TestIshikistaaProfile(t *testing.T) {
	p := Ishikistaa
	// No Predict-and-Win: predict-looking text is an answer, as in PHP.
	if p.Classify("M2", "") != Answer || p.Classify("PREDICT", "") != Answer || Soka.Classify("M2", "") != Predict {
		t.Fatal("predict classification per product")
	}
	if p.Classify("ondoa ishikistaa", "") != Unsubscribe || p.Classify("ondoa soka", "") != Answer || Soka.Classify("ondoa ishikistaa", "") != Answer {
		t.Fatal("unsubscribe words per product")
	}
	if !p.IsReactivation(" STAA ") || !p.IsReactivation("star") || p.IsReactivation("soka") {
		t.Fatal("reactivation words")
	}
	// Golden hours end at 21:59 in Dar es Salaam (18:59 UTC).
	at := func(h int) time.Time { return time.Date(2026, 10, 28, h, 30, 0, 0, time.UTC) }
	if !p.IsGoldenHour(at(18)) || p.IsGoldenHour(at(19)) || !Soka.IsGoldenHour(at(19)) {
		t.Fatal("golden hours per product")
	}
	if !p.IsStaff("255715123000") || p.IsStaff("255658123000") || !Soka.IsStaff("255658123000") {
		t.Fatal("staff prefixes per product")
	}
	if got := p.QuestionMessage(125, "Q"); got != "Una pointi 125! Q." {
		t.Fatal(got)
	}
	if got := p.CreditQuestion(125, 2, "Q"); got != "Una pointi 125!\n KETE 2 Buree!\nQ" {
		t.Fatalf("%q", got)
	}
	if got := p.WelcomeQuestion(0, "Q"); got != "Jibu swali ushinde zaidi ya 12,000,000 msimu huu. Anza sasa!Q." {
		t.Fatal(got)
	}
	if got := p.ActivationReactivationQuestion(40, "Q"); got != "Q." {
		t.Fatal(got)
	}
	if got := Soka.ActivationReactivationQuestion(40, "Q"); got != Soka.WelcomeQuestion(40, "Q") {
		t.Fatal(got)
	}
	if ByCode("ishikistaa") != Ishikistaa || ByCode("soka") != Soka || ByCode("megapromo") != nil {
		t.Fatal("ByCode")
	}
}
