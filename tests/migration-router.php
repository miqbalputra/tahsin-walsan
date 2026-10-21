<?php
/** Synthetic local migration pilot only; no production env or database fallback. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$database = (string)getenv('TAHSIN_TEST_DB_NAME');
if (!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D', $database)) { http_response_code(500); exit('Synthetic fixture required.'); }
putenv('DB_HOST=127.0.0.1'); putenv('DB_PORT=13319'); putenv('DB_DATABASE=' . $database);
putenv('DB_USERNAME=root'); putenv('DB_PASSWORD=tahsin_local_test');
putenv('APP_URL=http://' . $_SERVER['HTTP_HOST']);
putenv('TAHSIN_NEXT_ENABLED=0');
putenv('TAHSIN_VUE_TEACHER_ENABLED=' . (($_SERVER['HTTP_X_TEST_VUE_DISABLED'] ?? '') === '1' ? '0' : '1'));
putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=3');
return false;
