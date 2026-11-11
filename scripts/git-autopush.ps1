<#
.SYNOPSIS
    MtaaPass git auto-commit / auto-push watcher.

.DESCRIPTION
    Watches the MtaaPass workspace and, after the working tree has been quiet for
    a configurable period, commits every pending change and pushes it to GitHub.
    It is meant to run as a long-lived background process next to the PHP dev
    server so that every update to the running localhost app is published.

    Safety properties (see docs/git-release-process.md):
      * never force-pushes and never rewrites history;
      * never stages `.env*`, runtime storage, `.tools/` or credential-shaped files;
      * aborts a commit when staged content looks like a secret;
      * debounces concurrent writers (default 60s of quiet) so commits stay coherent;
      * never touches `.git/index.lock` while another git process is using it;
      * fails soft on network errors: commits stay local and are pushed next pass.

.PARAMETER IntervalSeconds
    Seconds between status polls. Default 25 (clamped to >= 5).

.PARAMETER QuietSeconds
    Seconds the working tree must stay unchanged before a commit is made.
    Default 60 (clamped to >= 10).

.PARAMETER Branch
    Branch to commit and push. Default `main`. Must match the checked-out branch.

.PARAMETER Remote
    Git remote to push to. Default `origin`.

.PARAMETER Once
    Run exactly one pass and exit. In this manual mode the current working tree
    is committed immediately (no debounce). Useful for one-off syncs / CI.

.PARAMETER NoPush
    Commit locally but never push (also skips remote checks). Pushes can be
    performed later by a watcher started without -NoPush.

.PARAMETER LogPath
    Log file, relative to the repo root. Default `storage/logs/git-sync.log`
    (gitignored).

.EXAMPLE
    pwsh -NoProfile -File scripts\git-autopush.ps1 -Once
    One manual commit + push of the current working tree.

.EXAMPLE
    pwsh -NoProfile -File scripts\git-autopush.ps1 -IntervalSeconds 20 -QuietSeconds 60
    Long-running watcher (the durable background service).

.NOTES
    Exit codes: 0 = normal/soft failure, 1 = configuration/tooling error,
    2 = remote history divergence (watcher stops, no force-push),
    3 = checked-out branch does not match -Branch.
#>
[CmdletBinding()]
param(
    [int]$IntervalSeconds = 25,
    [int]$QuietSeconds = 60,
    [string]$Branch = 'main',
    [string]$Remote = 'origin',
    [switch]$Once,
    [switch]$NoPush,
    [string]$LogPath = 'storage/logs/git-sync.log'
)

Set-StrictMode -Off
$ErrorActionPreference = 'Continue'

# ---------------------------------------------------------------------------
# Locate the repository root. The script lives in <repo>/scripts.
# ---------------------------------------------------------------------------
$script:RepoRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($script:RepoRoot)) { $script:RepoRoot = (Get-Location).Path }
$parent = Split-Path -Parent $script:RepoRoot
if ($parent -and (Test-Path -LiteralPath (Join-Path $parent '.git'))) { $script:RepoRoot = $parent }
$script:RepoRoot = (Resolve-Path -LiteralPath $script:RepoRoot).Path

if ($IntervalSeconds -lt 5) { $IntervalSeconds = 5 }
if ($QuietSeconds -lt 10) { $QuietSeconds = 10 }

if ($LogPath -match '^[A-Za-z]:[\\/]' -or $LogPath.StartsWith('\\')) {
    $script:LogFile = $LogPath
} else {
    $script:LogFile = Join-Path $script:RepoRoot $LogPath
}

$script:TempDir = Join-Path $script:RepoRoot 'storage\temporary'
if (-not (Test-Path -LiteralPath $script:TempDir)) {
    New-Item -ItemType Directory -Force -Path $script:TempDir | Out-Null
}

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------
$script:Throttle = @{}

