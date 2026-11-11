# Temporary T15 live smoke (deleted after use): resident + guard + public visitor flows
# against an isolated `mtaapass_smoke_test` database on 127.0.0.1:8123.
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
& php "$PSScriptRoot\_t15smoke-setup.php"

$tmp = $PSScriptRoot
$bodyFile = "$tmp\_t15smoke-body.tmp"; $hdrFile = "$tmp\_t15smoke-headers.tmp"
$dataFile = "$tmp\_t15smoke-data.tmp"
$jarR = "$tmp\_t15smoke-resident.tmp"; $jarG = "$tmp\_t15smoke-guard.tmp"; $jarD = "$tmp\_t15smoke-david.tmp"; $jarU = "$tmp\_t15smoke-unauth.tmp"
$out = "$tmp\_t15smoke-server.out.tmp"; $err = "$tmp\_t15smoke-server.err.tmp"
$base = 'http://127.0.0.1:8123'

function HttpReq {
    param([string]$Uri,[string]$Method='GET',[string]$Jar='',[hashtable]$Headers=@{},[string]$DataFile='',[string]$DataRaw='',[switch]$CaptureHeaders)
    $cargs = @('-s','-o',$bodyFile,'-w','%{http_code}')
    if ($Jar) { $cargs += @('-b',$Jar,'-c',$Jar) }
    if ($Method -ne 'GET') { $cargs += @('-X',$Method) }
    foreach ($k in $Headers.Keys) { $cargs += @('-H',"${k}: $($Headers[$k])") }
    if (-not [string]::IsNullOrEmpty($DataFile)) { $cargs += @('--data-binary',"@$DataFile") }
    if (-not [string]::IsNullOrEmpty($DataRaw)) { $cargs += @('--data-raw',$DataRaw) }
    if ($CaptureHeaders) { $cargs += @('-D',$hdrFile) }
    $cargs += $Uri
    $status = (& curl.exe @cargs)
    $respBody = ''; $respHeaders = ''
    if (Test-Path $bodyFile) { $respBody = [string](Get-Content -Path $bodyFile -Raw -ErrorAction SilentlyContinue) }
    if ($CaptureHeaders -and (Test-Path $hdrFile)) { $respHeaders = [string](Get-Content -Path $hdrFile -Raw -ErrorAction SilentlyContinue) }
    return [pscustomobject]@{ Status = [int]$status; Body = $respBody; Headers = $respHeaders }
}
function Write-Json([string]$Path,[hashtable]$Payload) { Set-Content -Path $Path -Value ($Payload | ConvertTo-Json -Compress) -NoNewline -Encoding Ascii }
$rows = @()
function Check($name,$cond,$detail) { $script:rows += [pscustomobject]@{ Check = $name; Result = $(if ($cond) { 'PASS' } else { 'FAIL' }); Detail = $detail } }
function FirstMatch([string]$text,[string]$pattern,[int]$group = 1) {
    $m = [regex]::Match($text, $pattern)
    if ($m.Success) { return $m.Groups[$group].Value }
    return ''
}
function Login([string]$phone,[string]$jar) {
    $csrf = HttpReq "$base/api/v1/auth/csrf" -Jar $jar
    $token = [string](($csrf.Body | ConvertFrom-Json).data.csrf_token)
    Write-Json $dataFile @{ phone = $phone }
    $otp = HttpReq "$base/api/v1/auth/request-otp" -Method POST -Jar $jar -Headers @{'X-CSRF-Token' = $token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $code = [string](($otp.Body | ConvertFrom-Json).data.dev_otp)
    Write-Json $dataFile @{ phone = $phone; code = $code }
    $verify = HttpReq "$base/api/v1/auth/verify-otp" -Method POST -Jar $jar -Headers @{'X-CSRF-Token' = $token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $newCsrf = HttpReq "$base/api/v1/auth/csrf" -Jar $jar
    $newToken = [string](($newCsrf.Body | ConvertFrom-Json).data.csrf_token)
    return [pscustomobject]@{ Token = $newToken; Otp = $otp; Verify = $verify }
}

$previousEnv = @{}
foreach ($name in @('APP_ENV','APP_DEBUG','DB_DATABASE','SMS_DRIVER','SMS_DEV_EXPOSE_OTP','UI_PREVIEW_CONTENT','LOG_LEVEL')) {
    $previousEnv[$name] = [System.Environment]::GetEnvironmentVariable($name, 'Process')
}
$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'true'
$env:DB_DATABASE = 'mtaapass_smoke_test'
$env:SMS_DRIVER = 'mock'
$env:SMS_DEV_EXPOSE_OTP = 'true'
$env:UI_PREVIEW_CONTENT = 'true'
$env:LOG_LEVEL = 'error'

$server = Start-Process -FilePath (Get-Command php).Source -ArgumentList '-S','127.0.0.1:8123','-t','public','public/index.php' -WorkingDirectory $root -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
try {
    $ready = $false
    for ($i = 0; $i -lt 40; $i++) { Start-Sleep -Milliseconds 250; if ((HttpReq "$base/health").Status -eq 200) { $ready = $true; break } }
    Check 'server ready on 8123 (isolated smoke DB)' $ready $base
    if (-not $ready) { throw 'smoke server did not start' }

    # ---------------- resident flow ----------------
    $resident = Login '+254700000010' $jarR
    Check 'resident dev OTP login' ($resident.Verify.Status -eq 200) "verify=$($resident.Verify.Status)"

    $homePage = HttpReq "$base/app" -Jar $jarR
    Check 'GET /app 200' ($homePage.Status -eq 200) "status=$($homePage.Status) bytes=$($homePage.Body.Length)"
    Check 'home shows next visitor pass in QR sheet' ($homePage.Body.Contains('Next visitor') -and $homePage.Body.Contains('Demo Visitor Three')) ''
    Check 'home never embeds demo PIN' (-not $homePage.Body.Contains('246810')) ''
    Check 'home has no APP_URL-absolute links' ((-not $homePage.Body.Contains('http://127.0.0.1:8010')) -and (-not $homePage.Body.Contains('http://localhost:8000'))) ''

    $visitors = HttpReq "$base/app/visitors" -Jar $jarR
    Check 'GET /app/visitors 200 real data' ($visitors.Status -eq 200 -and $visitors.Body.Contains('Demo Visitor Three') -and $visitors.Body.Contains('Demo Walk-in Four') -and (-not $visitors.Body.Contains('Layout preview'))) "status=$($visitors.Status)"
    $history = HttpReq "$base/app/visitors?tab=history" -Jar $jarR
    Check 'GET /app/visitors?tab=history 200' ($history.Status -eq 200 -and $history.Body.Contains('Demo Visitor One')) "status=$($history.Status)"
    $invitePage = HttpReq "$base/app/visitors/invite" -Jar $jarR
    Check 'GET /app/visitors/invite 200' ($invitePage.Status -eq 200) "status=$($invitePage.Status)"

    $from = (Get-Date).ToUniversalTime().AddMinutes(-5).ToString("yyyy-MM-ddTHH:mm:ssZ")
    $until = (Get-Date).ToUniversalTime().AddHours(2).ToString("yyyy-MM-ddTHH:mm:ssZ")
    Write-Json $dataFile @{
        visitor_name = 'Smoke Visitor Public'
        visitor_phone = '+254700095555'
        household_uuid = ''
        purpose = 'Live smoke verification'
        valid_from = $from
        valid_until = $until
        visitor_count = 1
    }
    $householdUuid = FirstMatch $invitePage.Body 'name="household_uuid"[^>]*value="([0-9a-f-]{36})"'
    if ($householdUuid -eq '') { $householdUuid = FirstMatch $invitePage.Body '<option value="([0-9a-f-]{36})"' }
    $inviteJson = @{
        visitor_name = 'Smoke Visitor Public'
        visitor_phone = '+254700095555'
        household_uuid = $householdUuid
        purpose = 'Live smoke verification'
        valid_from = $from
        valid_until = $until
        visitor_count = 1
    } | ConvertTo-Json -Compress
    Set-Content -Path $dataFile -Value $inviteJson -NoNewline -Encoding Ascii
    $create = HttpReq "$base/api/v1/resident/invitations" -Method POST -Jar $jarR -Headers @{'X-CSRF-Token' = $resident.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $created = $create.Body | ConvertFrom-Json
    $invitationUuid = [string]$created.data.invitation_uuid
    $newPin = [string]$created.data.pin
    $passUrl = [string]$created.data.pass_url
    Check 'POST resident invitation 201 + pin + pass_url' ($create.Status -eq 201 -and $newPin -match '^[0-9]{6}$' -and $passUrl.Contains('/v/')) "status=$($create.Status) pass_url=$passUrl"

    $passScreen = HttpReq "$base/app/passes/$invitationUuid" -Jar $jarR
    Check 'GET resident pass screen 200' ($passScreen.Status -eq 200 -and $passScreen.Body.Contains('Smoke Visitor Public') -and $passScreen.Body.Contains('/v/')) "status=$($passScreen.Status)"
    $qr = HttpReq "$base/app/passes/$invitationUuid/qr.png" -Jar $jarR -CaptureHeaders
    Check 'GET pass QR PNG 200 image/png no-store' ($qr.Status -eq 200 -and ([string]$qr.Headers).Contains('image/png') -and ([string]$qr.Headers).Contains('no-store')) "status=$($qr.Status)"
    Check 'QR PNG is a real PNG' ($qr.Body.Length -gt 200) "bytes=$($qr.Body.Length)"

    # public pass page (no auth)
    $public = HttpReq ($base + $passUrl) -Jar $jarU
    Check 'public /v/{token} pass page 200' ($public.Status -eq 200 -and $public.Body.Contains('Smoke Visitor Public') -and $public.Body.Contains('Greenview Residences')) "status=$($public.Status)"
    Check 'public pass page hides PIN and resident PII' ((-not $public.Body.Contains($newPin)) -and (-not $public.Body.Contains('+254700000010')) -and (-not $public.Body.Contains('Jane Smith'))) ''

    # ---------------- guard flow ----------------
    $guard = Login '+254700000004' $jarG
    Check 'guard dev OTP login' ($guard.Verify.Status -eq 200) "verify=$($guard.Verify.Status)"
    $dashPage = HttpReq "$base/gate" -Jar $jarG
    Check 'GET /gate guard dashboard 200' ($dashPage.Status -eq 200) "status=$($dashPage.Status)"
    $gateUuid = FirstMatch $dashPage.Body 'name="gate_uuid" value="([0-9a-f-]{36})"'
    if ($gateUuid -eq '') { $gateUuid = FirstMatch $dashPage.Body '<option value="([0-9a-f-]{36})"' }
    Check 'guard dashboard exposes assigned Main Gate' ($gateUuid -ne '') "gate=$gateUuid"

    $select = HttpReq "$base/gate/select-gate" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token } -DataRaw "gate_uuid=$gateUuid"
    Check 'POST /gate/select-gate' ($select.Status -eq 302) "status=$($select.Status)"

    Write-Json $dataFile @{ pin = '246810' }
    $verify = HttpReq "$base/api/v1/gate/verify-pin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $verifyData = $verify.Body | ConvertFrom-Json
    $demoInvitationUuid = [string]$verifyData.data.invitation_uuid
    $demoCredentialUuid = [string]$verifyData.data.credential_uuid
    Check 'guard verifies seeded demo PIN 246810' ($verify.Status -eq 200 -and $verifyData.data.visitor_name -eq 'Demo Visitor Three') "status=$($verify.Status) visitor=$($verifyData.data.visitor_name)"

    # The seeded demo pass is valid tomorrow 10:00-14:00, so a check-in today must
    # be refused as OUTSIDE_VALIDITY (verification for display still succeeds).
    Write-Json $dataFile @{ invitation_uuid = $demoInvitationUuid; credential_uuid = $demoCredentialUuid }
    $demoCheckin = HttpReq "$base/api/v1/gate/visitors/checkin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    Check 'demo pass outside window -> 422 OUTSIDE_VALIDITY' ($demoCheckin.Status -eq 422 -and ($demoCheckin.Body | ConvertFrom-Json).error.code -eq 'OUTSIDE_VALIDITY') "status=$($demoCheckin.Status)"

    # Check-in/check-out the invitation created above (window already open).
    Write-Json $dataFile @{ pin = $newPin }
    $liveVerify = HttpReq "$base/api/v1/gate/verify-pin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $liveData = $liveVerify.Body | ConvertFrom-Json
    $liveCredentialUuid = [string]$liveData.data.credential_uuid
    Check 'guard verifies freshly created PIN' ($liveVerify.Status -eq 200 -and $liveData.data.visitor_name -eq 'Smoke Visitor Public') "status=$($liveVerify.Status)"

    Write-Json $dataFile @{ invitation_uuid = $invitationUuid; credential_uuid = $liveCredentialUuid }
    $checkin = HttpReq "$base/api/v1/gate/visitors/checkin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    $checkinData = $checkin.Body | ConvertFrom-Json
    Check 'guard check-in 200 (live invitation)' ($checkin.Status -eq 200 -and $checkinData.data.status -eq 'checked_in') "status=$($checkin.Status)"
    $second = HttpReq "$base/api/v1/gate/visitors/checkin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    Check 'second check-in 409 ALREADY_INSIDE' ($second.Status -eq 409 -and ($second.Body | ConvertFrom-Json).error.code -eq 'ALREADY_INSIDE') "status=$($second.Status)"

    $activePage = HttpReq "$base/gate/active" -Jar $jarG
    Check 'GET /gate/active lists the visitor' ($activePage.Status -eq 200 -and $activePage.Body.Contains('Smoke Visitor Public')) "status=$($activePage.Status)"

    Write-Json $dataFile @{ invitation_uuid = $invitationUuid }
    $checkout = HttpReq "$base/api/v1/gate/visitors/checkout" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    Check 'guard checkout 200' ($checkout.Status -eq 200 -and ($checkout.Body | ConvertFrom-Json).data.status -eq 'checked_out') "status=$($checkout.Status)"
    $secondOut = HttpReq "$base/api/v1/gate/visitors/checkout" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
    Check 'second checkout 409 NOT_INSIDE' ($secondOut.Status -eq 409 -and ($secondOut.Body | ConvertFrom-Json).error.code -eq 'NOT_INSIDE') "status=$($secondOut.Status)"

    $verifyPage = HttpReq "$base/gate/verify" -Jar $jarG
    Check 'GET /gate/verify noscript form (name=code)' ($verifyPage.Status -eq 200 -and $verifyPage.Body.Contains('name="code"')) "status=$($verifyPage.Status)"

    # ---------------- walk-in approve flow ----------------
    $lists = HttpReq "$base/api/v1/resident/invitations" -Jar $jarR
    $listsData = $lists.Body | ConvertFrom-Json
    $walkin = @($listsData.data.pending_walkins) | Select-Object -First 1
    Check 'resident sees seeded pending walk-in' ($walkin -ne $null -and $walkin.visitor_name -eq 'Demo Walk-in Four') "walkins=$(@($listsData.data.pending_walkins).Count)"
    if ($walkin -ne $null) {
        $approve = HttpReq "$base/api/v1/resident/walkins/$([string]$walkin.uuid)/approve" -Method POST -Jar $jarR -Headers @{'X-CSRF-Token' = $resident.Token; 'Content-Type' = 'application/json' }
        $approveData = $approve.Body | ConvertFrom-Json
        Check 'resident approves walk-in 200 + invitation' ($approve.Status -eq 200 -and $approveData.data.status -eq 'approved' -and $approveData.data.invitation_uuid) "status=$($approve.Status)"
        $walkinPin = [string]$approveData.data.pin
        if ($walkinPin -ne '') {
            Write-Json $dataFile @{ pin = $walkinPin }
            $walkinVerify = HttpReq "$base/api/v1/gate/verify-pin" -Method POST -Jar $jarG -Headers @{'X-CSRF-Token' = $guard.Token; 'Content-Type' = 'application/json' } -DataFile $dataFile
            Check 'guard verifies approved walk-in PIN' ($walkinVerify.Status -eq 200 -and ($walkinVerify.Body | ConvertFrom-Json).data.visitor_name -eq 'Demo Walk-in Four') "status=$($walkinVerify.Status)"
        }
    }

    # ---------------- security spot checks ----------------
    $csrfMissing = HttpReq "$base/api/v1/resident/invitations" -Method POST -Jar $jarR -Headers @{'Content-Type' = 'application/json' } -DataFile $dataFile
    Check 'POST without CSRF -> 403 CSRF_TOKEN_INVALID' ($csrfMissing.Status -eq 403 -and ($csrfMissing.Body | ConvertFrom-Json).error.code -eq 'CSRF_TOKEN_INVALID') "status=$($csrfMissing.Status)"

    $david = Login '+254700000014' $jarD
    $foreignPass = HttpReq "$base/app/passes/$demoInvitationUuid" -Jar $jarD
    Check 'Riverside admin cannot read Greenview pass (403/404)' ($foreignPass.Status -eq 403 -or $foreignPass.Status -eq 404) "status=$($foreignPass.Status)"
    $foreignQr = HttpReq "$base/app/passes/$demoInvitationUuid/qr.png" -Jar $jarD
    Check 'Riverside admin cannot fetch Greenview QR (403/404)' ($foreignQr.Status -eq 403 -or $foreignQr.Status -eq 404) "status=$($foreignQr.Status)"

    $unauthVisitors = HttpReq "$base/app/visitors" -Jar $jarU
    Check 'unauth /app/visitors -> 302 /login' ($unauthVisitors.Status -eq 302) "status=$($unauthVisitors.Status)"
    $unauthPublic = HttpReq ($base + $passUrl) -Jar $jarU
    Check 'public pass page stays public without auth' ($unauthPublic.Status -eq 200) "status=$($unauthPublic.Status)"
} catch {
    Check 'SMOKE SCRIPT' $false ($_.Exception.Message + ' | ' + $_.InvocationInfo.PositionMessage)
} finally {
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 400
    foreach ($name in $previousEnv.Keys) {
        if ($null -eq $previousEnv[$name]) { Remove-Item "Env:\$name" -ErrorAction SilentlyContinue } else { Set-Item "Env:\$name" $previousEnv[$name] }
    }
    & php "$PSScriptRoot\_t15smoke-teardown.php"
}
$rows | Format-Table -AutoSize | Out-String -Width 220
$failed = @($rows | Where-Object Result -eq 'FAIL').Count
"SMOKE: $($rows.Count) checks, $($rows.Count - $failed) passed, $failed failed"
foreach ($f in @($bodyFile,$hdrFile,$dataFile,$jarR,$jarG,$jarD,$jarU,$out,$err)) { Remove-Item $f -ErrorAction SilentlyContinue }
exit $failed
