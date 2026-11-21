<?php
declare(strict_types=1);

/** JV Ampel: ausschließlich WLED-Master-Presetansteuerung (Tor und CS320 unberührt). */
class JVAmpel extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableAmpel', false);
        $this->RegisterPropertyInteger('PositionVariable', 0);
        $this->RegisterPropertyInteger('PresetVariable', 46199);
        $this->RegisterPropertyInteger('PowerVariable', 29605);
        $this->RegisterPropertyBoolean('SwitchOffWhenStopped', true);
        $this->RegisterAttributeInteger('SubscribedVariable', 0);
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $this->RegisterTimer('Synchronize', 10000, 'JVGA_Sync($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $previous = $this->ReadAttributeInteger('SubscribedVariable');
        $current = $this->ReadPropertyInteger('PositionVariable');
        if ($previous > 0 && ($previous !== $current || !IPS_VariableExists($previous))) {
            $this->UnregisterMessage($previous, VM_UPDATE);
        }
        if ($current > 0 && $this->IsVariable($current, VARIABLETYPE_STRING)) {
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
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('PositionVariable')) {
            $this->Sync();
        }
    }

    public function Sync(): void
    {
        if (!$this->ReadPropertyBoolean('EnableAmpel')) {
            $this->SetStatusText('Automatik AUS');
            return;
        }
        $source = $this->ReadPropertyInteger('PositionVariable');
        $presetVar = $this->ReadPropertyInteger('PresetVariable');
        if (!$this->IsVariable($source, VARIABLETYPE_STRING) ||
            !$this->IsVariable($presetVar, VARIABLETYPE_INTEGER)) {
            $this->SetStatusText('Konfigurationsfehler: Position oder WLED-Voreinstellungen fehlen.');
            return;
        }
        $position = GetValueString($source);
        $preset = match ($position) {
            'LÄUFT AUF' => 1,
            'AUF' => 2,
            'LÄUFT ZU' => 3,
            'ZU' => 4,
            default => 0
        };
        // Nie eine Endlage nur aus einer Zwischenposition ableiten.
        if ($position === 'GESTOPPT') {
            if (!$this->ReadPropertyBoolean('SwitchOffWhenStopped')) {
                $this->SetStatusText('GESTOPPT – Anzeige unverändert (bewusst konfiguriert).');
                return;
            }
            $power = $this->ReadPropertyInteger('PowerVariable');
            if (!$this->IsVariable($power, VARIABLETYPE_BOOLEAN)) {
                $this->SetStatusText('GESTOPPT – WLED-Ein/Aus-Variable fehlt; keine Umschaltung.');
                return;
            }
            if (GetValueBoolean($power)) {
                try {
                    if (!RequestAction($power, false)) {
                        $this->SetStatusText('GESTOPPT – Ausschalten fehlgeschlagen.');
                        return;
                    }
                } catch (Throwable $e) {
                    $this->SetStatusText('WLED Fehler: ' . $e->getMessage());
                    return;
                }
            }
            $this->SetStatusText('GESTOPPT – WLED AUS (nicht gleichbedeutend mit ZU).');
            return;
        }
        if ($preset < 1 || $preset > 4) {
            $this->SetStatusText('Nicht unterstützter Torstatus – keine WLED-Änderung.');
            return;
        }
        $power = $this->ReadPropertyInteger('PowerVariable');
        $isPowerOff = $this->IsVariable($power, VARIABLETYPE_BOOLEAN) && !GetValueBoolean($power);
        if (GetValueInteger($presetVar) === $preset && !$isPowerOff) {
            $this->SetStatusText($position . ' – Preset ' . $preset . ' aktiv.');
            return;
        }
        try {
            if (!RequestAction($presetVar, $preset)) {
                $this->SetStatusText($position . ' – Preset ' . $preset . ' nicht angenommen.');
                return;
            }
            $this->SetStatusText($position . ' – Preset ' . $preset . ' an WLED vorne gesendet.');
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