function Write-SyncLog {
    param([string]$Message, [string]$Level = 'INFO')
    $line = '[{0}] [{1}] [pid {2}] {3}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $PID, $Message
    try {
        $dir = Split-Path -Parent $script:LogFile
        if ($dir -and -not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        Add-Content -LiteralPath $script:LogFile -Value $line -Encoding utf8 -ErrorAction SilentlyContinue
    } catch { }
    Write-Host $line
}

function Write-SyncLogThrottled {
    param([string]$Key, [int]$Seconds, [string]$Level, [string]$Message)
    $now = Get-Date
    if ($script:Throttle.ContainsKey($Key) -and ($now - $script:Throttle[$Key]).TotalSeconds -lt $Seconds) { return }
    $script:Throttle[$Key] = $now
    Write-SyncLog -Message $Message -Level $Level
}

function Get-FirstLine {
    param([string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) { return '' }
    $first = ($Text -split "`r?`n" | Where-Object { $_.Trim().Length -gt 0 } | Select-Object -First 1)
    if ([string]::IsNullOrWhiteSpace($first)) { return '' }
    if ($first.Length -gt 200) { return $first.Substring(0, 200) + '...' }
    return $first.Trim()
}

# ---------------------------------------------------------------------------
# Tool discovery / self-healing
# ---------------------------------------------------------------------------
function Resolve-GitBinary {
    $candidates = @(
        (Join-Path $script:RepoRoot '.tools\git\cmd\git.exe'),
        (Join-Path $script:RepoRoot '.tools\git\bin\git.exe')
    )
    foreach ($c in $candidates) {
        if (Test-Path -LiteralPath $c) { return $c }
    }
    $cmd = Get-Command git -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    return $null
}

function Ensure-SshWrapper {
    $dir = Join-Path $script:RepoRoot '.tools\ssh'
    $wrapper = Join-Path $dir 'git-ssh-wrapper.cmd'
    if (-not (Test-Path -LiteralPath $wrapper)) {
        if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        $template = @(
            '@echo off',
            'rem MtaaPass git SSH wrapper. Generated by scripts/git-autopush.ps1 when missing.',
            'rem It must NOT be committed (lives under gitignored .tools/).',
            'rem It invokes OpenSSH directly because the DSH sandbox blocks MSYS sh.exe.',
            'rem To switch deploy keys, edit the -i path below.',
            '"C:\Windows\System32\OpenSSH\ssh.exe" -i "%USERPROFILE%\.ssh\id_ed25519_bodabora" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 %*'
        )
        Set-Content -LiteralPath $wrapper -Value $template -Encoding ASCII
        Write-SyncLog -Message "created SSH wrapper: $wrapper" -Level 'INFO'
    }
    return $wrapper
}

# ---------------------------------------------------------------------------
# Git invocation helper. stderr is merged and unwrapped so that progress
# messages ("remote: ...", "fatal: ...") never crash the pipeline and can be
# matched reliably. Works on Windows PowerShell 5.1 (no pwsh required).
# ---------------------------------------------------------------------------
function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [switch]$AllowFailure
    )
    $captured = & $script:Git @Arguments 2>&1 | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) { $_.Exception.Message } else { [string]$_ }
    }
    $code = $LASTEXITCODE
    $text = ''
    if ($captured) { $text = ((@($captured)) -join "`n").Trim() }
    if ($code -ne 0 -and -not $AllowFailure) {
        throw ("git {0} failed (exit {1}): {2}" -f ($Arguments -join ' '), $code, (Get-FirstLine $text))
    }
    return [pscustomobject]@{ Code = $code; Output = $text }
}

# ---------------------------------------------------------------------------
# Working-tree helpers
# ---------------------------------------------------------------------------
function Get-StatusLines {
    $r = Invoke-Git -Arguments @('status', '--porcelain=v1', '--untracked-files=all') -AllowFailure
    if ($r.Code -ne 0 -or [string]::IsNullOrWhiteSpace($r.Output)) { return @() }
    return @($r.Output -split "`r?`n" | Where-Object { $_.Length -gt 0 })
}

function Get-StatusPath {
    param([string]$Line)
    if ($Line.Length -le 3) { return $Line.Trim() }
    $p = $Line.Substring(3)
    $arrow = $p.IndexOf(' -> ')
    if ($arrow -ge 0) { $p = $p.Substring($arrow + 4) }
    return $p.Trim().Trim('"')
}

