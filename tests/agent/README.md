# Agent-Tests (Meilenstein AS)

## AS1 – `as1_test.py`

Abnahmesuite für das Agent-Fencing (`docs/ARCHITECTURE_HISTORY.md`, Abschnitt 5e). Sie führt den echten Agent-Code
des geprüften Commits über den eingebauten PHP-Webserver (`php -S`, HTTP-SAPI) aus. Alle verwalteten BIND-Dateien
liegen in einer Sandbox unter `/tmp/as1-*` auf einem ext4-Dateisystem (Loop-Image über dm-flakey, eingehängt mit
`noauto_da_alloc,commit=300`: strengster Fall, nur `fsync` schreibt fest), damit `fsync` wirksam ist und Stromausfälle simuliert werden können. `rndc` ist
ein Platzhalter; `/etc/bind`, die installierte BIND-Instanz und die installierten Agenten werden nicht berührt.

Voraussetzungen (Test-VM, root): `php` (CLI), `strace`, `named-checkzone`, `named-checkconf`, `losetup`,
`mkfs.ext4`, `dmsetup`, Kernelmodul `dm_flakey` (wird bei Bedarf geladen und danach wieder entladen).

```bash
python3 tests/agent/as1_test.py --src <Checkout des Prüfstands> --ref <Checkout des Basis-Commits> --out <Ergebnisse> [--groups FSICLXGM]
```

Ohne `--groups` laufen alle Gruppen `FSICLXGM`; das schließt L (Vollständigkeit der Verzeichnisauflistung, E98) und
X (nicht prüfbare Existenz, E99; fehlende PHP-Erweiterung `posix`, E100; verkürzter Read von H) ein.

| Gruppe | Inhalt |
|---|---|
| F | Modi `off`/`observe`/`enforce`; F13–F15: Fehlermarke (jeder Modus), Persistenzfehler und ungültiges H (`observe`) sperren alle drei Endpunkte (E97); alle Ablehnungsgründe an allen drei schreibenden Endpunkten ohne Bestandsänderung und ohne Reload; t = H ohne Schreibvorgang, t > H fortgeschrieben; Fehlermarke; ungültiges H (Format, leer, Symlink, Verzeichnis); Domäne; `system/status` je Zustand; Prüfung erst nach dem `flock` (verspätete Anfrage gegen inzwischen erhöhtes H); CLI unter dem `flock`, nie senken, nur als Eigentümer |
| S | strace-Nachweis der Syscall-Reihenfolge (`flock` → Marke → Verzeichnis-`fsync` → H-Temp → `fsync` → `rename` → Verzeichnis-`fsync` → Marke entfernen → Verzeichnis-`fsync` → Änderung → `fsync` → Reload) je Endpunkt; Mitschnitte `strace_*_relevant.txt` |
| I | für alle drei Modi: Fehlerinjektion (`strace -e inject=…:error=…:when=N`) an **jedem** Datei-Syscall (`openat`, `read`, `write`, `fsync`, `rename`, `unlink`, `flock`) im Verzeichnisbaum je Endpunkt; Nachweis, dass die Injektion genau den erwarteten Schritt trifft; Invarianten: H nie gesenkt, keine Änderung ohne dauerhaftes Token, Erfolg nur bei vollständigem Zustand, Fehler mit Änderung nur gekennzeichnet (`rollback = failed` bzw. Cleanup mit `rndc`), nach Persistenzfehler Fehlermarke und fail-closed |
| C | für alle drei Modi: Absturz vor jedem dieser Schritte (`inject=…:signal=SIGKILL`) mit anschließendem Stromausfall (dm-flakey `drop_writes`, Neu-Einhängen) sowie Stromausfall nach bestätigtem Abschluss; Invarianten: H ∈ {alt, neu}, sichtbare Änderung ⇒ H dauerhaft ≥ Token, keine Mischzustände, `named.conf`/`zones.conf` gültig, bestätigte Änderung dauerhaft |
| G | G01: ausdrücklich `off` (mit und ohne Fencing-Felder im Body) gegenüber dem Agenten des Basis-Commits: HTTP-Status, Content-Type, Body, Dateibaum und Reload-Aufrufe je Schritt identisch; G02 (E118): ohne `fencing_mode` antworten `system/status`, `zone_sync`, `conf_sync` und `zone_cleanup` mit 503 `fence_misconfigured` ohne Änderung und ohne Reload, Authentifizierung und die übrigen Endpunkte unverändert. F09f: dasselbe in Gruppe F; Mutation M29 (fehlend = `off`) |
| L | Vollständigkeit der Verzeichnisauflistung (E98): 2000 Zonen, `getdents64`-Fehler im jeweils 2. Aufruf **beider** Durchläufe (`strace -P` auf das Verzeichnis) für `conf_sync` und `zone_cleanup` – a mit vollständigem Agenten, b mit neutralisiertem `scandir`/`glob`-Abgleich (der Vollständigkeitsnachweis muss allein greifen); verkürztes Lesen der bisherigen `zones.conf` (`read` EIO); verwaiste Include-Zeile; L7/L8: nicht eingebundene `.conf`-Dateien und nur der `glob`-Durchlauf verkürzt (hier greift allein der Abgleich `scandir`/`glob`, S43); Gegenproben ohne Fehlalarm |
| X | nicht prüfbare Existenz (E99): `stat`/`access` mit EIO (`strace -P`) auf Fehlermarke (vorhanden und fehlend, alle Modi × alle Endpunkte), H (`observe`/`enforce`), bestehende `<zone>.conf`/`zones.conf` und verwaiste Zonendatei im Cleanup; X7 ohne `ext-posix` (E100: alle Modi × Endpunkte, Status, CLI, `pathState()` ohne Rückfall); X8 verkürzter Read von H mit syntaktisch gültigem Präfix (`fstat` per `strace` `poke_exit` um 3 Bytes größer als der Inhalt; `observe`/`enforce` × alle Endpunkte und `system/status`, Gegenprobe ohne Injektion); Gegenprobe |
| M | Mutationsproben M01–M28; jede wird in einem eigenen Lauf mit den zugeordneten Gruppen ausgeführt und muss mindestens einen Fehlschlag erzeugen |

