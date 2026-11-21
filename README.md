# JV Garagentor – IP-Symcon 9.0

Eigenständige IP-Symcon-Bibliothek mit **zwei getrennten Gerätemodulen**:

- **JVGaragentor**: Torbedienung AUF / STOP / ZU über einen Shelly Pro 3, Positionsauswertung und große, mobile HTML-SDK-Kachel.
- **JVAmpel**: steuert ausschließlich die WLED-Ampel anhand der Positionsvariable. Keine Verbindung zu den Shelly-Relais oder zur CS320.

Basis: echte Schiebetor-Skripte und Verknüpfungen aus dem SymBox-Backup vom **07.11.2026**. Das alte Schiebetor wird weder verändert noch referenziert.

## Installation

1. **IP-Symcon → Module Control → Hinzufügen**. Quelle/GitHub: `https://github.com/johannes-veit/JVGaragentor`; Branch `main`.
2. Unter dem gewünschten Bereich je eine Instanz der Typen **JVGaragentor** und **JVAmpel** anlegen.
3. Zunächst **keine Steuerung freigeben**. Die voreingestellten IDs prüfen. Danach Endlagen des Garagentors zuordnen und die vollständige Torlaufzeit messen.
4. **JVGaragentor** als Kachel in der Kachelvisualisierung hinzufügen. Große Schaltflächen: AUF (oben), STOP (Mitte), ZU (unten), Position rechts.
5. In **JVAmpel** die *Position*-Variable der Tor-Instanz auswählen. WLED-Ziel ist bereits auf **46199** voreingestellt. Erst nach Prüfung der WLED-Presets die Ampelautomatik einschalten.

**Wichtig**: Diese Software wurde lokal mit PHP-Mocks simuliert, **nicht** auf der realen SymBox bzw. mit der realen CS320 / dem echten Tor erprobt. Der Shelly wurde zuletzt als noch nicht elektrisch mit der CS320 verbunden gemeldet. Die Torsteuerung ist daher im Auslieferungszustand **gesperrt**; die Freigabe erfolgt erst nach einem separaten realen Inbetriebnahmetest.

## Bestätigte Zuordnungen: Shelly Pro 3 Garagentor

Shelly-IP `192.168.103.73`, Symcon-Instanz **22849**.

| Funktion | Physischer Ausgang | Symcon-Anzeige | Variable | Impuls am Shelly |
|---|---|---|---:|---|
| AUF | O1 / Relais 1 | Status | **44228** | EIN → Auto-OFF nach 1 s |
| ZU | O2 / Relais 2 | Status 1 | **56652** | EIN → Auto-OFF nach 1 s |
| STOP | O3 / Relais 3 | Status 2 | **55944** | AUS → Auto-ON nach 1 s |
| Erreichbarkeit | – | Erreichbar | **17585** | Boolean |

Die **Endlagenvariablen** des Garagentors sind **noch unbekannt** und werden daher absichtlich **nicht vorbelegt**. Beide müssen Boolean sein und `TRUE` melden, wenn die zugehörige Endlage anliegt. Werkseitig werden Fahrbefehle ohne zwei zugewiesene Endlagen gesperrt. Die beiden Shelly-Eingangsstatus-Variablen aus den Screenshots sind **nicht als Endlagen bestätigt**.

## Torsteuerung und Anzeige

Die zentrale Schnittstelle kann von der Kachel, aus Skripten und später vom LCN GT2 verwendet werden:

```php
JVG_Command($garagentorInstanzID, 'AUF');  // öffnet
JVG_Command($garagentorInstanzID, 'STOP'); // stoppt
JVG_Command($garagentorInstanzID, 'ZU');   // schließt
```

Alternativ ist aus Skripten `IPS_RequestAction($garagentorInstanzID, 'Command', 'AUF');` möglich.

Geplanter LCN-GT2: **Kurz = ZU**, **Lang = AUF**. Das Modul stellt die Befehle bereit, bindet aber bewusst **noch kein bestimmtes LCN-Tastenereignis** an, weil die GT2-Objekt-IDs und die LCN-Befehlsspezifikation dafür fehlen. Bei fahrendem Tor ist ein Richtungswechsel zunächst gesperrt, bis STOP gedrückt wurde.

