# Datei: tests/ui/screenshots.ps1
# Zweck: Screenshots des Ist-Zustands der Oberfläche (UI-Golden-Basis U0) mit Microsoft Edge headless.
#        403-Antworten sind reine Textseiten (requireRole) und werden in der Rollenmatrix statt per Screenshot belegt.
#        Voraussetzung: Auf dem Testsystem läuft "run.sh serve <SHA>" (PHP-Entwicklungsserver nur auf 127.0.0.1:18601-18603).
#        Das Skript öffnet einen SSH-Tunnel, ruft die Seiten je Rolle ab und speichert PNG-Dateien.
#        Eigenes temporäres Edge-Profil, keine Anmeldedaten im Browser (Sessions setzt der Router serverseitig).
# Aufruf:  powershell -File tests/ui/screenshots.ps1 -SshTarget root@192.168.53.122 -KeyFile $HOME\.ssh\claude_dnsmanager_test -OutDir docs/ui/screenshots/u0
#          Optional (ab U1): -ColorScheme dark (Systemvorgabe des Browsers dunkel), -NoJs (JavaScript abgeschaltet),
#          -Only name1,name2 (nur diese Aufnahmen). Dateiname erhält dann das Suffix -dark bzw. -nojs.
param(
    [Parameter(Mandatory = $true)][string]$SshTarget,
    [Parameter(Mandatory = $true)][string]$KeyFile,
    [Parameter(Mandatory = $true)][string]$OutDir,
    [string]$Edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    [ValidateSet('light', 'dark')][string]$ColorScheme = 'light',
    [switch]$NoJs,
    [string[]]$Only = @()
)
$ErrorActionPreference = 'Stop'
# Bei Aufruf über powershell -File kommt -Only a,b als eine Zeichenkette an
$Only = @($Only | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' })
$ports = @{ admin = 18601; zoneadmin = 18602; anon = 18603 }
$portsNoJs = @{ admin = 18611; zoneadmin = 18612; anon = 18613 }
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
    # Mobil: 492 CSS-Pixel = Mindestbreite von Edge headless (schmaler wird nur das Bild beschnitten); gleicher
    # Bootstrap-Bereich (xs, < 576 px) wie ein 390-px-Telefon
    @('anon-login-mobile', 'anon', 'login.php', 492, 900),
    @('admin-dashboard-mobile', 'admin', 'pages/dashboard.php', 492, 900),
    @('admin-zones-mobile', 'admin', 'pages/zones.php', 492, 900),
    @('admin-records-mobile', 'admin', 'pages/records.php?zone_id=1', 492, 900),
    # U3: Bausteinübersicht (Testseite des Harness), Editor-Rahmen und 403-Seite im Layout
    @('u3-showcase', 'admin', '__ui/showcase.php', 1440, 2900),
    @('u3-showcase-editor', 'admin', '__ui/showcase.php?editor=1', 1440, 1000),
    @('u3-showcase-mobile', 'admin', '__ui/showcase.php', 492, 3600),
    @('u3-showcase-editor-mobile', 'admin', '__ui/showcase.php?editor=1', 492, 900),
    @('u3-403-zoneadmin', 'zoneadmin', 'pages/servers.php', 1440, 800),
    # U4-UI: Record-Verwaltung (Zonenauswahl, Herkunft, Klassifizierung), rechter Editor mit Vorschau/DIFF
    @('u4-records', 'admin', 'pages/records.php?zone_id=1', 1440, 1500),
    @('u4-records-reverse', 'admin', 'pages/records.php?zone_id=3', 1440, 900),
    @('u4-records-zoneadmin', 'zoneadmin', 'pages/records.php?zone_id=1', 1440, 1500),
    @('u4-records-edit-srv', 'admin', 'pages/records.php?zone_id=1&edit_id=103', 1440, 1000),
    @('u4-records-edit-dkim', 'admin', 'pages/records.php?zone_id=1&edit_id=110', 1440, 1200),
    @('u4-records-edit-dyndns', 'admin', 'pages/records.php?zone_id=1&edit_id=8', 1440, 1000),
    @('u4-records-edit-glue', 'admin', 'pages/records.php?zone_id=1&edit_id=13', 1440, 1000),
    @('u4-records-add-mx', 'admin', 'pages/records.php?zone_id=1&add_new=1&type=MX', 1440, 1000),
    @('u4-records-add-dkim', 'admin', 'pages/records.php?zone_id=1&add_new=1&type=DKIM', 1440, 1200),
    @('u4-records-mobile', 'admin', 'pages/records.php?zone_id=1', 492, 1400),
    @('u4-records-edit-mobile', 'admin', 'pages/records.php?zone_id=1&edit_id=110', 492, 1400),
    # U5a: Dashboard, Zonenliste, Zoneneditor
    @('u5a-dashboard', 'admin', 'pages/dashboard.php', 1440, 1000),
    @('u5a-dashboard-zoneadmin', 'zoneadmin', 'pages/dashboard.php', 1440, 900),
    @('u5a-zones', 'admin', 'pages/zones.php', 1440, 900),
    @('u5a-zones-zoneadmin', 'zoneadmin', 'pages/zones.php', 1440, 800),
    @('u5a-zones-edit', 'admin', 'pages/zones.php?edit_id=1', 1440, 1300),
    @('u5a-zones-edit-reverse', 'admin', 'pages/zones.php?edit_id=3', 1440, 1300),
    @('u5a-zones-edit-zoneadmin', 'zoneadmin', 'pages/zones.php?edit_id=1', 1440, 1200),
    @('u5a-zones-add', 'admin', 'pages/zones.php?add_new=1', 1440, 1400),
    @('u5a-zones-add-reverse', 'admin', 'pages/zones.php?add_new=1&type=reverse_ipv4', 1440, 1400),
    @('u5a-dashboard-mobile', 'admin', 'pages/dashboard.php', 492, 1500),
    @('u5a-zones-mobile', 'admin', 'pages/zones.php', 492, 1000),
    @('u5a-zones-edit-mobile', 'admin', 'pages/zones.php?edit_id=1', 492, 1600)
)
New-Item -ItemType Directory -Force $OutDir | Out-Null
$profileDir = Join-Path $env:TEMP ("uig-edge-" + [guid]::NewGuid().ToString('N'))
$fwd = @(); foreach ($p in @($ports.Values) + @($portsNoJs.Values)) { $fwd += @('-L', "$($p):127.0.0.1:$p") }
$tunnel = Start-Process -FilePath ssh -ArgumentList (@('-i', $KeyFile, '-N', '-o', 'ExitOnForwardFailure=yes') + $fwd + @($SshTarget)) -PassThru -WindowStyle Hidden
try {
    Start-Sleep -Seconds 3
    # Blink-Einstellungen: bevorzugtes Farbschema (0 = dunkel, 1 = hell) und optional JavaScript aus
    $blink = 'preferredColorScheme=' + $(if ($ColorScheme -eq 'dark') { '0' } else { '1' })
    # Ohne JavaScript: Server-Ports 1861x, deren Router alle <script>-Elemente entfernt (Edge headless erzeugt mit
    # scriptEnabled=false keine Screenshots)
    $portMap = $(if ($NoJs) { $portsNoJs } else { $ports })
    $suffix = $(if ($ColorScheme -eq 'dark') { '-dark' } else { '' }) + $(if ($NoJs) { '-nojs' } else { '' })
    foreach ($s in $shots) {
        if ($Only.Count -gt 0 -and $Only -notcontains $s[0]) { continue }
        $file = Join-Path (Resolve-Path $OutDir) ($s[0] + $suffix + '.png')
        $url = "http://127.0.0.1:$($portMap[$s[1]])/dnsmanager-ui/$($s[2])"
        $edgeArgs = @('--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--disable-extensions', "--user-data-dir=`"$profileDir`"", "--blink-settings=$blink",
            "--window-size=$($s[3]),$($s[4])", "--screenshot=`"$file`"", $url)
        # Edge schreibt Diagnosezeilen auf stderr; diese werden verworfen (kein Abbruch)
        # Zeitlimit je Aufnahme: Edge headless beendet sich bei manchen Antworten (z. B. reine 403-Textseite) nicht selbst
        $proc = Start-Process -FilePath $Edge -ArgumentList $edgeArgs -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'uig-edge-stderr.txt')
        if (-not $proc.WaitForExit(45000)) {
            Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like "*$profileDir*" } |
                ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        }
        Start-Sleep -Milliseconds 300
        if (Test-Path $file) { "{0,-32} {1,8:N0} Byte  {2}" -f ($s[0] + $suffix), (Get-Item $file).Length, $s[2] } else { "FEHLT {0}" -f ($s[0] + $suffix) }
    }
}
finally {
    Stop-Process -Id $tunnel.Id -Force -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $profileDir -ErrorAction SilentlyContinue
}
