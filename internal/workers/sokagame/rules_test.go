package sokagame

import (
	"testing"
	"time"
)

func TestClassify(t *testing.T) {
	for text, want := range map[string]Class{
		"A": Answer, " b ": Answer, "hello": Answer, "soka": Answer, "P": Answer, "Pointsss": Answer,
		" p300 ": Purchase, "P10000": Purchase, "P750": PurchaseMenu, "p1": PurchaseMenu,
		"ONDOA": OndoaInfo, "Points": Points, "pointi": Points, "point": Points,
		"HELP": Help, "msaada": Help, "Zaidi": Help,
		"ondoa soka": Unsubscribe, "OndoaSoka": Unsubscribe, "STOP": Unsubscribe,
		"predict": Predict, "M2": Predict, "m 2-1": Predict,
	} {
		if got := Classify(text, ""); got != want {
			t.Errorf("Classify(%q) = %v, want %v", text, got, want)
		}
	}
	if Classify("A", "No open question for the subscriber") != OpenQuestion ||
		Classify("A", "x: NO INTERACTIVE REQUEST SENT TO SUBSCRIBER TODAY") != OpenQuestion {
		t.Fatal("provider no-question reasons must not be charged as answers")
	}
	// Predict-and-Win is checked first, as in PHP.
	if Classify("M1", "No open question for the subscriber") != Predict {
		t.Fatal("predict traffic must win")
	}
}

func TestScore(t *testing.T) {
	cases := []struct {
		text, key string
		golden    bool
		bonus     int64
		result    string
		points    int64
	}{
		{"a", "A", false, 0, "CORRECT", 100},
		{" A ", "a", true, 0, "CORRECT", 200},
		{"B", "A", false, 0, "WRONG", 25},
		{"B", "A", true, 0, "WRONG", 50},
		{"A", "A", false, 30, "CORRECT", 130},
		{"B", "A", true, 30, "WRONG", 80},
		// PHP strtolower is ASCII-only, so non-ASCII case differences do not match.
		{"É", "é", false, 0, "WRONG", 25},
	}
	for _, c := range cases {
		result, points := Score(c.text, c.key, 100, c.golden, c.bonus)
		if result != c.result || points != c.points {
			t.Errorf("Score(%q,%q,golden=%v,bonus=%d) = %s %d, want %s %d", c.text, c.key, c.golden, c.bonus, result, points, c.result, c.points)
		}
	}
}

func TestGoldenHourUsesDarEsSalaam(t *testing.T) {
	for utcHour, want := range map[int]bool{14: false, 15: true, 20: true, 21: false, 3: false} {
		if got := IsGoldenHour(time.Date(2026, 10, 28, utcHour, 30, 0, 0, time.UTC)); got != want {
			t.Errorf("UTC %02d:30 golden=%v, want %v", utcHour, got, want)
		}
	}
	if DarDate(time.Date(2026, 10, 28, 21, 30, 0, 0, time.UTC)) != "2026-10-29" {
		t.Fatal("bonus date must be the Dar es Salaam date")
	}
}

func TestFallback(t *testing.T) {
	if next, prefix, ok := Fallback(99, ""); !ok || next != 45 || prefix != "F" {
		t.Fatal("99 falls back to 45")
	}
	if next, prefix, ok := Fallback(45, "PREPAID"); !ok || next != 30 || prefix != "FF" {
		t.Fatal("45 falls back to 30")
	}
	for _, c := range []struct {
		amount int64
		system string
	}{{30, ""}, {99, "WALLET_ACCOUNT"}, {300, ""}} {
		if _, _, ok := Fallback(c.amount, c.system); ok {
			t.Errorf("no fallback expected for %d %q", c.amount, c.system)
		}
	}
}

func TestMessages(t *testing.T) {
	if got := QuestionMessage(425, "Nani alishinda?"); got != "Unapoint 425!Nani alishinda?." {
		t.Fatal(got)
	}
	if got := PointsMessage(90, "255700000001"); got[:17] != "Point zako ni 90." {
		t.Fatal(got)
	}
}

func TestSubscriptionRules(t *testing.T) {
	if !IsStaff("255715123456") || !IsStaff("x255678123") || IsStaff("255700000001") {
		t.Fatal("staff series")
	}
	if !IsReactivation(" SOKA ") || IsReactivation("soka!") {
		t.Fatal("reactivation word")
	}
	if got := WelcomeQuestion(0, "Q"); got != "Una point 0. PAPO HAPO unabahatika na SOKA;Q. Vigezo na masharti bofya http://www.yassports.co.tz/terms-and-conditions" {
		t.Fatal(got)
	}
	if got := ReactivationQuestion(75, "Q"); got != "Una point 75. PAPO HAPO unabahatika na SOKA;Q." {
		t.Fatal(got)
	}
	if got := CreditQuestion(125, 2, "Q"); got != "Unapoint 125!KETE 2 Buree!Q" {
		t.Fatal(got)
	}
	if got := CreditQuestion(125, 0, "Q"); got != "Unapoint 125!Q" {
		t.Fatal(got)
	}
}

func TestOpenQuestionBody(t *testing.T) {
	got := OpenQuestionBody("Alama %s kwa %msisdn. Café", "Nani?", "255700000001", 40)
	if got != "Alama 40 kwa 255700000001. Cafe Nani?" {
		t.Fatal(got)
	}
}

func TestParsePredict(t *testing.T) {
	cases := []struct {
		text string
		step PredictStep
		a, b int
	}{
		{" predict ", PredictEntry, 0, 0},
		{"M2", PredictSlot, 2, 0},
		{"m 12", PredictSlot, 12, 0},
		{"M2-1", PredictScore, 2, 1},
		{"m3 : 0", PredictScore, 3, 0},
		{"M1,4", PredictScore, 1, 4},
		{"M2 1", PredictBadScore, 0, 0},
		{"M2x", PredictOther, 0, 0},
	}
	for _, c := range cases {
		step, a, b := ParsePredict(c.text)
		if step != c.step || a != c.a || b != c.b {
			t.Errorf("ParsePredict(%q) = %v %d %d", c.text, step, a, b)
		}
	}
	if !IsPredictRequest("PD12255") || !IsPredictRequest("pw9") || IsPredictRequest("PU1") || PredictRequestID(7, "255") != "PD7255" {
		t.Fatal("predict request IDs")
	}
}
