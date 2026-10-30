<?php
/** Deterministic isolated router for weekly reminder HTTP checks. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('TAHSIN_TEST_NOW', '2026-10-19 10:00:00');
return require dirname(__DIR__, 2) . '/dev-router.php';
