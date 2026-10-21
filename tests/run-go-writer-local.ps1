param([ValidateSet('http','browser')][string]$Suite='http')
$ErrorActionPreference='Stop'
$taskRoot=Split-Path -Parent $PSScriptRoot
$taskFixturePath=Join-Path $taskRoot '.sessions/go-write-fixture.json'
$taskFixture=Get-Content -LiteralPath $taskFixturePath -Raw|ConvertFrom-Json
if($taskFixture.database -notmatch '^tahsin_test_autosave_[a-z0-9_]+$'){throw 'Synthetic fixture required'}
if(@(Get-NetTCPConnection -State Listen -LocalPort 18086,18092 -ErrorAction SilentlyContinue).Count -gt 0){throw 'Refusing to reuse occupied localhost test ports'}
$taskPhp=(Get-Command php).Source
$taskEnvironment=@{
 TAHSIN_NEXT_ENABLED='1';TAHSIN_NEXT_DATA_SOURCE='mysql';TAHSIN_NEXT_SHARED_SECRET='0123456789abcdef0123456789abcdef';TAHSIN_NEXT_LISTEN='127.0.0.1:18092';TAHSIN_NEXT_TEACHER_READ_ENABLED='0'
 TAHSIN_NEXT_DB_HOST='127.0.0.1';TAHSIN_NEXT_DB_PORT='13319';TAHSIN_NEXT_DB_DATABASE=$taskFixture.database;TAHSIN_NEXT_DB_USERNAME=$taskFixture.read_user;TAHSIN_NEXT_DB_PASSWORD='tahsin_writer_test';TAHSIN_NEXT_DB_TLS='false'
 TAHSIN_NEXT_WRITE_ENABLED='1';TAHSIN_NEXT_WRITE_SHARED_SECRET='staging-writer-secret-0123456789abcdef';TAHSIN_NEXT_WRITE_HALAQOH_IDS='1,3'
 TAHSIN_NEXT_WRITE_DB_HOST='127.0.0.1';TAHSIN_NEXT_WRITE_DB_PORT='13319';TAHSIN_NEXT_WRITE_DB_DATABASE=$taskFixture.database;TAHSIN_NEXT_WRITE_DB_USERNAME=$taskFixture.go_user;TAHSIN_NEXT_WRITE_DB_PASSWORD='tahsin_writer_test';TAHSIN_NEXT_WRITE_DB_TLS='false'
 TAHSIN_TEST_DB_NAME=$taskFixture.database;TAHSIN_TEST_PHP_BINARY=$taskPhp;TAHSIN_WRITE_BRIDGE_FILE=(Join-Path $taskRoot 'tests/go-write-bridge.php');TAHSIN_WRITE_FIXTURE_FILE=$taskFixturePath
 TAHSIN_TEST_URL='http://127.0.0.1:18086';TAHSIN_TEST_DATE=$taskFixture.date
}
$taskPrevious=@{};$taskServices=@();Push-Location $taskRoot
try{
 foreach($key in $taskEnvironment.Keys){$taskPrevious[$key]=[Environment]::GetEnvironmentVariable($key,'Process');[Environment]::SetEnvironmentVariable($key,$taskEnvironment[$key],'Process')}
 Push-Location 'next/backend';try{go build -o '../../.sessions/go-writer-test.exe' ./cmd/server;if($LASTEXITCODE -ne 0){throw 'Go build failed'}}finally{Pop-Location}
 if($Suite -eq 'browser'){
  $taskTransfer=@{action='transfer';group=3;date=$taskFixture.date;owner='go'}|ConvertTo-Json -Compress
  $taskResult=$taskTransfer|& $taskPhp -d extension=php_pdo_mysql.dll tests/go-write-bridge.php $taskFixture.database
  if(($taskResult|ConvertFrom-Json).status -ne 200){throw 'Synthetic ownership transfer failed'}
 }
 $taskServices+=Start-Process -FilePath (Join-Path $taskRoot '.sessions/go-writer-test.exe') -WindowStyle Hidden -RedirectStandardOutput '.sessions/writer-go.stdout' -RedirectStandardError '.sessions/writer-go.stderr' -PassThru
 $taskServices+=Start-Process -FilePath $taskPhp -ArgumentList @('-d','extension=php_pdo_mysql.dll','-S','127.0.0.1:18086','-t','.','tests/go-write-router.php') -WorkingDirectory $taskRoot -WindowStyle Hidden -RedirectStandardOutput '.sessions/writer-php.stdout' -RedirectStandardError '.sessions/writer-php.stderr' -PassThru
 foreach($taskPort in @(18086,18092)){
  $taskReady=$false
  for($taskAttempt=0;$taskAttempt -lt 50;$taskAttempt++){
   try{$taskClient=[System.Net.Sockets.TcpClient]::new('127.0.0.1',$taskPort);$taskClient.Dispose();$taskReady=$true;break}catch{Start-Sleep -Milliseconds 100}
  }
  if(-not $taskReady){throw "Owned test service on $taskPort failed to start"}
 }
 if($Suite -eq 'http'){node tests/go-write-http.cjs}else{node tests/autosave-browser.cjs}
 if($LASTEXITCODE -ne 0){throw 'Writer application checks failed'}
}finally{
 foreach($taskService in $taskServices){if(-not $taskService.HasExited){Stop-Process -Id $taskService.Id}}
 foreach($key in $taskPrevious.Keys){[Environment]::SetEnvironmentVariable($key,$taskPrevious[$key],'Process')}
 Pop-Location
}
