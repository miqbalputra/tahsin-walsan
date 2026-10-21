package main

import "testing"

func TestValidateStagingEnvironment(t *testing.T) {
	tests := []struct {
		name string
		env  map[string]string
		want bool
	}{
		{name: "normal deployment unaffected", env: map[string]string{"DB_DATABASE": "db_tahsinbpk"}},
		{name: "synthetic staging accepted", env: map[string]string{"TAHSIN_STAGING_ONLY": "1", "DB_DATABASE": "tahsin_test_autosave_restore_20261022", "TAHSIN_NEXT_DB_DATABASE": "tahsin_test_autosave_restore_20261022"}},
		{name: "production database rejected", env: map[string]string{"TAHSIN_STAGING_ONLY": "1", "DB_DATABASE": "db_tahsinbpk"}, want: true},
		{name: "different Go database rejected", env: map[string]string{"TAHSIN_STAGING_ONLY": "1", "DB_DATABASE": "tahsin_test_autosave_restore", "TAHSIN_NEXT_DB_DATABASE": "db_tahsinbpk"}, want: true},
		{name: "writer rejected", env: map[string]string{"TAHSIN_STAGING_ONLY": "1", "DB_DATABASE": "tahsin_test_autosave_restore", "TAHSIN_NEXT_WRITE_ENABLED": "1"}, want: true},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			err := validateStagingEnvironment(func(key string) string { return test.env[key] })
			if (err != nil) != test.want {
				t.Fatalf("validateStagingEnvironment() error = %v, want error %t", err, test.want)
			}
		})
	}
}