function Test-ExcludedPath {
    param([string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path)) { return $false }
    $p = $Path -replace '\\', '/'
    if ($p -match '^\.env($|\.)' -and $p -notmatch '^\.env\.example$') { return $true }
    if ($p -match '^storage/(logs|temporary|uploads|exports|cache)(/|$)') { return $true }
    if ($p -match '^\.tools(/|$)' -or $p -match '^\.git(/|$)') { return $true }
    $leaf = ($p -split '/')[-1]
    if ($leaf -eq '.env.example') { return $false }
    if ($leaf -match '^\.env') { return $true }
    if ($leaf -match '^id_(rsa|ed25519|ecdsa|dsa)(_[^.]*)?$') { return $true }
    if ($leaf -match '\.(pem|key|pfx|p12|jks|keystore|ppk|ovpn|log)$') { return $true }
    if ($leaf -match '^(credentials?|secrets?)(\.[a-z0-9]+)?$') { return $true }
    if ($leaf -match '^(git-ssh-wrapper\.cmd|\.npmrc|\.netrc|\.pgpass|\.htpasswd)$') { return $true }
    return $false
}

function New-CommitMessage {
    param([string[]]$StatusLines)
    $files = @()
    foreach ($line in $StatusLines) {
        $status = ''
        if ($line.Length -ge 2) { $status = $line.Substring(0, 2).Trim() }
        $files += [pscustomobject]@{ Status = $status; Path = (Get-StatusPath $line) }
    }
    # Group changed paths by top-level area (works on Windows PowerShell 5.1).
    $areaCounts = [ordered]@{}
    foreach ($f in $files) {
        $p = $f.Path -replace '\\', '/'
        $area = '(root)'
        if ($p -match '/') { $area = ($p -split '/')[0] + '/' }
        if ($areaCounts.Contains($area)) { $areaCounts[$area] = $areaCounts[$area] + 1 }
        else { $areaCounts[$area] = 1 }
    }

    $when = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $subject = 'chore(auto-sync): {0} - {1} file(s) changed' -f $when, $files.Count

    $body = @()
    $body += ('Auto-committed by scripts/git-autopush.ps1 after {0}s of quiet (poll interval {1}s).' -f $QuietSeconds, $IntervalSeconds)
    $body += ''
    $body += 'Areas:'
    foreach ($area in $areaCounts.Keys) { $body += ('  {0} {1} file(s)' -f $area, $areaCounts[$area]) }
    $body += ''
    $body += 'Changed paths (capped at 15):'
    $max = 15
    $i = 0
    foreach ($f in $files) {
        if ($i -ge $max) { break }
        $body += ('  [{0}] {1}' -f $f.Status.PadRight(2), $f.Path)
        $i++
    }
    if ($files.Count -gt $max) { $body += ('  ... and {0} more file(s)' -f ($files.Count - $max)) }

    return [pscustomobject]@{ Subject = $subject; Body = ($body -join "`n") }
}

# ---------------------------------------------------------------------------
# Secret / credential screening
# ---------------------------------------------------------------------------
function Get-SecretFindings {
    $findings = @()

    # 1. Exact application key value (read from the gitignored .env, never staged).
    $appKey = $null
    $envFile = Join-Path $script:RepoRoot '.env'
    if (Test-Path -LiteralPath $envFile) {
        $line = Get-Content -LiteralPath $envFile -ErrorAction SilentlyContinue |
            Where-Object { $_ -match '^\s*APP_KEY\s*=' } | Select-Object -First 1
        if ($line -and $line -match '^\s*APP_KEY\s*=\s*(.+)$') {
            $value = $Matches[1].Trim().Trim('"').Trim("'")
            if ($value -and $value.Length -ge 12) { $appKey = $value }
        }
    }
    if ($appKey) {
        $g = Invoke-Git -Arguments @('grep', '--cached', '-I', '-F', '-n', '-e', $appKey, '--', '.') -AllowFailure
        if ($g.Code -eq 0 -and $g.Output) {
            foreach ($l in ($g.Output -split "`r?`n")) {
                if ($l) { $findings += ('APP_KEY value leaked into staged content: {0}' -f $l) }
            }
        }
    }

    # 2. Credential-shaped lines added to the index.
    $diff = Invoke-Git -Arguments @('diff', '--cached', '--unified=0', '--no-color', '--no-ext-diff', '--', '.') -AllowFailure
    if ($diff.Code -eq 0 -and $diff.Output) {
        $added = @($diff.Output -split "`r?`n" | Where-Object { $_ -match '^\+' -and $_ -notmatch '^\+\+\+' })
        $patterns = @(
            @{ Name = 'private-key-header'; Regex = 'BEGIN [A-Z0-9 ]*PRIVATE KEY' },
            @{ Name = 'pem-private-key'; Regex = '-----BEGIN [A-Z ]+PRIVATE KEY-----' },
            @{ Name = 'ngrok-authtoken'; Regex = '(?i)authtoken["'']?\s*[:=]\s*["'']?[A-Za-z0-9_\-]{16,}' },
            @{ Name = 'app-key-assignment'; Regex = '(?i)APP_KEY\s*=\s*["'']?base64:[A-Za-z0-9+/=_.\-]{16,}' }
        )
        foreach ($line in $added) {
            # Never flag the scanner's own regex table when this script is committed.
            if ($line -match '^\+\s*@\{\s*Name\s*=') { continue }
            foreach ($p in $patterns) {
                if ($line -imatch $p.Regex) {
                    $snippet = $line.Trim()
                    if ($snippet.Length -gt 160) { $snippet = $snippet.Substring(0, 160) + '...' }
                    $findings += ('{0}: {1}' -f $p.Name, $snippet)
                }
            }
        }
    }
    return @($findings)
}

