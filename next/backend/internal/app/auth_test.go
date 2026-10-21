package app

import (
	"strings"
	"testing"

	"golang.org/x/crypto/bcrypt"
)

func TestPHPBcryptPrefixesVerifyWithoutRewritingHashes(t *testing.T) {
	stored, err := bcrypt.GenerateFromPassword([]byte("test-password"), bcrypt.MinCost)
	if err != nil {
		t.Fatal(err)
	}
	for _, prefix := range []string{"$2a$", "$2b$", "$2y$"} {
		hash := prefix + strings.TrimPrefix(string(stored), "$2a$")
		if !verifyPassword("test-password", hash) {
			t.Fatalf("valid bcrypt variant %s did not verify", prefix)
		}
		if compatiblePHPHash(hash) == hash && prefix != "$2a$" {
			t.Fatalf("expected in-memory compatibility for %s", prefix)
		}
		if hash[:4] != prefix {
			t.Fatal("stored hash value unexpectedly changed")
		}
	}
	if verifyPassword("wrong-password", string(stored)) {
		t.Fatal("incorrect password verified")
	}
}
