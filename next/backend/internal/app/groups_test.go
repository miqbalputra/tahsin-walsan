package app

import (
	"strings"
	"testing"
)

func TestValidateGroupInput(t *testing.T) {
	tests := []struct {
		name    string
		input   groupInput
		wantErr bool
	}{
		{name: "valid father group", input: groupInput{Name: "  Ahad Pagi  ", TeacherID: 4, Program: "bapak"}},
		{name: "valid mother group", input: groupInput{Name: "Kelompok Ibu", TeacherID: 9, Program: "ibu"}},
		{name: "blank name", input: groupInput{Name: "  ", TeacherID: 4, Program: "bapak"}, wantErr: true},
		{name: "too long name", input: groupInput{Name: strings.Repeat("a", 101), TeacherID: 4, Program: "bapak"}, wantErr: true},
		{name: "no teacher", input: groupInput{Name: "Kelompok", Program: "bapak"}, wantErr: true},
		{name: "invalid program", input: groupInput{Name: "Kelompok", TeacherID: 4, Program: "campur"}, wantErr: true},
		{name: "line breaks rejected", input: groupInput{Name: "Kelompok\nLain", TeacherID: 4, Program: "bapak"}, wantErr: true},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := validateGroupInput(test.input)
			if (err != nil) != test.wantErr {
				t.Fatalf("error = %v, wantErr %v", err, test.wantErr)
			}
			if err == nil && got.Name != "Ahad Pagi" && got.Name != "Kelompok Ibu" {
				t.Fatalf("name was not trimmed: %q", got.Name)
			}
		})
	}
}