Ergebnisse: `as1_test.log` (eine Zeile je Fall), Mitschnitte und Golden-Daten im Ausgabeverzeichnis. Laufzeit auf ns2
etwa 30–60 Minuten für alle Gruppen.

## AS2a – `as2a_pure_test.php`, `as2a_test.py`, `as2a_as1_regression.sh`

Abnahme der Serial-Regel und der Zustandsprüfung vor der Übernahme (`docs/ARCHITECTURE_HISTORY.md`, Abschnitt 5f).

**Reine Tests** (ohne BIND, ohne Netzwerk):

```bash
php tests/agent/as2a_pure_test.php <Checkout>
```

| Gruppe | Inhalt |
|---|---|
| R | RFC-1982-Vektoren aus `tests/rfc1982_vectors.json` (Parsen, `newer`/`older`/`equal`/`undefined`, ungültige Werte mit Exception) |
| Z | Serial aus der Ausgabe von `named-checkzone` (genau eine Zeile) |
| P | strenger `dig`-Parser mit Ausgaben von BIND 9.20 (NOERROR/NXDOMAIN/REFUSED/Referral, Timeout, Verbindungsfehler, abgeschnittene und widersprüchliche Ausgaben) |
| K | Parser für `named-checkconf -px` und eindeutige Pfadauflösung (`..`, `.`, `//`, relativ ohne `directory`) |
| A | Vorrangregel der aggregierten Cleanup-Wirkung (Beispiel 3 entfernt / 1 bleibt / 1 unbekannt ⇒ `partial`) |
| L | Sperrfrist: Anfragebeginn 20 s zurück, Sperre frei ⇒ `timeout` ohne Sperrversuch, keine Sperre gehalten |
| KL | Klassifizierer im Core (E113) einschließlich aller widersprüchlichen Kombinationen und der unveränderten Einordnung ohne `effect` |

