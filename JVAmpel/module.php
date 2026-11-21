<?php
declare(strict_types=1);

/** JV Ampel: ausschließlich WLED-Master-Presetansteuerung (Tor und CS320 unberührt). */
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
            $this->SetStatusText('Automatik AUS');
            return;
        }
        $presetVar = $this->ReadPropertyInteger('PresetVariable');
        if (!$this->IsVariable($source, VARIABLETYPE_INTEGER) ||
            !$this->IsVariable($presetVar, VARIABLETYPE_INTEGER)) {
            // Keine Tor-Statusquelle: keine zuvor signalisierte Fahrt weiter anzeigen.
            // Nur aktiv, wenn die Ampelautomatik ausdrücklich eingeschaltet ist.
            $powerID = $this->ReadPropertyInteger('PowerVariable');
            if ($this->IsVariable($powerID, VARIABLETYPE_BOOLEAN) && GetValueBoolean($powerID)) {
                try {
                    RequestAction($powerID, false);
                } catch (Throwable $e) {
                    $this->SetStatusText('Fehler beim Ausschalten nach Signalverlust: ' . $e->getMessage());
                    return;
                }
            }
            $this->SetStatusText('Ampel AUS: Signalquelle oder WLED-Voreinstellungen fehlen. Torinstanz und Verknüpfung prüfen.');
            return;
        }
        $signal = GetValueInteger($source);
        if (!in_array($signal, [0, 1, 2, 3, 4], true)) {
            $this->SetStatusText('Ungültiges Ampel-Signal – WLED vorsorglich AUS.');
            $signal = 0;
        }
        // Signal 0 enthält STOPP, Timeout und abgelaufene Nachleuchtzeit.
        if ($signal === 0) {
            $power = $this->ReadPropertyInteger('PowerVariable');
            if (!$this->IsVariable($power, VARIABLETYPE_BOOLEAN)) {
                $this->SetStatusText('Ampel AUS – WLED-Ein/Aus-Variable fehlt.');
                return;
            }
            if (GetValueBoolean($power)) {
                try {
                    if (!RequestAction($power, false)) {
                        $this->SetStatusText('Ampel AUS – Ausschalten fehlgeschlagen.');
                        return;
                    }
                } catch (Throwable $e) {
                    $this->SetStatusText('WLED Fehler: ' . $e->getMessage());
                    return;
                }
            }
            $this->SetStatusText('Ampel AUS (Signal 0).');
            return;
        }
        if ($signal < 1 || $signal > 4) {
            $this->SetStatusText('Nicht unterstützter Torstatus – keine WLED-Änderung.');
            return;
        }
        $power = $this->ReadPropertyInteger('PowerVariable');
        $isPowerOff = $this->IsVariable($power, VARIABLETYPE_BOOLEAN) && !GetValueBoolean($power);
        if (GetValueInteger($presetVar) === $signal && !$isPowerOff) {
            $this->SetStatusText('Signal ' . $signal . ' – Preset ' . $signal . ' aktiv.');
            return;
        }
        try {
            if (!RequestAction($presetVar, $signal)) {
                $this->SetStatusText('Signal ' . $signal . ' – Preset ' . $signal . ' nicht angenommen.');
                return;
            }
            $this->SetStatusText('Signal ' . $signal . ' – Preset ' . $signal . ' an WLED vorne gesendet.');
        } catch (Throwable $e) {
            $this->SetStatusText('WLED Fehler: ' . $e->getMessage());
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