# ---------------------------------------------------------------------------
# Stage / commit
# ---------------------------------------------------------------------------
function Invoke-Stage {
    $add = Invoke-Git -Arguments @('add', '-A') -AllowFailure
    if ($add.Code -ne 0) {
        Write-SyncLog -Level 'ERROR' -Message ('git add -A failed: ' + (Get-FirstLine $add.Output))
        return @()
    }
    $staged = Invoke-Git -Arguments @('diff', '--cached', '--name-only') -AllowFailure
    if ($staged.Code -ne 0 -or [string]::IsNullOrWhiteSpace($staged.Output)) { return @() }
    $names = @($staged.Output -split "`r?`n" | Where-Object { $_ })
    $excluded = @($names | Where-Object { Test-ExcludedPath $_ })
    if ($excluded.Count -gt 0) {
        $resetArgs = @('reset', '-q', '--') + $excluded
        Invoke-Git -Arguments $resetArgs -AllowFailure | Out-Null
        Write-SyncLog -Level 'WARN' -Message ('refused to stage excluded path(s): ' + ($excluded -join ', '))
        $names = @($names | Where-Object { -not (Test-ExcludedPath $_) })
    }
    return $names
}

# ---------------------------------------------------------------------------
# Push (never forced)
# ---------------------------------------------------------------------------
function Get-AheadCount {
    $remoteRef = '{0}/{1}' -f $Remote, $Branch
    $verify = Invoke-Git -Arguments @('rev-parse', '--verify', '--quiet', $remoteRef) -AllowFailure
    if ($verify.Code -ne 0) { return -1 }
    $count = Invoke-Git -Arguments @('rev-list', '--count', ('{0}..HEAD' -f $remoteRef)) -AllowFailure
    if ($count.Code -ne 0) { return -1 }
    $value = 0
    if ([int]::TryParse($count.Output.Trim(), [ref]$value)) { return $value }
    return -1
}

function Invoke-PushPending {
    param([string]$CommitHead)
    if ($NoPush) { return }
    $ahead = Get-AheadCount
    if ($ahead -eq 0) { return }
    $head = $CommitHead
    if ([string]::IsNullOrWhiteSpace($head)) {
        $head = (Invoke-Git -Arguments @('rev-parse', '--short', 'HEAD') -AllowFailure).Output
    }
    $push = Invoke-Git -Arguments @('push', $Remote, $Branch) -AllowFailure
    if ($push.Code -eq 0) {
        Write-SyncLog -Level 'INFO' -Message ('pushed {0} to {1}/{2}' -f $head, $Remote, $Branch)
        return
    }
    $text = $push.Output
    if ($text -match '(?i)non-fast-forward|\[rejected\]|fetch first|failed to push some refs') {
        Invoke-SafeRecovery -PushOutput $text
        return
    }
    Write-SyncLogThrottled -Key 'pushfail' -Seconds 300 -Level 'WARN' -Message (
        'push failed (remote unreachable or auth issue); {0} commit(s) kept local for retry: {1}' -f $ahead, (Get-FirstLine $text))
}

