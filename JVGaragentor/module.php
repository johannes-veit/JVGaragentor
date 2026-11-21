<?php
declare(strict_types=1);

/**
 * JVGaragentor – IP-Symcon 9.0 / HTML-SDK.
 *
 * Der Positionsstatus ist eine Anzeige-/Diagnosefunktion, KEINE
 * sicherheitsgerichtete Überwachung. Mechanische und elektrische
 * Schutzeinrichtungen bleiben vollständig Aufgabe der CS320.
 */
class JVGaragentor extends IPSModuleStrict
{
    private const MOVING_OPEN = 'LÄUFT AUF';
    private const MOVING_CLOSE = 'LÄUFT ZU';
    private const STOPPED = 'GESTOPPT';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyInteger('RelayOpenVariable', 44228);
        $this->RegisterPropertyInteger('RelayCloseVariable', 56652);
        $this->RegisterPropertyInteger('RelayStopVariable', 55944);
        $this->RegisterPropertyInteger('RelayOnlineVariable', 17585);
        $this->RegisterPropertyInteger('EndOpenVariable', 0);
        $this->RegisterPropertyInteger('EndCloseVariable', 0);
        // Nur vorläufige Startwerte: reale Laufzeit vor Freigabe messen!
        $this->RegisterPropertyInteger('TravelSeconds', 60);
        $this->RegisterPropertyInteger('ReserveSeconds', 15);
        $this->RegisterAttributeInteger('MovementStartedAt', 0);
        $this->RegisterTimer('Monitor', 1000, 'JVG_Monitor($_IPS["TARGET"]);');
        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->RegisterVariableString('Position', 'Position', [], 0)) {
            $this->SetValue('Position', self::STOPPED);
        }
        $this->SetTimerInterval('Monitor', 1000);
        $this->Monitor();
        $this->Broadcast();
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<p>Garagentor-Kachel nicht gefunden.</p>';
        }

        $json = json_encode(
            $this->StatePayload(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return str_replace('__INITIAL_STATE__', $json === false ? '{}' : $json, $html);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Command' || !is_string($Value) || !in_array($Value, ['AUF', 'STOP', 'ZU'], true)) {
            throw new InvalidArgumentException('Ungültiger Garagentor-Befehl');
        }
        if (!$this->ReadPropertyBoolean('EnableControl')) {
            $this->SendDebug('Befehl', 'Toransteuerung deaktiviert – kein Befehl gesendet', 0);
            return;
        }
        if (!$this->IsOnline()) {
            throw new RuntimeException('Garagentor-Shelly ist nicht erreichbar');
        }

        $relayIds = [
            'AUF'  => $this->ReadPropertyInteger('RelayOpenVariable'),
            'ZU'   => $this->ReadPropertyInteger('RelayCloseVariable'),
            'STOP' => $this->ReadPropertyInteger('RelayStopVariable')
        ];
        // Alle drei Relais müssen korrekt zugeordnet sein, bevor eine Fahrt möglich ist.
        foreach ($relayIds as $name => $id) {
            if (!$this->IsBooleanVariable($id)) {
                throw new RuntimeException('Shelly-Relais ' . $name . ' fehlt oder ist nicht Boolean');
            }
        }

        // Shelly muss den Impuls autonom nach 1 Sekunde zurückstellen:
        // AUF/ZU: EIN -> Auto-OFF. STOP: AUS -> Auto-ON.
        $relayValue = $Value !== 'STOP';
        if (!RequestAction($relayIds[$Value], $relayValue)) {
            throw new RuntimeException('Shelly-Befehl konnte nicht gesendet werden');
        }

        if ($Value === 'STOP') {
            $this->WriteAttributeInteger('MovementStartedAt', 0);
            $this->UpdatePosition($this->ResolvedEndPosition() ?? self::STOPPED);
            return;
        }

        $this->WriteAttributeInteger('MovementStartedAt', time());
        $this->UpdatePosition($Value === 'AUF' ? self::MOVING_OPEN : self::MOVING_CLOSE);
    }

    public function Monitor(): void
    {
        $position = (string) $this->GetValue('Position');
        $open = $this->EndActive('EndOpenVariable');
        $closed = $this->EndActive('EndCloseVariable');

        if ($open && $closed) {
            // Widersprüchliche Endlagensignale niemals als Endlage anzeigen.
            $this->SendDebug('Endlagen', 'Beide Endlagen melden gleichzeitig TRUE', 0);
            $this->WriteAttributeInteger('MovementStartedAt', 0);
            $this->UpdatePosition(self::STOPPED);
            return;
        }

        if ($position === self::MOVING_OPEN) {
            if ($open) {
                $this->Arrived('AUF');
                return;
            }
        } elseif ($position === self::MOVING_CLOSE) {
            if ($closed) {
                $this->Arrived('ZU');
                return;
            }
        } else {
            // Im Ruhezustand haben gemeldete Endlagen Vorrang.
            $resolved = $open ? 'AUF' : ($closed ? 'ZU' : null);
            if ($resolved !== null) {
                $this->UpdatePosition($resolved);
            } elseif ($position === 'AUF' || $position === 'ZU') {
                // Endlage hat ihre Bestätigung verloren: keine Schein-Endlage behalten.
                if ($this->ReadPropertyInteger($position === 'AUF' ? 'EndOpenVariable' : 'EndCloseVariable') > 0) {
                    $this->UpdatePosition(self::STOPPED);
                }
            }
            return;
        }

        $started = $this->ReadAttributeInteger('MovementStartedAt');
        $limit = max(5, $this->ReadPropertyInteger('TravelSeconds'))
            + max(1, $this->ReadPropertyInteger('ReserveSeconds'));
        if ($started <= 0 || time() - $started >= $limit) {
            $this->WriteAttributeInteger('MovementStartedAt', 0);
            $this->UpdatePosition(self::STOPPED);
        }
    }

    private function Arrived(string $position): void
    {
        $this->WriteAttributeInteger('MovementStartedAt', 0);
        $this->UpdatePosition($position);
    }

    private function ResolvedEndPosition(): ?string
    {
        $open = $this->EndActive('EndOpenVariable');
        $closed = $this->EndActive('EndCloseVariable');
        if ($open === $closed) {
            return null;
        }
        return $open ? 'AUF' : 'ZU';
    }

    private function EndActive(string $property): bool
    {
        $id = $this->ReadPropertyInteger($property);
        return $this->IsBooleanVariable($id) && GetValueBoolean($id);
    }

    private function IsBooleanVariable(int $id): bool
    {
        return $id > 0
            && IPS_VariableExists($id)
            && IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_BOOLEAN;
    }

    private function UpdatePosition(string $position): void
    {
        if ((string) $this->GetValue('Position') === $position) {
            return;
        }
        $this->SetValue('Position', $position);
        $this->Broadcast();
    }

    private function IsOnline(): bool
    {
        $id = $this->ReadPropertyInteger('RelayOnlineVariable');
        return $this->IsBooleanVariable($id) && GetValueBoolean($id);
    }

    private function StatePayload(): array
    {
        return [
            'position' => (string) $this->GetValue('Position'),
            'enabled' => $this->ReadPropertyBoolean('EnableControl'),
            'online' => $this->IsOnline()
        ];
    }

    private function Broadcast(): void
    {
        $data = json_encode($this->StatePayload(), JSON_UNESCAPED_UNICODE);
        if ($data !== false) {
            $this->UpdateVisualizationValue($data);
        }
    }
}
