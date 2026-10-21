package app

import (
	"strings"
	"testing"
)

func TestValidateParticipantInput(t *testing.T) {
	tests := []struct {
		name    string
		input   participantInput
		create  bool
		wantErr bool
	}{
		{name: "new participant", create: true, input: participantInput{Name: "  Ibu Aminah ", Category: "reguler", Children: []participantChild{{Name: "Ahmad", Class: "Kelas 2"}}}},
		{name: "existing participant may save settings only", input: participantInput{Name: "Ibu Aminah", Category: "askar"}},
		{name: "update retains child identity", input: participantInput{Name: "Ibu Aminah", Children: []participantChild{{ID: 42, Name: "Ahmad", Class: "Kelas 3"}}}},
		{name: "blank new child row is ignored", create: true, input: participantInput{Name: "Ibu Aminah", Children: []participantChild{{Name: "Ahmad", Class: "Kelas 2"}, {}}}},
		{name: "new participant needs a child", create: true, input: participantInput{Name: "Ibu Aminah"}, wantErr: true},
		{name: "invalid category", create: true, input: participantInput{Name: "Ibu Aminah", Category: "other", Children: []participantChild{{Name: "Ahmad"}}}, wantErr: true},
		{name: "negative group", create: true, input: participantInput{Name: "Ibu Aminah", GroupID: -1, Children: []participantChild{{Name: "Ahmad"}}}, wantErr: true},
		{name: "duplicate child id", input: participantInput{Name: "Ibu Aminah", Children: []participantChild{{ID: 7, Name: "Ahmad"}, {ID: 7, Name: "Ahmad"}}}, wantErr: true},
		{name: "invalid control character", input: participantInput{Name: "Ibu\x00Aminah"}, wantErr: true},
		{name: "too many children", input: participantInput{Name: "Ibu Aminah", Children: make([]participantChild, 21)}, wantErr: true},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := validateParticipantInput(test.input, test.create)
			if (err != nil) != test.wantErr {
				t.Fatalf("error = %v, wantErr=%v", err, test.wantErr)
			}
			if err == nil && got.Name != "Ibu Aminah" {
				t.Fatalf("name was not trimmed: %q", got.Name)
			}
			if test.name == "blank new child row is ignored" && len(got.Children) != 1 {
				t.Fatalf("blank row was not ignored: %#v", got.Children)
			}
			if test.name == "update retains child identity" && (len(got.Children) != 1 || got.Children[0].ID != 42) {
				t.Fatalf("child identity was not retained: %#v", got.Children)
			}
		})
	}
}

func TestValidParticipantText(t *testing.T) {
	if !validParticipantText("Jalan Melati\nNomor 3", 100, true) {
		t.Fatal("multiline address was rejected")
	}
	if validParticipantText(strings.Repeat("a", 21), 20, false) {
		t.Fatal("oversized value was accepted")
	}
	if validParticipantText("Nama\nAnak", 20, false) {
		t.Fatal("newline was accepted for a single-line field")
	}
}