**Live-Suite** gegen die **installierte** BIND-Instanz einer Test-VM (root; kein zusätzlicher `named`, kein eigener
DNS-Port). Der Agent-Code des Prüfstands läuft als www-data über `php -S` mit einer Testkonfiguration auf das echte
`/etc/bind`; Testzonen tragen das Suffix `as2a.test` bzw. `as2a-solo.test`, Konfigurationsvarianten liegen in
`/etc/bind/named.conf.as2a`. Vorher wird `/etc/bind` unter `/root/as2a-test-<Zeit>/` gesichert; am Ende stellt die Suite
den Ausgangszustand wieder her und weist ihn nach (Gruppe Z).

```bash
python3 tests/agent/as2a_test.py --src <Checkout des Prüfstands> --ref <Checkout des Basis-Commits> --out <Ergebnisse> [--groups EKDOCTGM]
```

Die Gruppen laufen in der angegebenen Reihenfolge; K, O, C und T setzen den Bestand aus E voraus (Gruppen G und M sind
eigenständig).

| Gruppe | Inhalt |
|---|---|
| E | Matrix nach E103, Zeilen 1–11: Neuinstallation, `newer`, idempotent (kein `rename`, ein Reload), `serial_conflict`, `stale_serial`, `serial_undefined`, Datei neuer als ausgeliefert, Fall B (Datei gelöscht, Zone geladen), Rest einer gelöschten Zone, ungültige Datei mit und ohne passendes Reparaturkennzeichen, Symlink/Verzeichnis/nicht lesbar, SERVFAIL ohne Reparaturmöglichkeit; jede Ablehnung mit Nachweis „Datei, Konfiguration und DNS-Antwort unverändert“ |
| K | Konfigurationsnachweis: fremder Dateipfad mit gleicher Serial, relativer Pfad, `secondary`, zusätzliche Option, eigene `allow-query`, Pfad mit `..`, Zonenzahl weicht ab (Konfiguration ohne Reload), Widerspruch Zeile 4, `allow-new-zones` und die danach liegen gebliebene NZD-Datei, NZD im `directory`, NZF im (auch relativen) `new-zones-directory`, Views, maskierter Schlüssel nicht in Antwort oder Log |
| D | ausgelieferter Zustand: Elternzone NXDOMAIN, Delegation, Eltern-ACL (REFUSED ⇒ unknown), Kontrollzone fehlt bzw. mit eigener ACL, `system/status`, DNS-Timeout |
| O | `observe`: Serial-Gründe `would_reject` mit Übernahme, Zustandsfehler weiter abgelehnt |
| C | named vor der Prüfung gestoppt (keine Änderung), `reload_failed` und `reload_timeout` nach der Übernahme (Fenster per verzögertem `rename`; Datei bleibt, nächster Reload lädt vorwärts, keine verwaisten Prozesse), Steuerkanal vor der Prüfung blockiert, `persist_unconfirmed` mit `rollback = not_attempted`, `conf_sync`-Rollback ⇒ `effect = none`, `conf_sync` mit `reload_timeout`, Cleanup mit fünf Zonen (unveränderlich bzw. `stat` EIO) ⇒ `partial` je Zone und im Klassifizierer |
| T | Fristen: langsames `named-checkconf` (Nachweis und Preflight mit Rollback), langsames `named-checkzone` (8 s), belegte Agent-Sperre (`lock_timeout` nach etwa 16 s), T05 Reserve unterschritten bei **freier** Sperre (erster `fsync` per `strace` um 17 s verzögert ⇒ `lock_timeout`), T06–T10 `system/status` regulär, mit blockiertem `rndc`-Steuerkanal, blockiertem DNS, beidem und verzögertem `named-checkconf` (⇒ 503 bzw. `status_timeout` innerhalb der Frist), T16–T19 (E118) fehlender `fencing_mode`: alle vier Endpunkte 503 `fence_misconfigured` ohne externen Prozess, ohne Änderung und ohne Reload, T20 danach regulär, T11–T15 `system/status` fail-closed: `observe`/`enforce` ohne `posix` (`-d disable_functions=…` nur für `php -S`, wie AS1 X7) ⇒ `agent_requirement_missing`, unbekannter `fencing_mode` ⇒ `fence_misconfigured`, jeweils ohne gestarteten externen Prozess (`strace -f -e trace=execve`; T11 Gegenprobe), keine verwaisten Prozesse, jeder Aufruf in `observe`/`enforce` < 25 s |
| G | Modus `off` gegenüber dem Agenten des Basis-Commits: Neuinstallation, `conf_sync`, ungültige Zone, ältere Serial, Preflight-Fehler mit Rollback, Cleanup, Steuerkanal blockiert (ohne Frist), named gestoppt, langsames `named-checkconf`, `persist_unconfirmed` (`rollback = failed` wie bisher), `system/status` (G11), `system/status` ohne `posix` (G12, bisheriger Ablauf; G13 startet weiterhin externe Programme) – HTTP-Status, Body und Dateien identisch |
| M | Mutationsproben M01–M17 (M17 fehlender `fencing_mode` als `off`; M13 `system/status` mit unbeschränktem `rndc status`, M14 Reserve erst nach dem Sperrversuch geprüft, M15/M16 `system/status` ohne fail-closed bei unbekanntem Modus bzw. fehlendem `posix`; Halbkreis als `newer`, Vorher-Prüfung entfernt, Pfadprüfung entfernt, NZD-Prüfung entfernt, Zonenzahl ignoriert, SERVFAIL als nicht geladen, REFUSED der Elternzone akzeptiert, Rollback nach Reload-Fehler, Teilzustand als `none`, `rndc` ohne Frist, Kontrollzone mit eigener ACL akzeptiert, Reparatur ohne Hashprüfung); jede muss mindestens einen Fehlschlag erzeugen |
| Z | Rückbau: `/etc/bind` sha256- und rechtegleich, `/var/cache/bind` ohne Reste, Serials, `iptables`, `named-checkconf`, keine verwaisten Prozesse, keine Fencing-Dateien |

