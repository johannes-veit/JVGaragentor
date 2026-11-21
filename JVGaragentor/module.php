<?php
declare(strict_types=1);

/** JVGaragentor: Fahrbefehle über Shelly Pro 3; Endlagen-/Laufzeit-Status. */
class JVGaragentor extends IPSModuleStrict
{
    private const OPENING = 'LÄUFT AUF';
    private const CLOSING = 'LÄUFT ZU';
    private const STOPPED = 'GESTOPPT';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyInteger('RelayOpenVariable', 44228);
        $this->RegisterPropertyInteger('RelayCloseVariable', 56652);
        $this->RegisterPropertyInteger('RelayStopVariable', 55944);
        $this->RegisterPropertyInteger('OnlineVariable', 17585);
        $this->RegisterPropertyInteger('EndOpenVariable', 0);
        $this->RegisterPropertyInteger('EndCloseVariable', 0);
        $this->RegisterPropertyBoolean('RequireEndStops', true);
        $this->RegisterPropertyInteger('MovementTimeoutSeconds', 120); // Harte Obergrenze auch bei Konfigurationsfehler 120 s
        $this->RegisterPropertyInteger('OpenHoldSeconds', 10);
        $this->RegisterPropertyInteger('CloseHoldSeconds', 5);
        $this->RegisterPropertyInteger('BlinkPeriodMs', 1600);
        $this->RegisterPropertyInteger('PulseToleranceSeconds', 3);
        $this->RegisterAttributeInteger('MovementStarted', 0);
        $this->RegisterAttributeInteger('SignalUntil', 0);
        $this->RegisterAttributeInteger('PulseSentAt', 0);
        $this->RegisterAttributeInteger('PulseVariable', 0);
        $this->RegisterAttributeBoolean('NeedsStop', false);
        $this->RegisterAttributeString('LastSnapshot', '');
        $this->RegisterTimer('Monitor', 1000, 'JVG_Monitor($_IPS["TARGET"]);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->RegisterVariableString('Position', 'Position', [], 0)) {
            $this->SetValue('Position', self::STOPPED);
        }
        $this->RegisterVariableString('Hinweis', 'Steuerung / Diagnose', [], 1);
        $this->RegisterVariableInteger('Signal', 'Ampel-Signal', [], 2);
        $this->SetTimerInterval('Monitor', 1000);
        $this->Monitor();
    }

    /** Derselbe Eingang für Kachel, Skripte, später LCN-GT2: JVG_Command(id, 'ZU'). */
    public function Command(string $command): bool
    {
        if (!in_array($command, ['AUF', 'STOP', 'ZU'], true)) {
            throw new InvalidArgumentException('Ungültiger Torbefehl');
        }
        if (!$this->ReadPropertyBoolean('EnableControl')) {
            $this->SetWarning('Steuerung ist gesperrt (Testmodus).');
            return false;
        }
        if (!$this->IsOnline()) {
            $this->SetWarning('Shelly ist nicht erreichbar – kein Befehl gesendet.');
            return false;
        }
        $ids = $this->RelayIds();
        if (count(array_unique(array_values($ids))) !== 3) {
            $this->SetWarning('Relaisvariablen sind nicht eindeutig zugeordnet.');
            return false;
        }
        foreach ($ids as $id) {
            if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN) || !$this->HasAction($id)) {
                $this->SetWarning('Shelly-Relaisvariablen fehlen oder sind nicht schaltbar.');
                return false;
            }
        }
        $endOpen = $this->EndSignal('EndOpenVariable');
        $endClose = $this->EndSignal('EndCloseVariable');
        if ($command !== 'STOP') {
            if ($this->ReadAttributeBoolean('NeedsStop')) {
                $this->SetWarning('Nach Laufzeitüberschreitung zuerst STOP drücken.');
                return false;
            }
            if ($this->ReadPropertyBoolean('RequireEndStops') && ($endOpen === null || $endClose === null)) {
                $this->SetWarning('Beide Endlagen-Rückmeldungen müssen zugeordnet sein.');
                return false;
            }
            if (($this->ReadPropertyInteger('EndOpenVariable') > 0 &&
                 $this->ReadPropertyInteger('EndOpenVariable') === $this->ReadPropertyInteger('EndCloseVariable')) ||
                in_array($this->ReadPropertyInteger('EndOpenVariable'), array_values($ids), true) ||
                in_array($this->ReadPropertyInteger('EndCloseVariable'), array_values($ids), true)) {
                $this->SetWarning('Endlagenvariablen sind nicht eindeutig / überlappen mit Relais.');
                return false;
            }
            if ($endOpen === true && $endClose === true) {
                $this->SetWarning('Widersprüchliche Endlagen – Fahrbefehl gesperrt.');
                return false;
            }
            $position = (string) $this->GetValue('Position');
            // Keine automatische Richtungsumkehr: zuerst ausdrücklich STOP drücken.
            if (($position === self::OPENING || $position === self::CLOSING) &&
                (($position === self::OPENING && $command === 'ZU') ||
                 ($position === self::CLOSING && $command === 'AUF'))) {
                $this->SetWarning('Bei laufendem Tor zuerst STOP drücken.');
                return false;
            }
            if (($command === 'AUF' && ($endOpen === true || $position === self::OPENING)) ||
                ($command === 'ZU' && ($endClose === true || $position === self::CLOSING))) {
                return true; // Kein weiterer Impuls auf bereits laufendes / erreichtes Ziel.
            }
            if (GetValueBoolean($ids['AUF']) || GetValueBoolean($ids['ZU'])) {
                $this->SetWarning('Mindestens ein Fahrrelais ist noch EIN – neuer Befehl gesperrt.');
                return false;
            }
        }

        // AUF & ZU: EIN-Impuls mit Auto-OFF 1 s am Shelly.
        // STOP: AUS-Impuls mit Auto-ON 1 s am Shelly.
        $relay = $ids[$command];
        $value = $command !== 'STOP';
        try {
            $sent = RequestAction($relay, $value);
        } catch (Throwable $error) {
            $this->SetWarning('Befehl fehlgeschlagen: ' . $error->getMessage());
            return false;
        }
        if ($sent !== true) {
            $this->SetWarning('Shelly hat den Befehl nicht angenommen.');
            return false;
        }
        $this->WriteAttributeInteger('PulseSentAt', time());
        $this->WriteAttributeInteger('PulseVariable', $relay);
        if ($command === 'STOP') {
            $this->WriteAttributeBoolean('NeedsStop', false);
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->SetStatus($this->ResolvedEnd() ?? self::STOPPED, 0);
        } else {
            $this->WriteAttributeInteger('MovementStarted', time());
            $this->SetStatus($command === 'AUF' ? self::OPENING : self::CLOSING, $command === 'AUF' ? 1 : 3);
        }
        $this->SetWarning('');
        return true;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Command' || !is_string($Value)) {
            throw new InvalidArgumentException('Unbekannte Aktion');
        }
        if (!$this->Command($Value)) {
            // Kachel bleibt unverändert, Diagnose wird im Status angezeigt.
            $this->SendDebug('Command', 'Befehl nicht ausgeführt: ' . (string)$Value, 0);
        }
    }

    /**
     * 1-Sekunden-Überwachung: Endlagen haben Vorrang; 120 Sekunden sind
     * die absolute maximale Anzeige- und Fahrüberwachungszeit.
     * Nach Ablauf stoppt die ANZEIGE – keine falsche Annahme, dass die
     * CS320 den Antrieb tatsächlich gestoppt hat.
     */
    public function Monitor(): void
    {
        $this->CheckPulse();
        $open = $this->EndSignal('EndOpenVariable');
        $closed = $this->EndSignal('EndCloseVariable');
        if ($open === true && $closed === true) {
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->WriteAttributeBoolean('NeedsStop', true);
            $this->SetStatus(self::STOPPED, 0);
            $this->SetWarning('Fehler: Beide Endlagen sind gleichzeitig aktiv.');
            return;
        }

        $position = (string)$this->GetValue('Position');
        if (($position === self::OPENING && $open === true) ||
            ($position === self::CLOSING && $closed === true)) {
            $this->Arrived($position === self::OPENING ? 'AUF' : 'ZU');
            return;
        }
        if ($position === self::OPENING || $position === self::CLOSING) {
            $started = $this->ReadAttributeInteger('MovementStarted');
            if ($started <= 0 || time() - $started >= $this->MovementTimeout()) {
                $this->WriteAttributeInteger('MovementStarted', 0);
                $this->WriteAttributeBoolean('NeedsStop', true);
                $this->SetStatus(self::STOPPED, 0);
                $this->SetWarning('120-s-Maximum bzw. konfigurierter Timeout erreicht. Endlage nicht bestätigt – Torstillstand nicht nachgewiesen. Vor weiterer Fahrt STOP drücken.');
            }
            $this->Broadcast();
            return;
        }

        // Auch Endlagen durch die CS320 (ohne Shelly-Fahrt) beachten.
        $resolved = $this->ResolvedEnd();
        if ($resolved !== null && $resolved !== $position) {
            $this->Arrived($resolved);
        } elseif (($position === 'AUF' && $open === false) ||
                  ($position === 'ZU' && $closed === false)) {
            $this->SetStatus(self::STOPPED, 0);
        } elseif (in_array($position, ['AUF', 'ZU'], true)) {
            // Nachleuchten zeitgesteuert beenden, Status aber beibehalten.
            $until = $this->ReadAttributeInteger('SignalUntil');
            if ((int)$this->GetValue('Signal') !== 0 && ($until <= 0 || time() >= $until)) {
                $this->SetStatus($position, 0);
            }
        } elseif ((int)$this->GetValue('Signal') !== 0) {
            $this->SetStatus(self::STOPPED, 0);
        }
        $this->Broadcast();
    }

    private function MovementTimeout(): int
    {
        return min(120, max(5, $this->ReadPropertyInteger('MovementTimeoutSeconds')));
    }

    private function CheckPulse(): void
    {
        $sent = $this->ReadAttributeInteger('PulseSentAt');
        $id = $this->ReadAttributeInteger('PulseVariable');
        if ($sent <= 0 || $id <= 0) {
            return;
        }
        if ((time() - $sent) <= max(2, $this->ReadPropertyInteger('PulseToleranceSeconds'))) {
            return;
        }
        $this->WriteAttributeInteger('PulseSentAt', 0);
        $this->WriteAttributeInteger('PulseVariable', 0);
        if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN)) {
            return;
        }
        $isStopRelay = $id === $this->ReadPropertyInteger('RelayStopVariable');
        $expected = $isStopRelay; // STOP bleibt EIN; AUF/ZU bleiben AUS.
        if (GetValueBoolean($id) !== $expected) {
            $this->SetWarning('Shelly-Impuls nicht zurückgesetzt! Auto-OFF/Auto-ON prüfen.');
            // Bei klemmendem AUF/ZU Relais eine Rücksetzanforderung senden.
            // Keine automatische Korrektur des STOP-Kontakts ohne Diagnose.
            if (!$isStopRelay && $this->IsOnline() && $this->HasAction($id)) {
                try { RequestAction($id, false); } catch (Throwable $e) {
                    $this->SendDebug('AutoOFF', $e->getMessage(), 0);
                }
            }
        }
    }

    private function Arrived(string $position): void
    {
        $this->WriteAttributeBoolean('NeedsStop', false);
        $this->WriteAttributeInteger('MovementStarted', 0);
        $this->SetWarning('');
        $seconds = $position === 'AUF' ? $this->ReadPropertyInteger('OpenHoldSeconds')
            : $this->ReadPropertyInteger('CloseHoldSeconds');
        $seconds = min(120, max(0, $seconds));
        $signal = $seconds <= 0 ? 0 : ($position === 'AUF' ? 2 : 4);
        $this->SetStatus($position, $signal, $seconds > 0 ? time() + $seconds : 0);
    }

    /**
     * Genau ein kanonischer Signalwert für HTML-Kachel und externes
     * Ampel-Modul: 0 AUS, 1 gelb pulsierend, 2 grün, 3 rot pulsierend,
     * 4 rot statisch. Im Ruhezustand bleibt die POSITION sichtbar.
     */
    private function SetStatus(string $position, int $signal, int $until = 0): void
    {
        $this->WriteAttributeInteger('SignalUntil', $until);
        if ((string)$this->GetValue('Position') !== $position) {
            $this->SetValue('Position', $position);
        }
        if ((int)$this->GetValue('Signal') !== $signal) {
            $this->SetValue('Signal', $signal);
        }
        $this->Broadcast();
    }

    private function SetWarning(string $warning): void
    {
        if ((string)$this->GetValue('Hinweis') !== $warning) {
            $this->SetValue('Hinweis', $warning);
            $this->Broadcast();
        }
    }

    private function ResolvedEnd(): ?string
    {
        $open = $this->EndSignal('EndOpenVariable');
        $closed = $this->EndSignal('EndCloseVariable');
        if ($open === true && $closed === false) return 'AUF';
        if ($closed === true && $open === false) return 'ZU';
        return null;
    }

    private function EndSignal(string $property): ?bool
    {
        $id = $this->ReadPropertyInteger($property);
        return $this->ValidVariable($id, VARIABLETYPE_BOOLEAN) ? GetValueBoolean($id) : null;
    }

    private function RelayIds(): array
    {
        return [
            'AUF' => $this->ReadPropertyInteger('RelayOpenVariable'),
            'STOP' => $this->ReadPropertyInteger('RelayStopVariable'),
            'ZU' => $this->ReadPropertyInteger('RelayCloseVariable')
        ];
    }

    private function IsOnline(): bool
    {
        $id = $this->ReadPropertyInteger('OnlineVariable');
        return $this->ValidVariable($id, VARIABLETYPE_BOOLEAN) && GetValueBoolean($id);
    }

    private function ValidVariable(int $id, int $type): bool
    {
        return $id > 0 && IPS_VariableExists($id) &&
            (int)IPS_GetVariable($id)['VariableType'] === $type;
    }

    private function HasAction(int $id): bool
    {
        if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN)) return false;
        $v = IPS_GetVariable($id);
        return (int)($v['VariableCustomAction'] ?: $v['VariableAction']) > 0;
    }

    private function Snapshot(): array
    {
        $requiredEndStopsMissing = $this->ReadPropertyBoolean('RequireEndStops') &&
            ($this->EndSignal('EndOpenVariable') === null || $this->EndSignal('EndCloseVariable') === null);
        $diagnostic = (string)$this->GetValue('Hinweis');
        if ($requiredEndStopsMissing && $diagnostic === '') $diagnostic = 'Endlagen fehlen – Fahrbefehle gesperrt.';
        $signal = (int)$this->GetValue('Signal');
        $deadline = 0;
        if (in_array($signal, [1, 3], true)) {
            $started = $this->ReadAttributeInteger('MovementStarted');
            $deadline = $started > 0 ? $started + $this->MovementTimeout() : 0;
        } elseif (in_array($signal, [2, 4], true)) {
            $deadline = $this->ReadAttributeInteger('SignalUntil');
        }
        return [
            'position' => (string)$this->GetValue('Position'),
            'signal' => $signal,
            'effectUntil' => $deadline,
            'blinkPeriodMs' => min(4000, max(500, $this->ReadPropertyInteger('BlinkPeriodMs'))),
            'warning' => $diagnostic,
            'enabled' => $this->ReadPropertyBoolean('EnableControl'),
            'ready' => !$requiredEndStopsMissing && !$this->ReadAttributeBoolean('NeedsStop'),
            'online' => $this->IsOnline()
        ];
    }

    private function Broadcast(): void
    {
        $json = json_encode($this->Snapshot(), JSON_UNESCAPED_UNICODE);
        if ($json === false || $json === $this->ReadAttributeString('LastSnapshot')) return;
        $this->WriteAttributeString('LastSnapshot', $json);
        $this->UpdateVisualizationValue($json);
    }

    public function GetVisualizationTile(): string
    {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) return '<p>JVGaragentor: Kachel-Datei fehlt.</p>';
        $json = json_encode($this->Snapshot(), JSON_UNESCAPED_UNICODE |
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('__JV_INITIAL__', $json === false ? '{}' : $json, $html);
    }
}
