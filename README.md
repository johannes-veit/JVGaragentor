# JV Garagentor v1.1.3 – IP-Symcon 9.0

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
