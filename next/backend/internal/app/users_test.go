package app

import (
	"strings"
	"testing"
)

func TestValidateUserInput(t *testing.T) {
	tests := []struct {
		name    string
		input   userInput
		create  bool
		wantErr bool
	}{
		{name: "new ustadz", create: true, input: userInput{Username: " ustadz.baru ", Name: "Ustadz Baru", Role: "ustadz", Password: "aman1234"}},
		{name: "existing password unchanged", input: userInput{Username: "admin", Name: "Administrator", Role: "admin"}},
		{name: "new account needs password", create: true, input: userInput{Username: "user", Name: "User", Role: "kepsek"}, wantErr: true},
		{name: "weak password", create: true, input: userInput{Username: "user", Name: "User", Role: "kepsek", Password: "short"}, wantErr: true},
		{name: "bcrypt input too long", input: userInput{Username: "user", Name: "User", Role: "kepsek", Password: strings.Repeat("x", 73)}, wantErr: true},
		{name: "unknown role", input: userInput{Username: "user", Name: "User", Role: "owner"}, wantErr: true},
		{name: "username too long", input: userInput{Username: strings.Repeat("u", 51), Name: "User", Role: "ustadz"}, wantErr: true},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := validateUserInput(test.input, test.create)
			if (err != nil) != test.wantErr {
				t.Fatalf("error = %v, wantErr=%v", err, test.wantErr)
			}
			if err == nil && test.name == "new ustadz" && got.Username != "ustadz.baru" {
				t.Fatalf("username was not trimmed: %q", got.Username)
			}
		})
	}
}
