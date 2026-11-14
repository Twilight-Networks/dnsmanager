# Datei: tests/ui/screenshots.ps1
# Zweck: Screenshots des Ist-Zustands der Oberfläche (UI-Golden-Basis U0) mit Microsoft Edge headless.
#        403-Antworten sind reine Textseiten (requireRole) und werden in der Rollenmatrix statt per Screenshot belegt.
#        Voraussetzung: Auf dem Testsystem läuft "run.sh serve <SHA>" (PHP-Entwicklungsserver nur auf 127.0.0.1:18601-18603).
#        Das Skript öffnet einen SSH-Tunnel, ruft die Seiten je Rolle ab und speichert PNG-Dateien.
#        Eigenes temporäres Edge-Profil, keine Anmeldedaten im Browser (Sessions setzt der Router serverseitig).
# Aufruf:  powershell -File tests/ui/screenshots.ps1 -SshTarget root@192.168.53.122 -KeyFile $HOME\.ssh\claude_dnsmanager_test -OutDir docs/ui/screenshots/u0
param(
    [Parameter(Mandatory = $true)][string]$SshTarget,
    [Parameter(Mandatory = $true)][string]$KeyFile,
    [Parameter(Mandatory = $true)][string]$OutDir,
    [string]$Edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
)
$ErrorActionPreference = 'Stop'
$ports = @{ admin = 18601; zoneadmin = 18602; anon = 18603 }
# Name; Rolle; Pfad; Breite; Höhe
$shots = @(
    @('anon-login-desktop', 'anon', 'login.php', 1440, 900),
    @('admin-dashboard', 'admin', 'pages/dashboard.php', 1440, 1000),
    @('admin-zones', 'admin', 'pages/zones.php', 1440, 1000),
    @('admin-zones-add', 'admin', 'pages/zones.php?add_new=1', 1440, 1300),
    @('admin-zones-edit', 'admin', 'pages/zones.php?edit_id=1', 1440, 1300),
    @('admin-records', 'admin', 'pages/records.php?zone_id=1', 1440, 1200),
    @('admin-records-add', 'admin', 'pages/records.php?zone_id=1&add_new=1', 1440, 1200),
    @('admin-records-edit', 'admin', 'pages/records.php?zone_id=1&edit_id=3', 1440, 1200),
    @('admin-records-reverse', 'admin', 'pages/records.php?zone_id=3', 1440, 1000),
    @('admin-servers', 'admin', 'pages/servers.php', 1440, 1000),
    @('admin-servers-add', 'admin', 'pages/servers.php?add_new=1', 1440, 1100),
    @('admin-servers-edit', 'admin', 'pages/servers.php?edit_id=2', 1440, 1100),
    @('admin-dyndns', 'admin', 'pages/dyndns.php', 1440, 1000),
    @('admin-dyndns-add', 'admin', 'pages/dyndns.php?add_new=1', 1440, 1000),
    @('admin-users', 'admin', 'pages/users.php', 1440, 1000),
    @('admin-users-add', 'admin', 'pages/users.php?add_new=1', 1440, 1000),
    @('admin-system-health', 'admin', 'pages/system_health.php', 1440, 1200),
    @('admin-update', 'admin', 'pages/update.php', 1440, 900),
    @('zoneadmin-dashboard', 'zoneadmin', 'pages/dashboard.php', 1440, 1000),
    @('zoneadmin-zones', 'zoneadmin', 'pages/zones.php', 1440, 1000),
    @('zoneadmin-records', 'zoneadmin', 'pages/records.php?zone_id=1', 1440, 1200),
    @('zoneadmin-users', 'zoneadmin', 'pages/users.php', 1440, 900),
    @('anon-login-mobile', 'anon', 'login.php', 390, 844),
    @('admin-dashboard-mobile', 'admin', 'pages/dashboard.php', 390, 844),
    @('admin-zones-mobile', 'admin', 'pages/zones.php', 390, 844),
    @('admin-records-mobile', 'admin', 'pages/records.php?zone_id=1', 390, 844)
)
New-Item -ItemType Directory -Force $OutDir | Out-Null
$profileDir = Join-Path $env:TEMP ("uig-edge-" + [guid]::NewGuid().ToString('N'))
$fwd = @(); foreach ($p in $ports.Values) { $fwd += @('-L', "$($p):127.0.0.1:$p") }
$tunnel = Start-Process -FilePath ssh -ArgumentList (@('-i', $KeyFile, '-N', '-o', 'ExitOnForwardFailure=yes') + $fwd + @($SshTarget)) -PassThru -WindowStyle Hidden
try {
    Start-Sleep -Seconds 3
    foreach ($s in $shots) {
        $file = Join-Path (Resolve-Path $OutDir) ($s[0] + '.png')
        $url = "http://127.0.0.1:$($ports[$s[1]])/dnsmanager-ui/$($s[2])"
        $edgeArgs = @('--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--disable-extensions', "--user-data-dir=`"$profileDir`"",
            "--window-size=$($s[3]),$($s[4])", "--screenshot=`"$file`"", $url)
        # Edge schreibt Diagnosezeilen auf stderr; diese werden verworfen (kein Abbruch)
        # Zeitlimit je Aufnahme: Edge headless beendet sich bei manchen Antworten (z. B. reine 403-Textseite) nicht selbst
        $proc = Start-Process -FilePath $Edge -ArgumentList $edgeArgs -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'uig-edge-stderr.txt')
        if (-not $proc.WaitForExit(45000)) {
            Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like "*$profileDir*" } |
                ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        }
        Start-Sleep -Milliseconds 300
        if (Test-Path $file) { "{0,-26} {1,8:N0} Byte  {2}" -f $s[0], (Get-Item $file).Length, $s[2] } else { "FEHLT {0}" -f $s[0] }
    }
}
finally {
    Stop-Process -Id $tunnel.Id -Force -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $profileDir -ErrorAction SilentlyContinue
}
