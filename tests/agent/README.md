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
python3 tests/agent/as1_test.py --src <Checkout des Prüfstands> --ref <Checkout des Basis-Commits> --out <Ergebnisse> [--groups FSICGM]
```

| Gruppe | Inhalt |
|---|---|
| F | Modi `off`/`observe`/`enforce`; F13/F14: Fehlermarke und Persistenzfehler sperren in **jedem** Modus alle drei Endpunkte (E97); alle Ablehnungsgründe an allen drei schreibenden Endpunkten ohne Bestandsänderung und ohne Reload; t = H ohne Schreibvorgang, t > H fortgeschrieben; Fehlermarke; ungültiges H (Format, leer, Symlink, Verzeichnis); Domäne; `system/status` je Zustand; Prüfung erst nach dem `flock` (verspätete Anfrage gegen inzwischen erhöhtes H); CLI unter dem `flock`, nie senken, nur als Eigentümer |
| S | strace-Nachweis der Syscall-Reihenfolge (`flock` → Marke → Verzeichnis-`fsync` → H-Temp → `fsync` → `rename` → Verzeichnis-`fsync` → Marke entfernen → Verzeichnis-`fsync` → Änderung → `fsync` → Reload) je Endpunkt; Mitschnitte `strace_*_relevant.txt` |
| I | für alle drei Modi: Fehlerinjektion (`strace -e inject=…:error=…:when=N`) an **jedem** Datei-Syscall (`openat`, `read`, `write`, `fsync`, `rename`, `unlink`, `flock`) im Verzeichnisbaum je Endpunkt; Nachweis, dass die Injektion genau den erwarteten Schritt trifft; Invarianten: H nie gesenkt, keine Änderung ohne dauerhaftes Token, Erfolg nur bei vollständigem Zustand, Fehler mit Änderung nur gekennzeichnet (`rollback = failed` bzw. Cleanup mit `rndc`), nach Persistenzfehler Fehlermarke und fail-closed |
| C | für alle drei Modi: Absturz vor jedem dieser Schritte (`inject=…:signal=SIGKILL`) mit anschließendem Stromausfall (dm-flakey `drop_writes`, Neu-Einhängen) sowie Stromausfall nach bestätigtem Abschluss; Invarianten: H ∈ {alt, neu}, sichtbare Änderung ⇒ H dauerhaft ≥ Token, keine Mischzustände, `named.conf`/`zones.conf` gültig, bestätigte Änderung dauerhaft |
| G | `off` (ohne Schlüssel, ausdrücklich `off`, mit Fencing-Feldern im Body) gegenüber dem Agenten des Basis-Commits: HTTP-Status, Content-Type, Body, Dateibaum und Reload-Aufrufe je Schritt identisch |
| L | Vollständigkeit der Verzeichnisauflistung (E98): 2000 Zonen, `getdents64`-Fehler im jeweils 2. Aufruf **beider** Durchläufe (`strace -P` auf das Verzeichnis) für `conf_sync` und `zone_cleanup` – a mit vollständigem Agenten, b mit neutralisiertem `scandir`/`glob`-Abgleich (der Vollständigkeitsnachweis muss allein greifen); verkürztes Lesen der bisherigen `zones.conf` (`read` EIO); verwaiste Include-Zeile; Gegenproben ohne Fehlalarm |
| M | Mutationsproben M01–M23; jede wird in einem eigenen Lauf mit den zugeordneten Gruppen ausgeführt und muss mindestens einen Fehlschlag erzeugen |

Ergebnisse: `as1_test.log` (eine Zeile je Fall), Mitschnitte und Golden-Daten im Ausgabeverzeichnis. Laufzeit auf ns2
etwa 30–60 Minuten für alle Gruppen.
