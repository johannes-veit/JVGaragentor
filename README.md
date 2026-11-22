# JV Garagentor v1.2.0 – IP-Symcon 9.0

Eigenständige IP-Symcon-Bibliothek mit **zwei getrennten Gerätemodulen**:

- **JVGaragentor**: AUF / STOP / ZU über Shelly Pro 3, Endlagen- und Timeoutüberwachung, mobile Kachel mit großen Bedientasten und eigener farbiger Positionsanzeige.
- **JVAmpel**: übernimmt **ausschließlich** das vom Tormodul berechnete Ampel-Signal (0–4) und schaltet darüber WLED vorne. Kein Torbefehl möglich.

Die Architektur wurde anhand der Schiebetor-Skripte und der Visualisierungsverknüpfungen aus dem SymBox-Backup vom 07.11.2026 vereinfacht. Das bestehende Schiebetor wird nicht verändert.

## Zeiten und Signale (voreingestellt, editierbar)

| Tatsächlicher Zustand | Positionstext in IP-Symcon | Ampel-Signal | WLED vorne | Beleuchtung der Positionskachel | Dauer |
|---|---|---:|---|---|---|
| AUF-Befehl erfolgreich | `LÄUFT AUF` | 1 | Preset 1: gelb pulsierend | **Gelb, sanft pulsierend** | Bis obere Endlage, maximal **120 s** |
| Obere Endlage bestätigt | `AUF` | 2 | Preset 2: grün statisch | **Grün leuchtend** | **10 s** |
| ZU-Befehl erfolgreich | `LÄUFT ZU` | 3 | Preset 3: rot pulsierend | **Rot, sanft pulsierend** | Bis untere Endlage, maximal **120 s** |
| Untere Endlage bestätigt | `ZU` | 4 | Preset 4: rot statisch | **Rot leuchtend** | **5 s** |
| STOP, Timeout oder abgelaufene Nachleuchtzeit | `GESTOPPT` bzw. weiter `AUF` oder `ZU` | 0 | WLED **AUS** | **Neutral, keine Beleuchtung** | Sofort bzw. nach Ablauf |

**Alle Zeiten werden ausschließlich in der JVGaragentor-Torsteuerungsinstanz bearbeitet** (nicht in JVAmpel):

- `MovementTimeoutSeconds`: Default **120 Sekunden**, 5–120 Sekunden; eine Konfiguration oberhalb 120 wird im Code auf 120 begrenzt.
- `OpenHoldSeconds`: Default **10 Sekunden**, 0–120 Sekunden.
- `CloseHoldSeconds`: Default **5 Sekunden**, 0–120 Sekunden.
- `BlinkPeriodMs`: Default **1600 Millisekunden** je Pulszyklus, 500–4000 ms; verändert **nur die Smartphone-Kachel**, nicht die WLED-Presetgeschwindigkeit.

**Bei Ende der Nachleuchtzeit geht die WLED-Ampel aus und der Farbhintergrund der Kachel wird wieder weiß.** Der Positionstext bleibt `AUF` oder `ZU`, bis eine neue Meldung oder ein neuer Befehl vorliegt.

Die Tor-Instanz besitzt dazu eine zusätzliche Integer-Variable **„Ampel-Signal“** (0=AUS, 1=gelb pulsierend, 2=grün, 3=rot pulsierend, 4=rot statisch). Sie ist der **einzige** Eingang der separaten JVAmpel-Instanz. Dieselbe Information mit Ablaufzeit steuert die Positionskachel. Auch ohne eine Statusmeldung wird die Browser-Farbe am Ablaufzeitpunkt lokal ausgeblendet. Beim ersten Modulstart wird eine bereits anliegende Endlage **ohne künstliches Nachleuchten** übernommen.

