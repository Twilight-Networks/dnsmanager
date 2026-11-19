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
| G | `off` (ohne Schlüssel, ausdrücklich `off`, mit Fencing-Feldern im Body) gegenüber dem Agenten des Basis-Commits: HTTP-Status, Content-Type, Body, Dateibaum und Reload-Aufrufe je Schritt identisch |
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
| T | Fristen: langsames `named-checkconf` (Nachweis und Preflight mit Rollback), langsames `named-checkzone` (8 s), belegte Agent-Sperre (`lock_timeout` nach etwa 16 s), T05 Reserve unterschritten bei **freier** Sperre (erster `fsync` per `strace` um 17 s verzögert ⇒ `lock_timeout`), T06–T10 `system/status` regulär, mit blockiertem `rndc`-Steuerkanal, blockiertem DNS, beidem und verzögertem `named-checkconf` (⇒ 503 bzw. `status_timeout` innerhalb der Frist), keine verwaisten Prozesse, jeder Aufruf in `observe`/`enforce` < 25 s |
| G | Modus `off` gegenüber dem Agenten des Basis-Commits: Neuinstallation, `conf_sync`, ungültige Zone, ältere Serial, Preflight-Fehler mit Rollback, Cleanup, Steuerkanal blockiert (ohne Frist), named gestoppt, langsames `named-checkconf`, `persist_unconfirmed` (`rollback = failed` wie bisher), `system/status` – HTTP-Status, Body und Dateien identisch |
| M | Mutationsproben M01–M14 (M13 `system/status` mit unbeschränktem `rndc status`, M14 Reserve erst nach dem Sperrversuch geprüft; Halbkreis als `newer`, Vorher-Prüfung entfernt, Pfadprüfung entfernt, NZD-Prüfung entfernt, Zonenzahl ignoriert, SERVFAIL als nicht geladen, REFUSED der Elternzone akzeptiert, Rollback nach Reload-Fehler, Teilzustand als `none`, `rndc` ohne Frist, Kontrollzone mit eigener ACL akzeptiert, Reparatur ohne Hashprüfung); jede muss mindestens einen Fehlschlag erzeugen |
| Z | Rückbau: `/etc/bind` sha256- und rechtegleich, `/var/cache/bind` ohne Reste, Serials, `iptables`, `named-checkconf`, keine verwaisten Prozesse, keine Fencing-Dateien |

Hinweise aus den Messungen: `strace -P` erfasst `rename` nicht über den Zielpfad (deshalb `-e trace=rename,…`); schon
das Setzen von `allow-new-zones yes` legt `_default.nzd` an, die nach dem Abschalten liegen bleibt.

**AS1-Regression** gegen den AS2a-Prüfstand (unveränderte AS1-Suite; nur `agentPreTransferGate()` wird in einer Kopie
neutralisiert, weil die Datei-Sandbox keinen passenden BIND hat):

```bash
bash tests/agent/as2a_as1_regression.sh <Prüfstand> <Referenz-Checkout Basis> <Ergebnisse> [FSICLXGM]
```