function Invoke-SafeRecovery {
    param([string]$PushOutput)
    Write-SyncLog -Level 'WARN' -Message 'push rejected as non-fast-forward; attempting safe fast-forward recovery (never forcing)'
    $fetch = Invoke-Git -Arguments @('fetch', '--quiet', $Remote, $Branch) -AllowFailure
    if ($fetch.Code -ne 0) {
        Write-SyncLog -Level 'ERROR' -Message ('cannot fetch {0}/{1}; commits stay local: {2}' -f $Remote, $Branch, (Get-FirstLine $fetch.Output))
        return
    }
    $remoteRef = '{0}/{1}' -f $Remote, $Branch
    $ancestor = Invoke-Git -Arguments @('merge-base', '--is-ancestor', $remoteRef, 'HEAD') -AllowFailure
    if ($ancestor.Code -eq 0) {
        $retry = Invoke-Git -Arguments @('push', $Remote, $Branch) -AllowFailure
        if ($retry.Code -eq 0) { Write-SyncLog -Level 'INFO' -Message 'push succeeded after fetch'; return }
    } else {
        $ff = Invoke-Git -Arguments @('pull', '--ff-only', '--quiet', $Remote, $Branch) -AllowFailure
        if ($ff.Code -eq 0) {
            $retry = Invoke-Git -Arguments @('push', $Remote, $Branch) -AllowFailure
            if ($retry.Code -eq 0) { Write-SyncLog -Level 'INFO' -Message 'push succeeded after fast-forward pull'; return }
        }
    }
    Write-SyncLog -Level 'CRITICAL' -Message 'remote history diverged; refusing to force-push. Watcher stopping for manual resolution.'
    throw ([System.InvalidOperationException]::new('remote history divergence: fast-forward not possible'))
}

# ---------------------------------------------------------------------------
# One sync pass
# ---------------------------------------------------------------------------
function Invoke-SyncPass {
    param([switch]$Immediate)

    # Never work while another git process holds the index lock.
    $indexLock = Join-Path $script:RepoRoot '.git\index.lock'
    if (Test-Path -LiteralPath $indexLock) {
        Write-SyncLogThrottled -Key 'indexlock' -Seconds 300 -Level 'WARN' -Message '.git/index.lock exists (another git process?); skipping this pass'
        return 'locked'
    }

    # Retry commits left local by an earlier failed push.
    if (-not $NoPush) { Invoke-PushPending }

    $lines = Get-StatusLines
    if ($lines.Count -eq 0) {
        if ($script:PendingSignature) {
            $script:PendingSignature = $null
            $script:PendingSince = $null
            Write-SyncLog -Level 'INFO' -Message 'working tree clean again before commit; debounce reset'
        }
        return 'clean'
    }

    $signature = ($lines -join "`n")
    if (-not $Immediate) {
        if ($signature -ne $script:PendingSignature) {
            $script:PendingSignature = $signature
            $script:PendingSince = Get-Date
            Write-SyncLog -Level 'INFO' -Message ('changes detected ({0} path(s)); waiting {1}s of quiet before committing' -f $lines.Count, $QuietSeconds)
            return 'debouncing'
        }
        $quietFor = ((Get-Date) - $script:PendingSince).TotalSeconds
        if ($quietFor -lt $QuietSeconds) {
            Write-SyncLogThrottled -Key 'debounce' -Seconds 30 -Level 'INFO' -Message ('still debouncing: quiet for {0:N0}s / {1}s' -f $quietFor, $QuietSeconds)
            return 'debouncing'
        }
    }

    $staged = Invoke-Stage
    if ($staged.Count -eq 0) {
        Write-SyncLog -Level 'WARN' -Message 'all changed paths are excluded by policy; nothing staged'
        $script:PendingSignature = $null
        $script:PendingSince = $null
        return 'excluded'
    }

    $findings = Get-SecretFindings
    if ($findings.Count -gt 0) {
        Invoke-Git -Arguments @('reset', '-q') -AllowFailure | Out-Null
        $script:PendingSignature = $null
        $script:PendingSince = $null
        Write-SyncLogThrottled -Key 'secret' -Seconds 300 -Level 'SECURITY' -Message (
            'commit aborted: possible secret in staged content -> ' + ($findings -join ' | '))
        return 'secret-abort'
    }

    $message = New-CommitMessage -StatusLines $lines
    $commit = Invoke-Git -Arguments @('commit', '-m', $message.Subject, '-m', $message.Body) -AllowFailure
    if ($commit.Code -ne 0) {
        Write-SyncLog -Level 'ERROR' -Message ('commit FAILED: ' + (Get-FirstLine $commit.Output))
        $script:PendingSignature = $null
        $script:PendingSince = $null
        return 'commit-failed'
    }
    $script:PendingSignature = $null
    $script:PendingSince = $null
    $head = (Invoke-Git -Arguments @('rev-parse', '--short', 'HEAD') -AllowFailure).Output.Trim()
    Write-SyncLog -Level 'INFO' -Message ('committed {0}: {1}' -f $head, $message.Subject)

    if (-not $NoPush) { Invoke-PushPending -CommitHead $head }
    return 'committed'
}

