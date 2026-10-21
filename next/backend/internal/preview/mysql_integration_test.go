package preview

import (
	"context"
	"os"
	"strings"
	"testing"
	"time"
)

func TestMySQLReadOnlyIntegration(t *testing.T) {
	if os.Getenv("TAHSIN_NEXT_MYSQL_TEST") != "1" {
		t.Skip("requires isolated localhost synthetic fixture")
	}
	name := os.Getenv("TAHSIN_NEXT_DB_DATABASE")
	if os.Getenv("TAHSIN_NEXT_DB_HOST") != "127.0.0.1" || os.Getenv("TAHSIN_NEXT_DB_PORT") != "13317" || !strings.HasPrefix(name, "tahsin_test_autosave_") {
		t.Fatal("isolated localhost test database required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	s, err := OpenMySQL(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer s.DB.Close()
	for _, scope := range []string{"active", "all"} {
		p, err := s.List(ctx, Filters{Page: 1, Limit: 100, Scope: scope})
		if err != nil || p.Total < 1 {
			t.Fatalf("read failed: %v", err)
		}
		if p.Schema != os.Getenv("TAHSIN_NEXT_TEST_SCHEMA") {
			t.Fatalf("unexpected schema: %s", p.Schema)
		}
		if p.Schema == "family" {
			if (scope == "active" && p.Total != 1) || (scope == "all" && p.Total != 2) {
				t.Fatal("school status filtering failed")
			}
			for _, child := range p.Students {
				if len(child.Guardians) != 2 || !strings.HasPrefix(child.NIS, "0000") {
					t.Fatal("shared family identity or NIS changed")
				}
			}
		}
	}
	p, err := s.List(ctx, Filters{Page: 1, Limit: 25, Scope: "all", Search: "' OR 1=1 --"})
	if err != nil || p.Total != 0 {
		t.Fatal("query parameters were not isolated")
	}
	if _, err = s.DB.ExecContext(ctx, "UPDATE wali_santri SET nama_bapak='must-never-change' WHERE id=1"); err == nil {
		t.Fatal("database user has write access")
	}
	if _, err = s.DB.ExecContext(ctx, "CREATE TABLE must_never_exist(id INT)"); err == nil {
		t.Fatal("database user has DDL access")
	}
	t.Setenv("TAHSIN_NEXT_DB_USERNAME", "root")
	t.Setenv("TAHSIN_NEXT_DB_PASSWORD", "tahsin_local_test")
	if unsafe, err := OpenMySQL(ctx); err == nil {
		unsafe.DB.Close()
		t.Fatal("privileged database account accepted")
	}
}
