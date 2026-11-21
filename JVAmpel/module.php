<?php
declare(strict_types=1);

/** JV Ampel: ausschließlich WLED-Master-Presetansteuerung (Tor und CS320 unberührt). */
class JVAmpel extends IPSModuleStrict
{
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

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $previous = $this->ReadAttributeInteger('SubscribedVariable');
        $current = $this->ReadPropertyInteger('SignalVariable');
        if ($previous > 0 && ($previous !== $current || !IPS_VariableExists($previous))) {
            $this->UnregisterMessage($previous, VM_UPDATE);
        }
        if ($current > 0 && $this->IsVariable($current, VARIABLETYPE_INTEGER)) {
            $this->RegisterMessage($current, VM_UPDATE);
            $this->WriteAttributeInteger('SubscribedVariable', $current);
        } else {
            $this->WriteAttributeInteger('SubscribedVariable', 0);
        }
        $this->SetTimerInterval('Synchronize', 10000);
        $this->Sync();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('SignalVariable')) {
            $this->Sync();
        }
    }

    public function Sync(): void
    {
        if (!$this->ReadPropertyBoolean('EnableAmpel')) {
            $this->SetStatusText('Automatik AUS');
            return;
        }
        $source = $this->ReadPropertyInteger('SignalVariable');
        $presetVar = $this->ReadPropertyInteger('PresetVariable');
        if (!$this->IsVariable($source, VARIABLETYPE_INTEGER) ||
            !$this->IsVariable($presetVar, VARIABLETYPE_INTEGER)) {
            $this->SetStatusText('Konfigurationsfehler: Ampel-Signal oder WLED-Voreinstellungen fehlen.');
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