| Anzeige rechts | Auslöser |
|---|---|
| `LÄUFT AUF` | erfolgreicher AUF-Befehl, obere Endlage noch nicht erreicht |
| `AUF` | bestätigte obere Endlage |
| `LÄUFT ZU` | erfolgreicher ZU-Befehl, untere Endlage noch nicht erreicht |
| `ZU` | bestätigte untere Endlage |
| `GESTOPPT` | STOP ohne bestätigte Endlage, Zeitüberschreitung oder widersprüchliche Sensoren |

Nach **Laufzeit + Reserve** ohne richtige Endlagenmeldung wechselt die Anzeige zu `GESTOPPT`. Dies ist **eine Annahme**, kein physischer Stillstandsnachweis; vor einem neuen Fahrbefehl muss dann zuerst ausdrücklich **STOP** ausgelöst werden. Es gibt absichtlich keine Anzeige `UNBEKANNT`. Auch nach Neustart können Endlagensignale sofort übernommen werden. Bei unklaren Sensoren wird keine Endlage erfunden.

Schutzmechanismen: Hardware-Sperre im Testmodus; Shelly-Online-Prüfung; keine doppelt konfigurierten Ausgangs-IDs; Boolean- und Aktionsprüfung; gegenseitige Richtungsverriegelung; keine erneuten identischen Fahrimpulse; Sperre bei widersprüchlichen Endlagen; Laufzeitreserve; Impulsrückfallprüfung nach mehreren Sekunden und Rücksetzversuch für AUF/ZU.

**Nicht Bestandteil der Sicherheitstechnik**: Der Torantrieb Marantec CS320-FU und seine elektrischen/mechanischen Schutzsysteme (Lichtgitter, optische Schaltleisten, Not-Halt, Endlagen) müssen unabhängig und fachgerecht funktionieren. IP-Symcon, Shelly, WLAN und WLED sind **keine sicherheitsgerichteten Steuerungen**. Das Modul darf keine vorgeschriebenen Schutzfunktionen ersetzen.

## Ampel / WLED

WLED-Master vorne: IP `192.168.103.91`, Instanz **17732**, Preset-Variable **46199**, Power-Variable **29605**.

WLED-Master hinten: IP `192.168.103.92`, Instanz **31076**. **Nur vorne wird angesteuert**; hinten soll die bereits vorhandene geräteseitige WLED-Synchronisierung übernehmen. **Preset-/Effekt-Synchronisation muss mit beiden realen LED-Stripes getestet werden**, die bisher gemeldete Schaltsynchronisierung genügt dafür nicht als Nachweis.

Im Modul `WLED (Websocket)` die Option **„Voreinstellungen“** aktivieren; dadurch wird im Master eine *einzige* Integer-Presetvariable erzeugt. Die Nutzer-Screenshots zeigen Presets 1–4 in dieser Variablenliste:

| JVGaragentor-Status | Wert auf Variable 46199 | Anzeige |
|---|---:|---|
| `LÄUFT AUF` | 1 | gelb pulsierend |
| `AUF` | 2 | grün dauerhaft |
| `LÄUFT ZU` | 3 | rot pulsierend |
| `ZU` | 4 | rot dauerhaft |
| `GESTOPPT` | – | WLED standardmäßig AUS, damit keine falsche Fahr-/Endlagenanzeige bleibt |

WLED-Presets 1–4 enthalten `on: true`: ein neuer Preset-Aufruf kann die zuvor ausgeschaltete Ampel wieder einschalten. Das muss beim echten WLED-Gerät validiert werden. Die Default-Option **„Bei GESTOPPT Ampel AUS“** kann in JVAmpel ausgeschaltet werden, dann bleibt der letzte Lampenzustand bestehen (nicht empfohlen, weil die Animation dann eine nicht mehr stattfindende Fahrt suggerieren kann).

Wenn eine tatsächlich sichtbare **STOP-Anzeige** statt AUS benötigt wird, muss zuerst ein eigener, eindeutiger WLED-Preset definiert und die Ampel-Logik dafür erweitert werden. WLED ist ein **Hinweislicht**, keine sicherheitsgerichtete Signalanlage.

## Tests

Lokale Prüfung: `php tests/simulate.php` simuliert Tor- und Ampelvariablen und testet u. a. alle Richtungen, falsche Sensoren, Offline, Timeout, Freigabe, Impulse und Presets. Mit `php -l` die PHP-Dateien prüfen; JSON-Dateien werden auf Syntax geprüft. Hardwaretests und echte WLED-Synchronisierung können in dieser Testumgebung nicht ausgeführt werden.
