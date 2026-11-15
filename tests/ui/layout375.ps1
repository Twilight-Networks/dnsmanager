# Datei: tests/ui/layout375.ps1
# Zweck: 375-px-Layoutprüfung (U6, E4) aller A+-Seiten und Editoren gegen die Testkopie ("run.sh serve <SHA>").
#        Steuert Microsoft Edge headless über das DevTools-Protokoll (Gerätemetrik 375 x 812, mobil), weil Edge headless
#        mit --window-size nicht schmaler als 492 px rendert. Je Seite:
#        - Seitenbreite: innerWidth = 375 und kein seitliches Scrollen (scrollWidth <= innerWidth);
#        - überstehende Elemente außerhalb eines eigenen Scroll-/Clip-Containers;
#        - Treffertest aller sichtbaren Bedienelemente (nach scrollIntoView liegt an ihrer Mitte das Element selbst);
#          bei offenem rechten Editor (mobil Dialog über die ganze Breite) nur die Elemente des Editors;
#        - Inline-/Löschbestätigungen (<details class="dm-confirm">) clientseitig geöffnet: Bestätigungsbutton sichtbar
#          und laut Treffertest klickbar (kein Klick, kein POST), danach wieder geschlossen;
#        - CSP-Verstöße (securitypolicyviolation, vom Protokoll vor jeder Seite registriert, nicht Teil der Seite);
#        - optional Screenshot der ganzen Seite.
#        Ohne JavaScript: Server-Ports 1861x (Router entfernt <script>); die Messung läuft über das Protokoll.
#        Keine Anmeldedaten im Browser (Sessions setzt der Router serverseitig); eigenes temporäres Edge-Profil.
# Aufruf:  powershell -File tests/ui/layout375.ps1 -SshTarget root@192.168.53.122 -KeyFile $HOME\.ssh\claude_dnsmanager_test
#            -Result $env:TEMP\l375.json [-OutDir docs/ui/screenshots/u6] [-ColorScheme dark] [-NoJs] [-Only name1,name2]
#            [-Shots name1,name2 | -Shots all]
param(
    [Parameter(Mandatory = $true)][string]$SshTarget,
    [Parameter(Mandatory = $true)][string]$KeyFile,
    [Parameter(Mandatory = $true)][string]$Result,
    [string]$OutDir = '',
    [string]$Edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    [ValidateSet('light', 'dark')][string]$ColorScheme = 'light',
    [switch]$NoJs,
    [string[]]$Only = @(),
    [string[]]$Shots = @(),
    [int]$DebugPort = 9333
)
$ErrorActionPreference = 'Stop'
$Only = @($Only | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' })
$Shots = @($Shots | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' })
$ports = @{ admin = 18601; zoneadmin = 18602; anon = 18603 }
$portsNoJs = @{ admin = 18611; zoneadmin = 18612; anon = 18613 }
# Name; Rolle; Pfad (Fixture der Golden-Basis: Zone 1 u0.test, Record 3 www A, Server 2, DynDNS 1, Benutzer 2 u0zone)
$pages = @(
    @('login', 'anon', 'login.php'),
    @('dashboard', 'admin', 'pages/dashboard.php'),
    @('zones', 'admin', 'pages/zones.php'),
    @('zones-add', 'admin', 'pages/zones.php?add_new=1'),
    @('zones-edit', 'admin', 'pages/zones.php?edit_id=1'),
    @('records', 'admin', 'pages/records.php?zone_id=1'),
    @('records-add', 'admin', 'pages/records.php?zone_id=1&add_new=1'),
    @('records-edit', 'admin', 'pages/records.php?zone_id=1&edit_id=3'),
    @('records-reverse', 'admin', 'pages/records.php?zone_id=3'),
    @('servers', 'admin', 'pages/servers.php'),
    @('servers-add', 'admin', 'pages/servers.php?add_new=1'),
    @('servers-edit', 'admin', 'pages/servers.php?edit_id=2'),
    @('dyndns', 'admin', 'pages/dyndns.php'),
    @('dyndns-add', 'admin', 'pages/dyndns.php?add_new=1'),
    @('dyndns-edit', 'admin', 'pages/dyndns.php?edit_id=1'),
    @('dyndns-pw', 'admin', 'pages/dyndns.php?pw_id=1'),
    @('users', 'admin', 'pages/users.php'),
    @('users-add', 'admin', 'pages/users.php?add_new=1'),
    @('users-edit', 'admin', 'pages/users.php?edit_id=2'),
    @('users-pw', 'admin', 'pages/users.php?pw_id=2'),
    @('system-health', 'admin', 'pages/system_health.php'),
    @('update', 'admin', 'pages/update.php'),
    @('z-dashboard', 'zoneadmin', 'pages/dashboard.php'),
    @('z-zones', 'zoneadmin', 'pages/zones.php'),
    @('z-zones-edit', 'zoneadmin', 'pages/zones.php?edit_id=1'),
    @('z-records', 'zoneadmin', 'pages/records.php?zone_id=1'),
    @('z-records-edit', 'zoneadmin', 'pages/records.php?zone_id=1&edit_id=3'),
    @('z-users', 'zoneadmin', 'pages/users.php'),
    @('z-users-pw', 'zoneadmin', 'pages/users.php?pw_id=2'),
    @('z-403', 'zoneadmin', 'pages/servers.php')
)

# Vor jeder Seite registriert (nicht Teil der Seite, unterliegt nicht deren CSP): sammelt CSP-Verstöße
$cspHook = "window.__u6csp = []; document.addEventListener('securitypolicyviolation', function (e) { window.__u6csp.push(e.effectiveDirective + ' ' + e.blockedURI + ' ' + (e.sourceFile || '') + ':' + e.lineNumber); });"

# Messung (liefert JSON-Text); ändert keinen Serverzustand: Bestätigungen werden nur über das open-Attribut geöffnet
$measure = @'
(() => {
  const W = innerWidth, de = document.documentElement;
  const vis = el => { const b = el.getBoundingClientRect(); return b.width > 0 && b.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  const desc = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '')
    + ' "' + (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 30) + '"';
  const clipped = el => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { if (getComputedStyle(p).overflowX !== 'visible') return true; } return false; };
  const skip = el => el.closest('[aria-hidden=true]') || (el.matches('.visually-hidden, .visually-hidden-focusable') && !el.matches(':focus-within'));
  const off = [];
  for (const el of document.body.querySelectorAll('*')) {
    if (!vis(el) || skip(el)) continue;
    const b = el.getBoundingClientRect();
    if ((b.right > W + 1 || b.left < -1) && !clipped(el)) off.push(desc(el) + ' ' + Math.round(b.left) + '..' + Math.round(b.right));
  }
  const hit = el => {
    el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
    const b = el.getBoundingClientRect();
    const x = b.left + b.width / 2, y = b.top + b.height / 2;
    if (x < 0 || x > W || y < 0 || y > innerHeight) return 'außerhalb ' + Math.round(x) + ',' + Math.round(y);
    const h = document.elementFromPoint(x, y);
    if (h && (h === el || el.contains(h) || (h.tagName === 'LABEL' && h.control === el))) return '';
    return 'verdeckt von ' + (h ? desc(h) : 'nichts');
  };
  const sel = 'a[href], button, input:not([type=hidden]), select, textarea, summary';
  // Offener rechter Editor: mobil ein Dialog über die ganze Breite (aria-modal); geprüft wird dann nur der Editor,
  // die Seite dahinter ist beabsichtigt verdeckt. Nur bei Fokus sichtbare Elemente (Skip-Link) und aria-hidden
  // (Hintergrund-Link des Editors) zählen nicht als sichtbare Bedienelemente.
  const ed = document.querySelector('.dm-editor');
  const scope = ed && vis(ed) ? ed : document;
  const failed = [], small = [];
  let n = 0;
  for (const el of scope.querySelectorAll(sel)) {
    if (!vis(el) || skip(el) || el.closest('details:not([open]) > :not(summary)')) continue;
    n++;
    const r = hit(el);
    if (r) failed.push(desc(el) + ': ' + r);
    if (el.getBoundingClientRect().height < 24 && el.tagName !== 'INPUT') small.push(desc(el));
  }
  const conf = [];
  let nc = 0;
  for (const d of scope.querySelectorAll('details.dm-confirm')) {
    if (d.open || !vis(d.querySelector('summary') || d)) continue;
    d.open = true;
    for (const b of d.querySelectorAll('.dm-confirm-pop button, .dm-confirm-pop [type=submit]')) {
      nc++;
      const r = hit(b);
      const bb = b.getBoundingClientRect();
      if (r) conf.push(desc(b) + ': ' + r);
      else if (bb.right > W + 1 || bb.left < -1) conf.push(desc(b) + ': ragt über den Rand ' + Math.round(bb.left) + '..' + Math.round(bb.right));
    }
    d.open = false;
  }
  scrollTo({ left: 0, top: 0, behavior: 'instant' });
  // CSP-Positivkontrolle (nur wenn die Testkopie vorübergehend ein Element #u6-csp-probe mit style-Attribut enthält)
  const probe = document.getElementById('u6-csp-probe');
  return JSON.stringify({ probe: probe ? { attr: probe.getAttribute('style'), outline: getComputedStyle(probe).outlineStyle } : null, title: document.title, editor: scope !== document, w: W, sw: de.scrollWidth, offenders: off.slice(0, 15), offenderCount: off.length,
    controls: n, failed: failed.slice(0, 15), failedCount: failed.length, confirms: nc, confirmFailed: conf,
    smallTargets: small.length, smallSample: small.slice(0, 5), csp: window.__u6csp || [], scripts: document.scripts.length });
})()
'@

$profileDir = Join-Path $env:TEMP ("uig-edge-" + [guid]::NewGuid().ToString('N'))
$fwd = @(); foreach ($p in @($ports.Values) + @($portsNoJs.Values)) { $fwd += @('-L', "$($p):127.0.0.1:$p") }
$tunnel = Start-Process -FilePath ssh -ArgumentList (@('-i', $KeyFile, '-N', '-o', 'ExitOnForwardFailure=yes') + $fwd + @($SshTarget)) -PassThru -WindowStyle Hidden
$edgeProc = $null; $ws = $null
$script:cdpId = 0
function Receive-Cdp {
    $buf = New-Object byte[] 65536; $ms = New-Object IO.MemoryStream
    do {
        $r = $ws.ReceiveAsync([ArraySegment[byte]]::new($buf), [Threading.CancellationToken]::None).Result
        $ms.Write($buf, 0, $r.Count)
    } while (-not $r.EndOfMessage)
    return [Text.Encoding]::UTF8.GetString($ms.ToArray())
}
# Sendet einen Befehl und liefert die Antwort als Rohtext (große Antworten wie Screenshots ohne ConvertFrom-Json)
function Invoke-Cdp([string]$method, $params = @{}) {
    $script:cdpId++
    $msg = @{ id = $script:cdpId; method = $method; params = $params } | ConvertTo-Json -Depth 10 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($msg)
    $ws.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).Wait()
    while ($true) {
        $raw = Receive-Cdp
        if ($raw -match ('^\{"id":' + $script:cdpId + '[,}]')) {
            if ($raw -match '^\{"id":\d+,"error"') { throw "CDP $method : $raw" }
            return $raw
        }
    }
}
function Eval-Cdp([string]$expr) {
    $raw = Invoke-Cdp 'Runtime.evaluate' @{ expression = $expr; returnByValue = $true }
    $o = $raw | ConvertFrom-Json
    if ($o.result.exceptionDetails) { throw "JS-Fehler: $($o.result.exceptionDetails.text) $($o.result.exceptionDetails.exception.description)" }
    return $o.result.result.value
}
try {
    Start-Sleep -Seconds 3
    if ($tunnel.HasExited) { throw 'SSH-Tunnel nicht aufgebaut (läuft run.sh serve?)' }
    $edgeArgs = @('--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--disable-extensions', "--remote-debugging-port=$DebugPort",
        "--user-data-dir=`"$profileDir`"", 'about:blank')
    $edgeProc = Start-Process -FilePath $Edge -ArgumentList $edgeArgs -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'uig-edge-stderr.txt')
    $target = $null
    for ($i = 0; $i -lt 40 -and -not $target; $i++) {
        Start-Sleep -Milliseconds 250
        # Windows PowerShell 5.1 reicht JSON-Arrays von Invoke-RestMethod als ein Objekt weiter: explizit durchlaufen
        try { foreach ($t in (Invoke-RestMethod "http://127.0.0.1:$DebugPort/json/list")) { if ($t.type -eq 'page') { $target = $t; break } } } catch { }
    }
    if (-not $target) { throw 'Edge-DevTools nicht erreichbar' }
    $ws = New-Object Net.WebSockets.ClientWebSocket
    $ws.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).Wait()
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 375; height = 812; deviceScaleFactor = 1; mobile = $true } | Out-Null
    Invoke-Cdp 'Emulation.setEmulatedMedia' @{ features = @(@{ name = 'prefers-color-scheme'; value = $ColorScheme }) } | Out-Null
    Invoke-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = $cspHook } | Out-Null
    $portMap = $(if ($NoJs) { $portsNoJs } else { $ports })
    $suffix = '-375' + $(if ($ColorScheme -eq 'dark') { '-dark' } else { '' }) + $(if ($NoJs) { '-nojs' } else { '' })
    if ($OutDir -ne '') { New-Item -ItemType Directory -Force $OutDir | Out-Null }
    $results = [ordered]@{}
    foreach ($pg in $pages) {
        if ($Only.Count -gt 0 -and $Only -notcontains $pg[0]) { continue }
        $url = "http://127.0.0.1:$($portMap[$pg[1]])/dnsmanager-ui/$($pg[2])"
        Invoke-Cdp 'Page.navigate' @{ url = $url } | Out-Null
        $ready = $false
        for ($i = 0; $i -lt 60 -and -not $ready; $i++) {
            Start-Sleep -Milliseconds 200
            try { $ready = (Eval-Cdp "location.href === '$url' && document.readyState === 'complete'") -eq $true } catch { }
        }
        Start-Sleep -Milliseconds 500
        $m = (Eval-Cdp $measure) | ConvertFrom-Json
        $issues = @()
        # Mobilmenü (nur mit JavaScript, ohne offenen Editor): Offcanvas öffnen, Links per Treffertest prüfen, schließen
        $menu = $null
        if (-not $NoJs -and -not $m.editor -and (Eval-Cdp "(() => { const t = document.querySelector('.app-menu-toggle'); if (!t || !t.offsetParent) return false; t.click(); return true; })()") -eq $true) {
            Start-Sleep -Milliseconds 700
            $menu = (Eval-Cdp @'
(() => {
  const nav = document.getElementById('appSidebar'), W = innerWidth, out = { open: nav.classList.contains('show'), links: 0, failed: [] };
  for (const a of nav.querySelectorAll('a[href], button')) {
    const b0 = a.getBoundingClientRect(); if (!b0.width || getComputedStyle(a).visibility === 'hidden') continue;
    out.links++;
    a.scrollIntoView({ block: 'center', behavior: 'instant' });
    const b = a.getBoundingClientRect(), x = b.left + b.width / 2, y = b.top + b.height / 2, h = document.elementFromPoint(x, y);
    if (!(h && (h === a || a.contains(h))) || b.right > W + 1) out.failed.push((a.innerText || a.getAttribute('aria-label') || a.tagName).trim().slice(0, 30));
  }
  const c = nav.querySelector('[data-bs-dismiss=offcanvas]'); if (c) c.click();
  return JSON.stringify(out);
})()
'@) | ConvertFrom-Json
            Start-Sleep -Milliseconds 600
            if (-not $menu.open) { $issues += 'Menü öffnet nicht' }
            if (@($menu.failed).Count -gt 0) { $issues += "Menü: $(@($menu.failed).Count) nicht klickbar ($(@($menu.failed) -join ', '))" }
        }
        $m | Add-Member -NotePropertyName menu -NotePropertyValue $menu
        if ($m.w -ne 375) { $issues += "innerWidth $($m.w)" }
        if ($m.sw -gt $m.w) { $issues += "scrollWidth $($m.sw)" }
        if ($m.offenderCount -gt 0) { $issues += "$($m.offenderCount) überstehend" }
        if ($m.failedCount -gt 0) { $issues += "$($m.failedCount) nicht klickbar" }
        if (@($m.confirmFailed).Count -gt 0) { $issues += "$(@($m.confirmFailed).Count) Bestätigung(en)" }
        if (@($m.csp).Count -gt 0) { $issues += "$(@($m.csp).Count) CSP-Verstöße" }
        if ($NoJs -and $m.scripts -gt 0) { $issues += "$($m.scripts) Skripte trotz No-JS" }
        $m | Add-Member -NotePropertyName url -NotePropertyValue $pg[2]
        $m | Add-Member -NotePropertyName role -NotePropertyValue $pg[1]
        $m | Add-Member -NotePropertyName ready -NotePropertyValue $ready
        $m | Add-Member -NotePropertyName issues -NotePropertyValue $issues
        $shot = ''
        if ($OutDir -ne '' -and ($Shots -contains 'all' -or $Shots -contains $pg[0])) {
            $lm = (Invoke-Cdp 'Page.getLayoutMetrics') | ConvertFrom-Json
            $h = [Math]::Ceiling($lm.result.cssContentSize.height)
            $raw = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $true; clip = @{ x = 0; y = 0; width = 375; height = $h; scale = 1 } }
            if ($raw -match '"data":"([^"]+)"') {
                $shot = Join-Path (Resolve-Path $OutDir) ('u6-' + $pg[0] + $suffix + '.png')
                [IO.File]::WriteAllBytes($shot, [Convert]::FromBase64String($Matches[1]))
                $shot = Split-Path $shot -Leaf
            }
        }
        $results[$pg[0]] = $m
        "{0,-18} {1,-9} {2,3} Elemente, {3,2} Bestätigungen, Menü {6,-3} {4}{5}" -f $pg[0], $pg[1], $m.controls, $m.confirms, $(if ($issues.Count) { 'FEHLER: ' + ($issues -join '; ') } else { 'ok' }), $(if ($shot) { "  [$shot]" } else { '' }), $(if ($menu) { $menu.links } else { '-' })
        if ($m.probe) { "                   CSP-Probe: style-Attribut '{0}', berechnete outline-style '{1}', Verstöße: {2}" -f $m.probe.attr, $m.probe.outline, (@($m.csp) -join ' | ') }
    }
    $results | ConvertTo-Json -Depth 6 | Set-Content -Encoding UTF8 $Result
    $bad = @($results.Values | Where-Object { $_.issues.Count -gt 0 }).Count
    "== 375 px ($ColorScheme$(if ($NoJs) { ', ohne JS' } else { '' })): $($results.Count - $bad)/$($results.Count) ohne Befund"
}
finally {
    if ($ws) { try { $ws.Dispose() } catch { } }
    if ($edgeProc) {
        Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like "*$profileDir*" } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    }
    Stop-Process -Id $tunnel.Id -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 500
    Remove-Item -Recurse -Force $profileDir -ErrorAction SilentlyContinue
}