Hinweise aus den Messungen: `strace -P` erfasst `rename` nicht über den Zielpfad (deshalb `-e trace=rename,…`); schon
das Setzen von `allow-new-zones yes` legt `_default.nzd` an, die nach dem Abschalten liegen bleibt.

**AS1-Regression** gegen den AS2a-Prüfstand (unveränderte AS1-Suite; nur `agentPreTransferGate()` wird in einer Kopie
neutralisiert, weil die Datei-Sandbox keinen passenden BIND hat):

```bash
bash tests/agent/as2a_as1_regression.sh <Prüfstand> <Referenz-Checkout Basis> <Ergebnisse> [FSICLXGM]
```

## AS2b – `as2b_pure_test.php`, `as2b_test.py`, `as2b_as1_regression.sh`

Abnahme der Nachverifikation nach dem Reload und des Nachweises der Entfernung (`docs/ARCHITECTURE_HISTORY.md`,
Abschnitt 5g).

**Reine Tests** (ohne BIND; die Gruppen C, S, L und BU verwenden kleine Ersatzprogramme für `dig`, `named-checkconf`,
`named-checkzone` und `rndc` in einem temporären Verzeichnis und brauchen Linux, root, `setsid` und `ext-posix`):

```bash
php tests/agent/as2b_pure_test.php <Checkout> [Gruppen]
```

| Gruppe | Inhalt |
|---|---|
| B, W, P | Rundenfolge (50 ms verdoppelt bis 500 ms), Fensterende min(Reload-Ende + 10 s, TW − 1 s), Polling-Steuerung mit simulierter Uhr (keine Runde und keine Wartezeit nach dem Fensterende) |
| DL, DR | Entscheidungsmatrizen `conf_sync` und Cleanup (SERVFAIL, Timeout und Unbestimmtes nie positiv; `absent` nur mit Reload-Erfolg, K = nein und not_auth) |
| F | Endergebnis nach R3/R4 (letzte vollständige Runde; unvollständige Runde ersetzt sie nicht; `still_served` bei K = ja nie aus Runde 1) |
| N | Zonennamen aus dem Konfigurationsnachweis (eindeutig, Großbuchstaben, Punkt am Ende, fremder Pfad, unbestimmbar) |
| C | Kontrollzonen-Speicher: eine Abfrage je Runde für mehrere Zonen, neue Runde bzw. anderer Nachweis ⇒ neue Abfrage, ohne Speicher wie bisher; `control_queries` zählt nur tatsächlich gestartete dig-Prozesse der Kontrollabfrage (Kontrollzone fehlt, eigene ACL, verwaltet bzw. nicht prüfbar ⇒ keine Abfrage, 0; Prozess wegen der Frist nicht gestartet ⇒ 0; gestartet mit Zeitüberschreitung ⇒ 1) |
| S | Abschnittsfrist in `proc.php` (Prozessabbruch, Verschachtelung, Wiederherstellung, nie über TW) |
| L | Abläufe `verifyLoaded()`/`verifyRemoved()` mit Antwortfolgen (mehrere Runden, R4, K vorübergehend, kein Übertrag über Runden, Reload gescheitert) |
| BU | Vorabnachweis: Restzeit knapp unter/über 20 s, größter Verbrauch innerhalb der Obergrenzen, hängender Nachweis, späte Sperre; Restzeit danach ≥ 9 s |