# ---------------------------------------------------------------------------
# Singleton lock so two watchers never race on the index
# ---------------------------------------------------------------------------
$script:LockFile = Join-Path $script:TempDir 'git-autopush.pid'

function Acquire-WatcherLock {
    if (Test-Path -LiteralPath $script:LockFile) {
        $old = (Get-Content -LiteralPath $script:LockFile -Raw -ErrorAction SilentlyContinue)
        if ($old) { $old = $old.Trim() }
        if ($old -match '^\d+$') {
            $proc = Get-Process -Id ([int]$old) -ErrorAction SilentlyContinue
            if ($proc) {
                Write-SyncLog -Level 'ERROR' -Message ('another watcher is already running (pid {0}); exiting' -f $old)
                return $false
            }
        }
        Remove-Item -LiteralPath $script:LockFile -Force -ErrorAction SilentlyContinue
    }
    Set-Content -LiteralPath $script:LockFile -Value $PID -Encoding ASCII
    return $true
}

function Remove-WatcherLock {
    try {
        if (Test-Path -LiteralPath $script:LockFile) {
            $cur = (Get-Content -LiteralPath $script:LockFile -Raw -ErrorAction SilentlyContinue)
            if ($cur) { $cur = $cur.Trim() }
            if ($cur -eq "$PID") { Remove-Item -LiteralPath $script:LockFile -Force -ErrorAction SilentlyContinue }
        }
    } catch { }
}

