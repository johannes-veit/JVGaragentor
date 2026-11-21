# JVGaragentor

IP-Symcon 9.0: HTML-SDK-Kachel für die Glasfassaden-Garagentorsteuerung mit Shelly Pro 3 und Marantec CS320-FU.

> **Entwicklungsstand v0.1 – nicht für den ungetesteten Echtbetrieb freigeben.** Der Shelly Pro 3 ist bisher nur in Symcon eingebunden. Die elektrische Verbindung zur CS320 sowie die Endlagenrückmeldungen sind noch nicht bestätigt. Die Toransteuerung ist daher **standardmäßig AUS**.

## Vorbelegte Shelly-Variablen (Instanz 22849)

| Aktion | Shelly-Ausgang | IP-Symcon-Bezeichnung | Variablen-ID | Impuls |
|---|---|---|---|---|
| AUF | O1 / Relais 1 | Status | 44228 | EIN, Auto-OFF nach 1 s |
| ZU | O2 / Relais 2 | Status 1 | 56652 | EIN, Auto-OFF nach 1 s |
| STOP | O3 / Relais 3 | Status 2 | 55944 | AUS, Auto-ON nach 1 s |
| Erreichbarkeit | – | Erreichbar | 17585 | Online/Offline |

Shelly-IP: `192.168.103.73`. Die tatsächliche Schaltfunktion an der CS320 vor Freigabe **einzeln** verifizieren. Das Modul sendet ausschließlich Boolean-Variablenaktionen per `RequestAction(VariablenID, Wert)`, keine Impulsfolgen.

## Kachel

Die Kachel folgt der großen Symcon-Schiebetor-Bedienansicht: AUF oben links, STOP in der Mitte, ZU unten links, Position rechts über die gesamte Höhe. Statuswerte:

- `AUF`: bestätigte obere Endlage
- `ZU`: bestätigte untere Endlage
- `LÄUFT AUF`: letzter Fahrbefehl AUF, noch keine obere Endlage gemeldet
- `LÄUFT ZU`: letzter Fahrbefehl ZU, noch keine untere Endlage gemeldet
- `GESTOPPT`: STOP-Befehl ohne Endlage oder erwartete Fahrzeit plus Reserve abgelaufen

Kein Status `UNBEKANNT`. Der letzte Fahrbefehl bleibt bis zur Endlagenmeldung bzw. bis zum Ablauf der Überwachungszeit sichtbar.

## Konfiguration

1. Nach Installation als **Geräteinstanz** das Modul **JVGaragentor** anlegen.
2. Relais-Variablen O1/O2/O3 und Erreichbarkeitsvariable anhand von Instanz **22849** prüfen.
3. Die zwei **tatsächlichen Endlagenrückmeldungen** als Boolean-Variablen auswählen (TRUE = Endlage erreicht). Die sichtbaren Shelly-Eingangsstatus-Variablen sind dafür **noch nicht bestätigt**.
4. Tatsächliche komplette Torlaufzeit messen und als `TravelSeconds` eintragen; Reserve separat einstellen. Die Defaults 60 und 15 Sekunden sind **nur Platzhalter**.
5. Erst nach geprüfter Verdrahtung, CS320-Sicherheitsfunktionen und einzeln geprüfter Shelly-Schaltlogik die Option `Toransteuerung aktivieren` einschalten.
6. HTML-SDK-Kachel in die Symcon-Kachelvisualisierung einfügen.

**Wichtig:** `GESTOPPT` nach Zeitablauf ist eine **abgeleitete Anzeigelogik**, kein physischer Nachweis für Stillstand. Die sicherheitsgerichtete Hinderniserkennung, Endabschaltung und Bewegungsüberwachung müssen unabhängig über die dafür vorgesehenen Torsteuerungs- und Schutzeinrichtungen funktionieren. Bei fehlenden Endlagensignalen kann dieses Modul keine Endlage als `AUF`/`ZU` bestätigen.

## Entwicklungsstand

Version `0.1` enthält Modulgerüst, Bedienkachel, Zustandslogik, Shelly-Schaltaktionen mit Schutzfreigabe und Online-Prüfung. Eine Überprüfung auf einer laufenden SymBox und am realen Tor steht aus. Entwicklung erfolgt im Branch `feature/garagentor-v0.1`. `main` bleibt vorerst unverändert.