**Wichtige Abgrenzung:** Ablauf der maximalen 120 Sekunden bedeutet, dass die **Softwareanzeige** auf `GESTOPPT` geht, die Ampel ausgeschaltet wird und weitere Fahrbefehle bis zu STOP gesperrt sind. Das ist **kein elektrischer Stopp und kein Nachweis, dass der Antrieb tatsächlich steht**. Die Marantec CS320-FU ist separat sicherheitstechnisch auszulegen. Bei Netzwerk- oder WLED-Ausfall ist das Ausschalten der realen LED nicht garantiert. Die Ampel ist nur eine zusätzliche Informationsanzeige, keine Sicherheitseinrichtung.

## Installation / Aktualisierung

1. **IP-Symcon → Module Control → Hinzufügen**. Quelle: `https://github.com/johannes-veit/JVGaragentor`, Branch **`main`**. Bei bestehender Installation die Bibliothek aktualisieren.
2. Die Geräteinstanzen **JVGaragentor** und **JVAmpel** anlegen bzw. bestehende Instanzen übernehmen.
3. **JVGaragentor:** Shelly-Variablen kontrollieren, **beide tatsächlichen Endlagenrückmeldungen** zuweisen und die Laufzeit-/Nachleuchtzeiten einstellen. Die Toransteuerung ist standardmäßig gesperrt.
4. **JVGaragentor-Kachel:** In die Symcon-Kachelvisualisierung einbinden. Linke Spalte: große AUF-/STOP-/ZU-Tasten; rechte Spalte: Status und synchron zur Ampel animierter Farbhintergrund.
5. **JVAmpel:** Das Modul verbindet sich **automatisch** mit der Integer-Variable „Ampel-Signal“ der Torsteuerung, wenn genau **eine** JVGaragentor-Torinstanz vorhanden ist. Ein leerer manueller Eingang bedeutet **automatische Erkennung**, kein Fehler. Bei mehreren Torinstanzen muss die gewünschte Integer-Variable ausdrücklich ausgewählt werden. Die String-Variable „Position“ ist nicht geeignet.
6. WLED-Ausgänge prüfen: vorne Presets **#46199**, Ein/Aus **#29605**. Den hinteren Controller nicht doppelt ansteuern, da er über WLED-Synchronisierung folgt. **Vor Freigabe prüfen, ob alle vier Preset-/Effektwechsel auch hinten ankommen.**
7. Nach realem Prüfablauf für Ampel und Tor **getrennt** die Automatikfreigaben setzen. Bis dahin beide deaktiviert lassen.

**Kein Hardwaretest möglich:** Das Modul ist mit PHP-Mocks und JavaScript-Tests geprüft, aber nicht auf der echten SymBox und nicht mit der realen CS320-FU gefahren. Der Shelly Pro 3 wurde zuletzt als elektrisch noch nicht an die CS320 angeschlossen gemeldet. Daher keine Freigabe zum automatischen Torbetrieb ohne reale Abnahme.

## Vorbelegte Symcon-IDs

Shelly Pro 3 Garagentor: Instanz **22849**, IP `192.168.103.73`.

| Befehl | Shelly | Symcon-Variable | Impuls |
|---|---|---:|---|
| AUF | O1, `Status` | **44228** | EIN, Auto-OFF nach 1 s |
| ZU | O2, `Status 1` | **56652** | EIN, Auto-OFF nach 1 s |
| STOP | O3, `Status 2` | **55944** | AUS, Auto-ON nach 1 s |
| Erreichbar | – | **17585** | Boolean |

**Die zwei Endlagen-IDs fehlen weiterhin.** Sie müssen jeweils eine Boolean-Variable darstellen, die **TRUE** meldet, wenn die zugehörige Endlage erreicht ist. Keines der vorhandenen Shelly-Eingangssignale ist bisher als Endlage bestätigt. Deshalb bleiben Fahrbefehle ohne eindeutige Endlagensignale standardmäßig gesperrt.

## WLED-Ampel

- WLED Garage vorne: **192.168.103.91**, Instanz **17732**, Preset **46199**, Power **29605**.
- WLED Garage hinten: **192.168.103.92**, Instanz **31076**, folgt geräteseitig via WLED-Synchronisierung.
- Presets in WLED-Master über **„Voreinstellungen“** einblenden. Diese Integer-Variable verarbeitet die Werte 1, 2, 3 und 4. Die Werte 0 und 5 werden für das Tor nicht verwendet.