# ---------------------------------------------------------------------------
# Startup validation
# ---------------------------------------------------------------------------
function Test-Startup {
    if (-not (Test-Path -LiteralPath (Join-Path $script:RepoRoot '.git'))) {
        Write-SyncLog -Level 'ERROR' -Message ('not a git repository: {0}' -f $script:RepoRoot)
        return 1
    }

    $script:Git = Resolve-GitBinary
    if (-not $script:Git) {
        Write-SyncLog -Level 'ERROR' -Message 'no git binary found. Expected .tools\git\cmd\git.exe (portable MinGit) or git on PATH.'
        return 1
    }
    $version = (Invoke-Git -Arguments @('--version') -AllowFailure).Output
    Write-SyncLog -Level 'INFO' -Message ('git binary: {0} ({1})' -f $script:Git, $version)

    $script:SshWrapper = Ensure-SshWrapper
    $env:GIT_SSH = $script:SshWrapper
    $env:GIT_TERMINAL_PROMPT = '0'
    $env:GIT_PAGER = 'cat'
    # Remove (do not blank) GIT_SSH_COMMAND: an empty value is still a value to git.
    Remove-Item -Path Env:\GIT_SSH_COMMAND -ErrorAction SilentlyContinue
    Write-SyncLog -Level 'INFO' -Message ('GIT_SSH wrapper: {0}' -f $script:SshWrapper)

    # core.sshCommand also goes through MSYS sh.exe in this sandbox; remove it if set.
    $sshCmd = Invoke-Git -Arguments @('config', '--local', '--get', 'core.sshCommand') -AllowFailure
    if ($sshCmd.Code -eq 0 -and $sshCmd.Output) {
        Invoke-Git -Arguments @('config', '--local', '--unset', 'core.sshCommand') -AllowFailure | Out-Null
        Write-SyncLog -Level 'WARN' -Message 'removed core.sshCommand from local git config (uses MSYS sh.exe, blocked by the sandbox)'
    }

    $current = (Invoke-Git -Arguments @('branch', '--show-current') -AllowFailure).Output.Trim()
    if ([string]::IsNullOrWhiteSpace($current)) {
        Write-SyncLog -Level 'ERROR' -Message 'repository is in detached HEAD state; checkout a branch first'
        return 3
    }
    if ($current -ne $Branch) {
        Write-SyncLog -Level 'ERROR' -Message ('checked-out branch "{0}" does not match -Branch "{1}"; refusing to switch automatically' -f $current, $Branch)
        return 3
    }

    if (-not $NoPush) {
        $url = Invoke-Git -Arguments @('remote', 'get-url', $Remote) -AllowFailure
        if ($url.Code -ne 0) {
            Write-SyncLog -Level 'ERROR' -Message ('remote "{0}" is not configured. Add it with: git remote add {0} git@github.com:<owner>/<repo>.git' -f $Remote)
            return 1
        }
        Write-SyncLog -Level 'INFO' -Message ('remote {0}: {1}' -f $Remote, $url.Output.Trim())
    } else {
        Write-SyncLog -Level 'INFO' -Message 'push disabled (-NoPush): commits stay local'
    }

    # Commit identity is required; keep a repo-local fallback only when unset.
    $name = (Invoke-Git -Arguments @('config', 'user.name') -AllowFailure).Output.Trim()
    if ([string]::IsNullOrWhiteSpace($name)) {
        Invoke-Git -Arguments @('config', '--local', 'user.name', 'MtaaPass AutoSync') -AllowFailure | Out-Null
        Write-SyncLog -Level 'WARN' -Message 'user.name was unset; set repo-local fallback "MtaaPass AutoSync"'
    }
    $email = (Invoke-Git -Arguments @('config', 'user.email') -AllowFailure).Output.Trim()
    if ([string]::IsNullOrWhiteSpace($email)) {
        Invoke-Git -Arguments @('config', '--local', 'user.email', 'autosync@mtaapass.local') -AllowFailure | Out-Null
        Write-SyncLog -Level 'WARN' -Message 'user.email was unset; set repo-local fallback "autosync@mtaapass.local"'
    }

    return 0
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
$script:PendingSignature = $null
$script:PendingSince = $null
$script:Git = $null
$script:SshWrapper = $null

Write-SyncLog -Level 'INFO' -Message ('--- git-autopush starting: repo={0} branch={1} remote={2} interval={3}s quiet={4}s once={5} nopush={6}' -f
    $script:RepoRoot, $Branch, $Remote, $IntervalSeconds, $QuietSeconds, [bool]$Once, [bool]$NoPush)
Write-SyncLog -Level 'INFO' -Message ('log file: {0}' -f $script:LogFile)

$exitCode = (Test-Startup)
if ($exitCode -ne 0) {
    Write-SyncLog -Level 'ERROR' -Message ('startup validation failed (code {0}); watcher not started' -f $exitCode)
    exit $exitCode
}

if (-not (Acquire-WatcherLock)) { exit 1 }

try {
    while ($true) {
        try {
            $result = Invoke-SyncPass -Immediate:$Once
            if ($Once) { Write-SyncLog -Level 'INFO' -Message ('single pass finished: {0}' -f $result) }
        } catch [System.InvalidOperationException] {
            Write-SyncLog -Level 'CRITICAL' -Message $_.Exception.Message
            $exitCode = 2
            break
        } catch {
            Write-SyncLog -Level 'ERROR' -Message ('pass failed (fail-soft, continuing): ' + $_.Exception.Message)
        }
        if ($Once) { break }
        Start-Sleep -Seconds $IntervalSeconds
    }
} finally {
    Remove-WatcherLock
}

Write-SyncLog -Level 'INFO' -Message ('--- git-autopush stopped (exit {0})' -f $exitCode)
exit $exitCode
