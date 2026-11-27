<?php
declare(strict_types=1);

require_once __DIR__ . '/LCNTasten.php';
require_once __DIR__ . '/LCNBridge.php';
require_once __DIR__ . '/MotionFeedback.php';

/** JVGaragentor: Fahrbefehle über Shelly Pro 3; Endlagen-/Laufzeit-Status. */
class JVGaragentor extends IPSModuleStrict
{
    use JVLCNBridgeTrait;
    use JVMotionFeedbackTrait;
    private const OPENING = 'LÄUFT AUF';
    private const CLOSING = 'LÄUFT ZU';
    private const STOPPED = 'GESTOPPT';
    private const END_OPEN_ID = 13220;  // SW2, Eingangsstatus 1
    private const END_CLOSE_ID = 21923; // SW1, Eingangsstatus
    private const SHELLY_INSTANCE = 22849;
    private const END_CONFIG_WARNING = 'Endlagen fehlen oder sind falsch zugeordnet – Eingangsstatus prüfen.';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->LCNCreate();
        $this->MotionCreate();
        $this->RegisterPropertyInteger('RelayOpenVariable', 44228);
        $this->RegisterPropertyInteger('RelayCloseVariable', 56652);
        $this->RegisterPropertyInteger('RelayStopVariable', 55944);
        $this->RegisterPropertyInteger('OnlineVariable', 17585);
        $this->RegisterPropertyInteger('EndOpenVariable', self::END_OPEN_ID);
        $this->RegisterPropertyInteger('EndCloseVariable', self::END_CLOSE_ID);
        $this->RegisterPropertyBoolean('RequireEndStops', true);
        $this->RegisterPropertyInteger('MovementTimeoutSeconds', 120); // Harte Obergrenze auch bei Konfigurationsfehler 120 s
        $this->RegisterPropertyInteger('OpenHoldSeconds', 10);
        $this->RegisterPropertyInteger('CloseHoldSeconds', 5);
        $this->RegisterPropertyInteger('BlinkPeriodMs', 1600);
        $this->RegisterPropertyInteger('PulseToleranceSeconds', 3);
        $this->RegisterAttributeInteger('MovementStarted', 0);
        $this->RegisterAttributeInteger('SignalUntil', 0);
        $this->RegisterAttributeBoolean('StateInitialized', false);
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
        $this->SetTimerInterval('Monitor', 1000); // Local fallback / hold time / visualization only
        $this->LCNApplyChanges();
        $this->MotionApplyChanges();
        $this->Monitor();
        $this->LCNScheduleCommission();
    }

    /** Derselbe Eingang für Kachel, Skripte, später LCN-GT2: JVG_Command(id, 'ZU'). */
    public function Command(string $command): bool
    {
        return $this->CommandInternal($command);
    }

    private function CommandInternal(string $command, bool $short = false, ?string &$actual = null): bool
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
        $feedback = $this->MotionEnabled();
        if ($feedback && ($command !== 'STOP' || $short)) {
            // Read current Symcon direction for every decision, including an external drive
            // that started since the last timer tick.
            $this->MotionMonitor();
            if ($short && (!$this->ReadAttributeBoolean('MotionFeedbackOK') ||
                in_array((string)$this->GetValue('Position'), [self::OPENING, self::CLOSING], true) ||
                $this->ReadAttributeInteger('MotionPendingUntil') > time())) {
                $command = 'STOP';
            }
        }
        $actual = $command;
        if ($feedback && $command !== 'STOP' && !$this->ReadAttributeBoolean('MotionFeedbackOK')) {
            $this->SetWarning(self::MOTION_WARNING . 'Keine gültige Rückmeldung – Fahrbefehl gesperrt.');
            return false;
        }
        if ($feedback && $command !== 'STOP' && $this->ReadAttributeInteger('MotionPendingUntil') > time()) {
            $this->SetWarning(self::MOTION_WARNING . 'Warte auf Fahrtrückmeldung – nur STOP möglich.');
            return false;
        }
        $endOpen = $this->EndSignal('EndOpenVariable');
        $endClose = $this->EndSignal('EndCloseVariable');
        if ($command !== 'STOP') {
            if ($feedback && !GetValueBoolean($ids['STOP'])) {
                $this->SetWarning('STOP-Kontakt noch offen – Fahrbefehl gesperrt.');
                return false;
            }
            if ($this->ReadAttributeBoolean('NeedsStop')) {
                $this->SetWarning('Nach Laufzeitüberschreitung zuerst STOP drücken.');
                return false;
            }
            if ($this->ReadPropertyBoolean('RequireEndStops') && ($endOpen === null || $endClose === null)) {
                $this->SetWarning('Beide Endlagen-Rückmeldungen müssen zugeordnet sein.');
                return false;
            }
            $openID = $this->EndVariableId('EndOpenVariable');
            $closeID = $this->EndVariableId('EndCloseVariable');
            if (($openID > 0 && $openID === $closeID) ||
                in_array($openID, array_values($ids), true) ||
                in_array($closeID, array_values($ids), true)) {
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
        if ($feedback) {
            // No optimistic lamp state; feedback confirms actual movement/stop.
            $this->WriteAttributeInteger('MotionPendingUntil', $command === 'STOP' ? 0 : time() + 3);
            $this->Broadcast();
            return true;
        }
        if ($command === 'STOP') {
            $this->WriteAttributeBoolean('NeedsStop', false);
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->UpdateGateState($this->ResolvedEnd() ?? self::STOPPED, 0);
        } else {
            $this->WriteAttributeInteger('MovementStarted', time());
            $this->UpdateGateState($command === 'AUF' ? self::OPENING : self::CLOSING, $command === 'AUF' ? 1 : 3);
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
        if ($this->MotionEnabled()) {
            $this->MotionMonitor();
            return;
        }
        $open = $this->EndSignal('EndOpenVariable');
        $closed = $this->EndSignal('EndCloseVariable');
        if ($this->ReadPropertyBoolean('RequireEndStops') && ($open === null || $closed === null)) {
            // Ohne gültiges Eingangspaar niemals eine alte oder falsche Endlage anzeigen.
            if ($this->ReadAttributeInteger('MovementStarted') > 0) {
                $this->WriteAttributeBoolean('NeedsStop', true);
            }
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->WriteAttributeBoolean('StateInitialized', false);
            $this->UpdateGateState(self::STOPPED, 0);
            $this->SetWarning(self::END_CONFIG_WARNING);
            return;
        }
        if ((string)$this->GetValue('Hinweis') === self::END_CONFIG_WARNING) {
            $this->SetWarning('');
        }
        if ($open === true && $closed === true) {
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->WriteAttributeBoolean('NeedsStop', true);
            $this->UpdateGateState(self::STOPPED, 0);
            $this->SetWarning('Fehler: Beide Endlagen sind gleichzeitig aktiv.');
            return;
        }

        $position = (string)$this->GetValue('Position');
        // Beim ersten Start eine bereits anliegende Endlage übernehmen,
        // aber nicht ohne tatsächliches neues Ereignis Nachleuchten auslösen.
        if (!$this->ReadAttributeBoolean('StateInitialized')) {
            $this->WriteAttributeBoolean('StateInitialized', true);
            if ($position === self::STOPPED && $this->ReadAttributeInteger('MovementStarted') <= 0) {
                $initialEnd = $this->ResolvedEnd();
                if ($initialEnd !== null) {
                    $this->UpdateGateState($initialEnd, 0);
                    return;
                }
            }
        }
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
                $this->UpdateGateState(self::STOPPED, 0);
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
            $this->UpdateGateState(self::STOPPED, 0);
        } elseif (in_array($position, ['AUF', 'ZU'], true)) {
            // Nachleuchten zeitgesteuert beenden, Status aber beibehalten.
            $until = $this->ReadAttributeInteger('SignalUntil');
            if ((int)$this->GetValue('Signal') !== 0 && ($until <= 0 || time() >= $until)) {
                $this->UpdateGateState($position, 0);
            }
        } elseif ((int)$this->GetValue('Signal') !== 0) {
            $this->UpdateGateState(self::STOPPED, 0);
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
        $this->UpdateGateState($position, $signal, $seconds > 0 ? time() + $seconds : 0);
    }

    /**
     * Genau ein kanonischer Signalwert für HTML-Kachel und externes
     * Ampel-Modul: 0 AUS, 1 gelb pulsierend, 2 grün, 3 rot pulsierend,
     * 4 rot statisch. Im Ruhezustand bleibt die POSITION sichtbar.
     */
    private function UpdateGateState(string $position, int $signal, int $until = 0): void
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

    /** Alte Instanzen mit gespeicherten 0-Werten nur für das bestätigte Garagen-Setup ergänzen. */
    private function EndVariableId(string $property): int
    {
        $id = $this->ReadPropertyInteger($property);
        // Repair only known legacy mistakes on the explicitly commissioned garage.
        // A different installation or unknown ID is never guessed.
        $legacy = $this->InstanceID === 15957 &&
            in_array($id, [0,44228,56652,55944,17585,self::END_OPEN_ID,self::END_CLOSE_ID], true);
        $expected = $property === 'EndOpenVariable' ? self::END_OPEN_ID : self::END_CLOSE_ID;
        if ($id === $expected || ($id !== 0 && !$legacy)) return $id;
        $ids = $this->RelayIds();
        if ($ids['AUF'] !== 44228 || $ids['ZU'] !== 56652 || $ids['STOP'] !== 55944 ||
            $this->ReadPropertyInteger('OnlineVariable') !== 17585) return 0;
        foreach ([44228, 56652, 55944, 17585, self::END_OPEN_ID, self::END_CLOSE_ID] as $known) {
            if (!$this->ValidVariable($known, VARIABLETYPE_BOOLEAN) ||
                IPS_GetParent($known) !== self::SHELLY_INSTANCE) return 0;
        }
        return $property === 'EndOpenVariable' ? self::END_OPEN_ID : self::END_CLOSE_ID;
    }

    private function EndSignal(string $property): ?bool
    {
        $id = $this->EndVariableId($property);
        $other = $this->EndVariableId($property === 'EndOpenVariable' ? 'EndCloseVariable' : 'EndOpenVariable');
        // Ausgangsstatus, Online-Status, doppelte oder vertauschte bekannte Endlagen ablehnen.
        if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN) || $id === $other ||
            in_array($id, array_values($this->RelayIds()), true) ||
            in_array($id, [44228, 56652, 55944, 17585, $this->ReadPropertyInteger('OnlineVariable')], true)) return null;
        if (in_array($id, [self::END_OPEN_ID, self::END_CLOSE_ID], true)) {
            $expected = $property === 'EndOpenVariable' ? self::END_OPEN_ID : self::END_CLOSE_ID;
            if ($id !== $expected || IPS_GetParent($id) !== self::SHELLY_INSTANCE) return null;
        }
        return GetValueBoolean($id);
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
            $deadline = $this->MotionEnabled() ? time() + 4 : ($started > 0 ? $started + $this->MovementTimeout() : 0);
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
            'ready' => !$requiredEndStopsMissing && !$this->ReadAttributeBoolean('NeedsStop') &&
                (!$this->MotionEnabled() || ($this->ReadAttributeBoolean('MotionFeedbackOK') &&
                    $this->ReadAttributeInteger('MotionPendingUntil') <= time())),
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