Die Blinkfrequenz der realen WLED-Ampel wird innerhalb der Presets festgelegt. Die Handy-Kachel verwendet dieselben **Farben und Zustandsdauern**, nicht zwangsläufig exakt dieselbe Blinkphase.

## LCN-GT2 (für spätere Inbetriebnahme vorbereitet)

Die zentrale Befehlsschnittstelle kann sowohl von der Kachel als auch von Skripten aufgerufen werden:

```php
JVG_Command($garagentorInstanzID, 'ZU');  // später kurzer GT2-Tastendruck
JVG_Command($garagentorInstanzID, 'AUF'); // später langer GT2-Tastendruck
JVG_Command($garagentorInstanzID, 'STOP');
```

Die konkrete Zuordnung des LCN-GT2 erfolgt erst nach Bekanntgabe der Tastenobjekte. Beim fahrenden Tor wird ein Richtungswechsel ohne vorherigen STOP gesperrt.

## Sicherheitsgrenzen

Das Modul überprüft Freigabe, Onlinezustand, schaltbare Relais, eindeutig zugeordnete IDs, Endlagen, Richtungsumkehr und Impulsrückfall. Nach Timeout wird die Anzeige `GESTOPPT`, das WLED-Signal 0 und bis zu einem STOP-Befehl keine weitere Fahrt angenommen. **Bei widersprüchlichen Endlagen bleibt die Bedienung gesperrt**.

Kein Ersatz für die CS320-Sicherheitseinrichtungen: Lichtgitter, Schaltleisten, Endlagen, Not-Halt und motorseitige Fahrzeitüberwachung müssen hardwareseitig funktionieren. **Eine zeitbasierte Anzeige darf nie als sicherer Stillstand oder als bestätigte Endlage interpretiert werden.**

## Prüfungen

```sh
php -l JVGaragentor/module.php
php -l JVAmpel/module.php
php tests/simulate.php
node tests/visual_mock.js
```

GitHub Actions prüft zusätzlich JSON und die JavaScript-Syntax. Reale Endlagen, Controller-Verfügbarkeit, WLED-Preset-Synchronisierung und die elektrische Sicherheit müssen vor dem Echtbetrieb vor Ort geprüft werden.

## Automatische Konfiguration (v1.1.1)

- Vorbelegt: Shelly O1 AUF **44228**, O2 ZU **56652**, O3 STOP **55944**, Online **17585**.
- Vorbelegt: WLED Garage vorne Presets **46199**, Ein/Aus **29605**; hinten synchronisiert geräteseitig.
- Die **Ampel-Signal-Variable** wird automatisch aus der **einzigen** JVGaragentor-Instanz ermittelt. Ihre Objekt-ID ist erst nach Erstellung dieser Instanz bekannt. Die Verbindung wird auch dann gefunden, wenn die Torinstanz später angelegt wird (10-Sekunden-Timer).
- Bei mehreren Torinstanzen oder einer bewusst gesetzten, aber ungültigen manuellen Variable erfolgt **keine** automatische Wahl irgendeiner Quelle.
- **Nicht automatisch belegbar:** Endlage AUF und Endlage ZU – echte, eindeutige Rückmeldungen der CS320 fehlen bisher; Standard-Sicherheitssperre bleibt aktiv. Fahrbefehle erst nach realer Prüfung freigeben.
- **Keine Doppelung der Zeiten:** 120/10/5 Sekunden und Smartphone-Puls stehen im Konfigurationsformular von **JVGaragentor (Torsteuerung)**. JVAmpel hat keine eigenen Zeitwerte.

## Fehlerkorrektur v1.1.2 – Torsteuerungsinstanz im Instanzdialog

Die bisher intern definierte Methode `SetStatus(string, int, int)` kollidierte mit der öffentlichen und von IP-Symcon bereitgestellten Basisklassenmethode `SetStatus(int)`. Dies verhindert das Laden der Klasse `JVGaragentor` in IP-Symcon und erklärt, weshalb unter **Instanz hinzufügen** nur **JV Garagentor Ampelsteuerung** angeboten wurde.