**Live-Suite** gegen die installierte BIND-Instanz (wie AS2a über `php -S` mit dem Agent-Code des Prüfstands; Hilfsfunktionen
aus `as2a_test.py`; Testzonen `*.as2b.test`/`*.as2b-solo.test`; Sicherung unter `/root/as2b-test-<Zeit>/`):

```bash
python3 tests/agent/as2b_test.py --src <Checkout des Prüfstands> --out <Ergebnisse> [--groups VNRBM]
```

| Gruppe | Inhalt |
|---|---|
| V | `conf_sync` ⇒ `verified`: neue Zone, Änderung, Unterzone, Zone mit 300 000 RR (nach Ladeabschluss), nach abgelehntem `zone_sync` (gegen die verwaltete Datei), `observe`; Antwortvertrag und Klassifizierung unverändert |
| N | ohne positiven Nachweis: Datei ungültig bzw. fehlt, andere Serial (`serial_differs` am Fensterende), SERVFAIL, K strukturell, DNS blockiert, Reload gescheitert (keine Abfrage), Großbuchstaben |
| R | Cleanup: `absent` ohne/mit Elternzone (eine Runde, eine Kontrollabfrage), große Zone, Zone weiter konfiguriert, Reload gescheitert (`still_served`, nie `absent`), Kontrollzone fehlt (keine Kontrollabfrage gezählt), Großbuchstaben, DNS blockiert, 30 Zonen mit gemeinsamem Fenster |
| B | Zeitbudget: Restzeit beim Sperrerwerb knapp unter/über 20 s (Sperre extern gehalten), größter Verbrauch des Vorabnachweises, hängender Vorabnachweis, späte Sperre mit blockiertem DNS, Sperre während der Nachverifikation gehalten; B99 jeder Aufruf < 25 s |
| M | Mutationsproben MV01–MV19; je Mutation das zugeordnete Live-Szenario und die zugeordneten reinen Gruppen gegen die mutierte Kopie; mindestens ein Fehlschlag erforderlich |
| Z | Rückbau wie AS2a (Z01–Z08) |

**AS1-Regression** gegen den AS2b-Prüfstand: wie AS2a, zusätzlich werden die AS2b-Einstiegspunkte (`agentVerifyLoaded`,
`agentCleanupNames`, `agentVerifyRemoved`) in der Kopie durch „unknown ohne Abfrage“ ersetzt:

```bash
bash tests/agent/as2b_as1_regression.sh <Prüfstand> <Referenz-Checkout Basis> <Ergebnisse> [FSICLXGM]
```

Die AS2a-Live-Suite läuft unverändert gegen den AS2b-Prüfstand (`--ref` = Basis-Commit für den `off`-Vergleich G). Für die
AS1-Gruppe G (und die darüber erkannten Mutationen M15/M29) muss die Referenz ein Stand **vor** E118 sein (fehlender
`fencing_mode` = `off`, z. B. `81ccd70`); mit einer Referenz ab E118 weicht bereits der Referenzlauf ab.

**Stufentest** über den installierten Core (`as2b_stage.php`, als www-data auf dem Core-System):

```bash
php tests/agent/as2b_stage.php /usr/share/dnsmanager publish-all
php tests/agent/as2b_stage.php /usr/share/dnsmanager publish-zone <Zone> [Sperr-Wartezeit]
php tests/agent/as2b_stage.php /usr/share/dnsmanager conf-sync <Servername> <Zone>
php tests/agent/as2b_stage.php /usr/share/dnsmanager cleanup <Servername>
```
