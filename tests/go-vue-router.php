<?php
/** PHP development server only; always hard-wires the isolated synthetic DB. */
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$db=getenv('TAHSIN_TEST_DB_NAME');
if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',(string)$db)){http_response_code(500);exit('Synthetic fixture required');}
putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=13317');putenv('DB_DATABASE='.$db);putenv('DB_USERNAME=root');putenv('DB_PASSWORD=tahsin_local_test');
putenv('APP_URL=http://'.$_SERVER['HTTP_HOST']);putenv('N8N_API_KEY=tahsin-test-api');putenv('HERMES_API_KEY=tahsin-test-api');
putenv('TAHSIN_NEXT_ENABLED='.((($_SERVER['HTTP_X_TEST_NEXT_DISABLED']??'')==='1')?'0':'1'));
putenv('TAHSIN_NEXT_API_URL=http://127.0.0.1:18090');putenv('TAHSIN_NEXT_SHARED_SECRET=0123456789abcdef0123456789abcdef');
return false;