Die interne Zustandsfunktion heißt ab v1.1.2 `UpdateGateState`. Die Testumgebung bildet die reale Basisklassenmethode nun nach, sodass diese konkrete Regression im CI-Test einen PHP-Klassenladefehler auslöst und künftige Veröffentlichungen blockiert. Nach Aktualisierung der GitHub-Bibliothek auf **main/v1.1.2** im Symcon Module Control sollten unter Hersteller **JV** beide Gerätetypen zur Auswahl stehen: **JVGaragentor** und **JV Garagentor Ampelsteuerung**. Die Ampelinstanz nicht löschen oder doppelt anlegen. Der Hardwarebetrieb bleibt weiterhin bis zur Endlagen- und CS320-Prüfung gesperrt.

## Korrektur v1.1.3 – Positionsanzeige auf Smartphones

- Die rechte Positionsspalte verwendet jetzt eine nach der tatsächlichen Spaltenbreite skalierende Schrift.
- `GESTOPPT` wird **kleiner, zentriert und einzeilig** dargestellt, damit es nicht mehr rechts aus dem Feld ragt.
- `LÄUFT AUF` und `LÄUFT ZU` werden über zwei zentrierte Zeilen mit angepasster Schriftgröße angezeigt.
- `AUF` und `ZU` bleiben bewusst groß. Bestehende Ampel-, Farb-, Zeitschalt- und Shelly-Funktionen wurden nicht verändert.
- Drei zusätzliche Visualisierungs-Regressionstests prüfen die Schriftklassen und die Breitenbegrenzung.

## LCN-GT2: Zwei Tasten für Garagentor und Schiebetor (v1.2.0)

Die Torinstanz `JVGaragentor` hat nun unten zwei unabhängige Kanäle:
- **Taste 1 – Garagentor**: LCN-Modul wählen (z. B. M51), die dazugehörige Stringvariable `Host-Kommando → Befehl` wählen, Tastentabelle A/B/C/D, Taste 1–8, Aktion für **Kurz**, **Lang**, **Los** wählen. Voreinstellung **C2**: kurz ZU, lang AUF, los keine Aktion.
- **Taste 2 – Schiebetor**: eigenes (oder dasselbe) LCN-Modul, eigene Host-Kommando-Variable (bei derselben Quelle dieselbe Variable auswählen), Tastentabelle/Taste sowie Kurz/Lang/Los. Voreinstellung **C1**: kurz ZU, lang AUF, los keine Aktion. Vorhandene Symcon-Skripte AUF #12119, ZU #26612, STOP #29055 sind als Standard aus dem Backup 07.11.2026 vorbelegt. Vor Freigabe aktuellen Objektbaum prüfen.

**Wichtige technische Korrektur:** Ein vollständig unprogrammierter LCN-Taster liefert dem IP-Symcon-LCN-Modul keinen eigenen Tastencode. In LCN-PRO **muss** auf der betreffenden Tastenfunktion ein Host-Kommando eingerichtet werden. Für jede genutzte Taste werden in LCN-PRO (Funktion *Freie Eingabe*, Ziel PCHK Host-ID, meist M004) die passenden `57 XX YY`-SKH-Kommandos hinterlegt. Der Taster benötigt damit **kein Tor als LCN-Ziel**, wohl aber diese reine Meldung an Symcon.

Beispiel für einen GT2 mit C-Tabelle:
| Taste | Ereignis | LCN-PRO freie Eingabe | Symcon-String `Befehl` |
|---|---|---|---|
| C1 | Kurz | `57 21 02` | `033002` |
| C1 | Lang | `57 21 01` | `033001` |
| C1 | Los | `57 21 00` | `033000` |
| C2 | Kurz | `57 22 02` | `034002` |
| C2 | Lang | `57 22 01` | `034001` |
| C2 | Los | `57 22 00` | `034000` |

