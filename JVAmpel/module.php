<?php
declare(strict_types=1);

/**
 * JV Garagentor – unabhängige WLED-Ampel.
 * Ausschließlich Preset-Steuerung. Keine Schaltverbindung zur CS320.
 */
class JVAmpel extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableAmpel', false);
        $this->RegisterPropertyInteger('PositionVariable', 0);
        $this->RegisterPropertyInteger('PresetVariable', 46199);
        $this->RegisterPropertyInteger('StopPreset', 0);
        $this->RegisterAttributeInteger('SubscribedVariable', 0);
        $this->RegisterVariableString('AmpelStatus', 'Ampel-Status', [], 0);
        $this->RegisterTimer('Retry', 30000, 'JVGA_Sync($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $old = $this->ReadAttributeInteger('SubscribedVariable');
        $new = $this->ReadPropertyInteger('PositionVariable');
        if ($old > 0 && $old !== $new && IPS_VariableExists($old)) {
            $this->UnregisterMessage($old, VM_UPDATE);
        }
        if ($new > 0 && IPS_VariableExists($new)) {
            $this->RegisterMessage($new, VM_UPDATE);
            $this->WriteAttributeInteger('SubscribedVariable', $new);
        } else {
            $this->WriteAttributeInteger('SubscribedVariable', 0);
        }
        $this->SetTimerInterval('Retry', 30000);
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
            $this->SetValue('AmpelStatus', 'Automatik AUS');
            return;
        }
        $source = $this->ReadPropertyInteger('PositionVariable');
        if (!$this->IsVariableType($source, VARIABLETYPE_STRING)) {
            $this->SetValue('AmpelStatus', 'Positionsvariable fehlt oder ist nicht String');
            return;
        }
        $position = GetValueString($source);
        $preset = match ($position) {
            'LÄUFT AUF' => 1,
            'AUF' => 2,
            'LÄUFT ZU' => 3,
            'ZU' => 4,
            'GESTOPPT' => $this->ReadPropertyInteger('StopPreset'),
            default => 0
        };
        if ($preset < 1 || $preset > 4) {
            $this->SetValue('AmpelStatus', 'Kein Preset für ' . $position);
            return;
        }
        $variable = $this->ReadPropertyInteger('PresetVariable');
        if (!$this->IsVariableType($variable, VARIABLETYPE_INTEGER)) {
            $this->SetValue('AmpelStatus', 'WLED vorne: Preset-Variable fehlt oder ist nicht Integer');
            return;
        }
        // Nur der fuehrende WLED-Controller wird angesteuert.
        // Die Synchronisierung zum hinteren Controller erfolgt in WLED selbst.
        if (GetValueInteger($variable) === $preset) {
            $this->SetValue('AmpelStatus', $position . ' → Preset ' . $preset . ' (bereits ausgewaehlt)');
            return;
        }
        try {
            if (!RequestAction($variable, $preset)) {
                $this->SetValue('AmpelStatus', $position . ' → Preset ' . $preset . ' (Senden fehlgeschlagen)');
                return;
            }
            $this->SetValue('AmpelStatus', $position . ' → Preset ' . $preset . ' an WLED vorne gesendet');
        } catch (Throwable $e) {
            $this->SetValue('AmpelStatus', 'WLED-Fehler: ' . $e->getMessage());
        }
    }

    private function IsVariableType(int $id, int $type): bool
    {
        return $id > 0
            && IPS_VariableExists($id)
            && IPS_GetVariable($id)['VariableType'] === $type;
    }
}
