<?php
declare(strict_types=1);

/** JV Ampel: zwei getrennte WLED-Master (Tor und CS320 unberührt). */
class JVAmpel extends IPSModuleStrict
{
    private const GATE_MODULE_ID = '{8193BC5B-9A6A-48A3-A70C-B69A73472328}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableAmpel', false);
        $this->RegisterPropertyInteger('SignalVariable', 0);
        $this->RegisterPropertyInteger('PresetVariable', 46199);
        $this->RegisterPropertyInteger('PowerVariable', 29605);
        $this->RegisterPropertyInteger('RearPresetVariable', 17676);
        $this->RegisterPropertyInteger('RearPowerVariable', 16055);
        $this->RegisterPropertyBoolean('RearPresetsVerified', false);
        $this->RegisterPropertyInteger('GateOnlineVariable', 17585);
        $this->RegisterAttributeInteger('LastFrontSignal', -1);
        $this->RegisterAttributeInteger('LastRearSignal', -1);
        $this->RegisterAttributeInteger('BlockedGateSignal', -1);
        $this->RegisterAttributeInteger('SubscribedVariable', 0);
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $this->RegisterTimer('Synchronize', 10000, 'JVGA_Sync($_IPS["TARGET"]);');
    }

    /**
     * Bei genau einer gültigen JVGaragentor-Instanz wird deren Integer-
     * Variable Signal automatisch verwendet. Eine explizite Auswahl hat
     * immer Vorrang. Bei 0 oder mehreren passenden Quellen keine Vermutung.
     *
     * @return array{0: int, 1: string}
     */
    private function FindSignal(): array
    {
        $manual = $this->ReadPropertyInteger('SignalVariable');
        if ($manual > 0) {
            if ($this->IsVariable($manual, VARIABLETYPE_INTEGER)) {
                return [$manual, 'Manuell gewählt: Signalvariable #' . $manual];
            }
            return [0, 'Manuelle Signalvariable #' . $manual . ' ist ungültig.'];
        }
        $matches = [];
        foreach (IPS_GetInstanceListByModuleID(self::GATE_MODULE_ID) as $gateID) {
            if (!IPS_InstanceExists((int)$gateID)) {
                continue;
            }
            try {
                $signalID = IPS_GetObjectIDByIdent('Signal', (int)$gateID);
            } catch (Throwable $e) {
                continue; // Torinstanz ist evtl. noch in Erstellung.
            }
            if ($this->IsVariable((int)$signalID, VARIABLETYPE_INTEGER)) {
                $matches[(int)$gateID] = (int)$signalID;
            }
        }
        if (count($matches) === 1) {
            $gateID = (int)array_key_first($matches);
            return [$matches[$gateID], 'Automatisch: Torinstanz #' . $gateID .
                ' -> Ampel-Signal #' . $matches[$gateID]];
        }
        if (count($matches) > 1) {
            return [0, 'Mehrere Torinstanzen gefunden: Ampel-Signal bitte manuell auswählen.'];
        }
        return [0, 'Keine JVGaragentor-Torinstanz mit Ampel-Signal gefunden. Zuerst Torinstanz erstellen.'];
    }

    /**
     * Erneute Suche im 10-Sekunden-Timer: Funktioniert auch, wenn die
     * Torinstanz erst nach der Ampelinstanz erstellt wird.
     */
    private function UpdateSignalSubscription(): int
    {
        [$signalID, $note] = $this->FindSignal();
        $previous = $this->ReadAttributeInteger('SubscribedVariable');
        if ($previous !== $signalID) {
            if ($previous > 0) {
                $this->UnregisterMessage($previous, VM_UPDATE);
            }
            if ($signalID > 0) {
                $this->RegisterMessage($signalID, VM_UPDATE);
            }
            $this->WriteAttributeInteger('SubscribedVariable', $signalID);
            $this->SendDebug('Ampel Signal', $note, 0);
        }
        return $signalID;
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{"elements":[],"actions":[]}';
        }
        [, $note] = $this->FindSignal();
        array_splice($form['elements'], 3, 0, [
            ['type' => 'Label', 'caption' => 'Verbindung: ' . $note]
        ]);
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $this->UpdateSignalSubscription();
        $this->WriteAttributeInteger('LastFrontSignal', -1);
        $this->WriteAttributeInteger('LastRearSignal', -1);
        $this->SetTimerInterval('Synchronize', 10000);
        $this->Sync();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE && $SenderID > 0 &&
            $SenderID === $this->ReadAttributeInteger('SubscribedVariable')) {
            $this->Sync();
        }
    }

    public function Sync(): void
    {
        $source = $this->UpdateSignalSubscription();
        if (!$this->ReadPropertyBoolean('EnableAmpel')) {
            $this->WriteAttributeInteger('LastFrontSignal', -1);
            $this->WriteAttributeInteger('LastRearSignal', -1);
            $this->SetTimerInterval('Synchronize', 10000);
            $this->SetStatusText('Automatik AUS');
            return;
        }
        $validSource = $this->IsVariable($source, VARIABLETYPE_INTEGER);
        $raw = $validSource ? GetValueInteger($source) : -1;
        $signal = in_array($raw, [0, 1, 2, 3, 4], true) ? $raw : 0;
        $note = $validSource && $raw === $signal ? '' : 'Signalquelle ungültig; ';
        $online = $this->ReadPropertyInteger('GateOnlineVariable');
        $gateOnline = $this->IsVariable($online, VARIABLETYPE_BOOLEAN) && GetValueBoolean($online);
        if (!$gateOnline || !$validSource || $raw !== $signal) {
            // Nach Verlust der Torquelle nicht denselben möglicherweise alten Zustand neu aktivieren.
            $this->WriteAttributeInteger('BlockedGateSignal', $signal > 0 ? $signal : -1);
            $signal = 0;
            if (!$gateOnline) $note .= 'Tor-Shelly nicht erreichbar; ';
        } else {
            $blocked = $this->ReadAttributeInteger('BlockedGateSignal');
            if ($blocked > 0 && $signal === $blocked) {
                $signal = 0;
                $note .= 'Warte auf neues Torsignal nach Ausfall; ';
            } else {
                $this->WriteAttributeInteger('BlockedGateSignal', -1);
            }
        }
        $fp = $this->ReadPropertyInteger('PresetVariable');
        $fw = $this->ReadPropertyInteger('PowerVariable');
        $rp = $this->ReadPropertyInteger('RearPresetVariable');
        $rw = $this->ReadPropertyInteger('RearPowerVariable');
        // Nie zwei Seiten auf dieselben Variablen oder dasselbe Gerät schalten.
        if (count(array_unique([$fp,$fw,$rp,$rw])) !== 4 ||
            ($this->IsVariable($fw, VARIABLETYPE_BOOLEAN) && $this->IsVariable($rw, VARIABLETYPE_BOOLEAN) &&
             IPS_GetParent($fw) === IPS_GetParent($rw))) {
            $this->SetTimerInterval('Synchronize', 2000);
            $this->SetStatusText('Konfigurationsfehler: WLED vorne/hinten nicht eindeutig getrennt.');
            return;
        }
        $rearReady = $this->ReadPropertyBoolean('RearPresetsVerified');
        // Ungeprüfte hintere Presets nie aufrufen. AUS bleibt auch ohne Preset möglich.
        [$rearOK, $rear] = $this->SyncSide($rp, $rw, $rearReady ? $signal : 0, 'LastRearSignal');
        [$frontOK, $front] = $this->SyncSide($fp, $fw, $signal, 'LastFrontSignal');
        $this->SetTimerInterval('Synchronize', ($signal !== 0 || !$frontOK || !$rearOK) ? 2000 : 10000);
        $this->SetStatusText($note . 'Signal ' . $signal . ' | vorne: ' . $front .
            ' | hinten: ' . $rear . ($rearReady ? '' : ' (Presets noch nicht freigegeben)'));
    }

    /** Verbindungsstatus einschließlich Gateway und WebSocket, nicht nur gespeicherte Variablen. */
    private function ConnectionActive(int $instance): bool
    {
        if ($instance <= 0) return false;
        $visited = [];
        while ($instance > 0) {
            if (isset($visited[$instance]) || !IPS_InstanceExists($instance)) return false;
            $visited[$instance] = true;
            $info = IPS_GetInstance($instance);
            if ((int)$info['InstanceStatus'] !== 102) return false;
            $instance = (int)$info['ConnectionID'];
        }
        return true;
    }

    /** @return array{0: bool, 1: string} */
    private function SyncSide(int $preset, int $power, int $signal, string $lastKey): array
    {
        if (!$this->IsVariable($power, VARIABLETYPE_BOOLEAN)) {
            $this->WriteAttributeInteger($lastKey, -1);
            return [false, 'Ein/Aus fehlt'];
        }
        try {
            $parent = IPS_GetParent($power);
            if (!$this->ConnectionActive($parent)) {
                $this->WriteAttributeInteger($lastKey, -1);
                return [false, 'Verbindung gestört – tatsächlicher LED-Zustand unbekannt'];
            }
            $presetValid = $this->IsVariable($preset, VARIABLETYPE_INTEGER) && IPS_GetParent($preset) === $parent;
            if ($signal !== 0 && !$presetValid) {
                // Bei fehlender Preset-Zuordnung bestmöglich ausschalten, nicht blind einschalten.
                RequestAction($power, false);
                $this->WriteAttributeInteger($lastKey, -1);
                return [false, 'Preset fehlt – AUS angefordert'];
            }
            $last = $this->ReadAttributeInteger($lastKey);
            $on = GetValueBoolean($power);
            $matches = $signal === 0 ? !$on : $on && GetValueInteger($preset) === $signal;
            if ($matches && $last === $signal) {
                return [true, $signal === 0 ? 'AUS (Rückmeldung)' : 'Preset ' . $signal . ' (Rückmeldung)'];
            }
            // Bei neuem Sollsignal oder Wiederverbindung auch dann senden, wenn Variablen
            // noch den alten gecachten Sollwert zeigen. Niemals alte Befehle puffern.
            $ok = $signal === 0 ? RequestAction($power, false) : RequestAction($preset, $signal);
            if (!$ok) {
                $this->WriteAttributeInteger($lastKey, -1);
                if ($signal !== 0) {
                    try { RequestAction($power, false); } catch (Throwable $ignored) {}
                }
                return [false, 'Befehl fehlgeschlagen – Zustand nicht bestätigt'];
            }
            $this->WriteAttributeInteger($lastKey, $signal);
            return [true, $signal === 0 ? 'AUS angefordert' : 'Preset ' . $signal . ' angefordert'];
        } catch (Throwable $e) {
            $this->WriteAttributeInteger($lastKey, -1);
            if ($signal !== 0) {
                try { RequestAction($power, false); } catch (Throwable $ignored) {}
            }
            return [false, 'Fehler: ' . $e->getMessage()];
        }
    }

    private function IsVariable(int $id, int $type): bool
    {
        return $id > 0 && IPS_VariableExists($id) &&
            (int) IPS_GetVariable($id)['VariableType'] === $type;
    }

    private function SetStatusText(string $text): void
    {
        if ((string)$this->GetValue('AmpelStatus') !== $text) {
            $this->SetValue('AmpelStatus', $text);
        }
    }
}
