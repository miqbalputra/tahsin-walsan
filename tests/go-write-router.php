<?php
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$database=(string)getenv('TAHSIN_TEST_DB_NAME');if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',$database)){http_response_code(500);exit('Synthetic fixture required');}
putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=13319');putenv('DB_DATABASE='.$database);putenv('DB_USERNAME=php_w_'.substr(hash('sha256',$database),0,10));putenv('DB_PASSWORD=tahsin_writer_test');
putenv('APP_URL=http://'.$_SERVER['HTTP_HOST']);putenv('TAHSIN_NEXT_ENABLED=1');
putenv('DATA_INDUK_ENABLED=0');
putenv('TAHSIN_NEXT_API_URL=http://127.0.0.1:18092');putenv('TAHSIN_NEXT_SHARED_SECRET=0123456789abcdef0123456789abcdef');
putenv('TAHSIN_NEXT_WRITE_ENABLED='.(($_SERVER['HTTP_X_TEST_WRITER_DISABLED']??'')==='1'?'0':'1'));
putenv('TAHSIN_NEXT_WRITE_HALAQOH_IDS=1,3');putenv('TAHSIN_NEXT_WRITE_SHARED_SECRET=staging-writer-secret-0123456789abcdef');
putenv('TAHSIN_VUE_TEACHER_ENABLED=1');putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=3');
return false;