Bei klassischen LCN-Tastentabellen gibt es **A1–A8, B1–B8, C1–C8 und D1–D8**. Beim GT12 verteilen sich zwölf physische Tasten auf mehrere Tabellen, es gibt keine A9–A12.

In Symcon: **LCN Konfigurator → das sendende LCN-Modul → Host-Kommando erstellen**; danach in der Torinstanz dessen Stringvariable `Befehl` auswählen. Die Modulwahl und die Befehl-Variable müssen zum **gleichen** LCN-Modul gehören. Die Software prüft vor der Auslösung die Objekt-Hierarchie. Ein Host-Kommando-Update löst ohne Polling die Auswertung aus. Fremde, fehlerhafte oder mehrdeutige Codes werden ignoriert.

**Diagnose ohne Bewegung:** Die LCN-Zuordnungen zunächst mit beiden Schaltern **deaktiviert** lassen. Trotzdem wird die Stringvariable bei Aktualisierung überwacht. Unter `LCN letzte Tastenmeldung` wird das erkannte Ereignis inklusive Tastenkennung angezeigt, aber noch kein Fahrbefehl gesendet. Erst wenn beide Tasten korrekt zugeordnet, Endanschläge/CS320 und Schutzfunktionen geprüft sind, den jeweiligen LCN-Tastenkanal freigeben.

**Torfahrten:** Garagentor-Kommandos laufen unverändert durch dieselbe `Command()`-Prüfung wie die Smartphone-Kachel (Freigabe, Relais, Online, ggf. Endlagen). Schiebetor-Kommandos starten **ausschließlich** die bisherigen IP-Symcon-Skripte, niemals direkt das Shelly-Relais. Beide Kanäle sind standardmäßig **AUS**; identische Mehrfachmeldungen innerhalb 500 ms werden unterdrückt.

Das LCN-Host-Kommando ist kein zertifizierter Sicherheitseingang. Für Torbewegungen, insbesondere Schließen aus Bereichen ohne Sicht auf das Tor, müssen die CS320 und die Schiebetorsteuerung ihre erforderlichen Sicherheitseinrichtungen eigenständig bereitstellen.

### Freie Eingabe in LCN-PRO

Im normalen Kommandodialog gibt es **keinen** Eintrag „Sende Kommando an Host“. Für SKH muss im Menü **Optionen → Einstellungen → Rechte & Lizenzen** der Administratormodus aktiviert werden. Erst danach steht das Kommando **Freie Eingabe** zur Verfügung. Die Codes `57 XX YY` sind dabei Hex-Bytes. Die Anleitung findest du unter https://wiki.my-gekko.com/de/index/installation_5/lcnlegacy/anhang_1/lcn-tastenpr/lcn-tastenpr.html.

Das normale Kommando **Sende Tasten** ist kein nachgewiesener Ersatz für SKH in der IP-Symcon Host-Kommando-Stringvariablen-Schnittstelle; deshalb wird es von diesem Modul nicht als Eingabe vorausgesetzt.

**GT2-Belegung:** Laut LCN-Installationsanleitung verwendet der GT2 ab Werk eigene Peripherietasten. Zur Bedienung über C1/C2 muss das GT2-Tastenfeld ausdrücklich auf die C-Tabelle umgestellt werden. Bei unveränderter Peripherietasten-Konfiguration müssen die zugehörigen SKH-Codes auf den entsprechenden physischen Tasten parametriert werden. Die Einstellung `C1`/`C2` im JV-Modul beschreibt die per SKH gesendeten Codekennungen und muss nicht zwingend der ursprünglichen Tastentabelle entsprechen.

**Verbindliche Voreinstellung nach deiner LCN-PRO-Aufnahme:** C1 = Schiebetor, C2 = Garagentor. Die beiden Felder werden entsprechend vorausgewählt. Das erste SKH-Kommandofeld für C1 KURZ lautet `57 21 02`. Vor der Auslösung müssen die LCN-Tastenkanäle einzeln explizit aktiviert werden; bis dahin sind die Ereignisse nur zur Diagnose sichtbar.
