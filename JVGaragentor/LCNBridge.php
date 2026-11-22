<?php
declare(strict_types=1);

trait JVLCNBridgeTrait
{
    private function LCNCreate(): void
    {
        // Jede physische Taste kann ein eigenes LCN-Modul haben.
        // Ohne ausdrückliche Freigabe werden niemals Fahrbefehle gesendet.
        foreach (['Garage', 'Schiebe'] as $channel) {
            $this->RegisterPropertyBoolean('LCN' . $channel . 'Enabled', false);
            $this->RegisterPropertyInteger('LCN' . $channel . 'Module', 0);
            $this->RegisterPropertyInteger('LCN' . $channel . 'HostVariable', 0);
            $this->RegisterPropertyString('LCN' . $channel . 'Table', 'C');
            $this->RegisterPropertyInteger('LCN' . $channel . 'Key', $channel === 'Garage' ? 1 : 2);
            $this->RegisterPropertyString('LCN' . $channel . 'Short', 'ZU');
            $this->RegisterPropertyString('LCN' . $channel . 'Long', 'AUF');
            $this->RegisterPropertyString('LCN' . $channel . 'Release', 'NONE');
        }
        // Vorhandene Schiebetor-Skripte aus dem SymBox-Backup vom 07.11.2026.
        // Bewusst NICHT direkt an die Shelly-Relais senden.
        $this->RegisterPropertyInteger('LCNSchiebeOpenScript', 12119);
        $this->RegisterPropertyInteger('LCNSchiebeCloseScript', 26612);
        $this->RegisterPropertyInteger('LCNSchiebeStopScript', 29055);
        $this->RegisterAttributeString('LCNSubscriptions', '[]');
        $this->RegisterAttributeString('LCNLastDispatch', '{}');
        $this->RegisterVariableString('LCNActivity', 'LCN letzte Tastenmeldung', [], 3);
    }

    private function LCNApplyChanges(): void
    {
        $old = json_decode($this->ReadAttributeString('LCNSubscriptions'), true);
        $old = is_array($old) ? array_map('intval', $old) : [];
        $wanted = [];
        foreach (['Garage', 'Schiebe'] as $channel) {
            $id = $this->ReadPropertyInteger('LCN' . $channel . 'HostVariable');
            if ($this->ValidVariable($id, VARIABLETYPE_STRING)) {
                $wanted[] = $id;
            }
        }
        $wanted = array_values(array_unique($wanted));
        foreach (array_diff($old, $wanted) as $id) {
            $this->UnregisterMessage((int)$id, VM_UPDATE);
        }
        foreach (array_diff($wanted, $old) as $id) {
            $this->RegisterMessage((int)$id, VM_UPDATE);
        }
        $this->WriteAttributeString('LCNSubscriptions', json_encode($wanted) ?: '[]');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE || !$this->ValidVariable($SenderID, VARIABLETYPE_STRING)) {
            return;
        }
        $this->ProcessLCNInput($SenderID, GetValueString($SenderID));
    }

    /**
     * Wert aus einer offiziellen LCN-Host-Kommando-Stringvariable.
     * Verarbeitet nur die freigegebenen, eindeutig zugeordneten Tasten.
     * @return bool true ausschließlich bei akzeptiertem Tor-Befehl.
     */
    public function ProcessLCNInput(int $sourceID, string $raw): bool
    {
        $allowed = [
            'Garage' => $this->ReadPropertyInteger('LCNGarageHostVariable'),
            'Schiebe' => $this->ReadPropertyInteger('LCNSchiebeHostVariable')
        ];
        if (!in_array($sourceID, array_values($allowed), true) ||
            !$this->ValidVariable($sourceID, VARIABLETYPE_STRING)) {
            return false;
        }
        $code = JVLCNCodec::normalize($raw);
        if ($code === null) {
            $this->RecordLCN('LCN Quelle #' . $sourceID . ': Host-Kommando ungültig/kein Tastencode');
            return false;
        }
        $matches = [];
        foreach (['Garage','Schiebe'] as $channel) {
            if ($allowed[$channel] !== $sourceID) {
                continue;
            }
            foreach (['KURZ' => 'Short', 'LANG' => 'Long', 'LOS' => 'Release'] as $gesture => $suffix) {
                if (JVLCNCodec::code(
                    $this->ReadPropertyString('LCN' . $channel . 'Table'),
                    $this->ReadPropertyInteger('LCN' . $channel . 'Key'),
                    $gesture
                ) === $code) {
                    $matches[] = ['channel' => $channel, 'gesture' => $gesture,
                        'action' => $this->ReadPropertyString('LCN' . $channel . $suffix)];
                }
            }
        }
        $description = JVLCNCodec::describe($code) . ' (Quelle #' . $sourceID . ')';
        if (count($matches) === 0) {
            $this->RecordLCN('Host-Kommando ' . $description . ' – nicht zugeordnet');
            return false;
        }
        if (count($matches) !== 1) {
            $this->RecordLCN('SPERRE: mehrdeutiger Host-Code ' . $description .
                ' – beide Tore bleiben unverändert');
            return false;
        }
        $hit = $matches[0];
        $channel = $hit['channel'];
        $action = $hit['action'];
        $moduleID = $this->ReadPropertyInteger('LCN' . $channel . 'Module');
        if (!in_array($action, ['AUF', 'ZU', 'STOP', 'NONE'], true)) {
            $this->RecordLCN('SPERRE: ungültiger Fahrbefehl für ' . $description);
            return false;
        }
        if ($moduleID <= 0 || !IPS_InstanceExists($moduleID) ||
            !$this->LCNIsSourceOfModule($sourceID, $moduleID)) {
            $this->RecordLCN('SPERRE: Host-Kommando ' . $description .
                ' liegt nicht unter dem gewählten LCN-Modul (Mxx)');
            return false;
        }
        if (!$this->ReadPropertyBoolean('LCN' . $channel . 'Enabled')) {
            $this->RecordLCN('TEST: ' . $channel . ', ' . $description .
                ', Zuordnung ' . $action . ' – LCN-Freigabe AUS');
            return false;
        }
        if ($action === 'NONE') {
            $this->RecordLCN($channel . ' ' . $description . ': keine Aktion');
            return false;
        }
        $fingerprint = $sourceID . ':' . $code;
        $last = json_decode($this->ReadAttributeString('LCNLastDispatch'), true);
        $now = microtime(true);
        if (is_array($last) && ($last['code'] ?? '') === $fingerprint &&
            $now - (float)($last['at'] ?? 0) < 0.5) {
            $this->RecordLCN('Doppelmeldung unterdrückt: ' . $description);
            return false;
        }
        // Vor dem Versenden markieren: schützt auch gegen synchronen Wiedereintritt.
        $this->WriteAttributeString('LCNLastDispatch',
            json_encode(['code' => $fingerprint, 'at' => $now]) ?: '{}');

        if ($channel === 'Garage') {
            // Gleiche Prüfung wie in der Visualisierung, niemals Bypass.
            $ok = $this->Command($action);
        } else {
            $script = $this->ReadPropertyInteger('LCNSchiebe' .
                match($action) {'AUF'=>'OpenScript','ZU'=>'CloseScript','STOP'=>'StopScript'});
            if ($script <= 0 || !IPS_ScriptExists($script)) {
                $this->RecordLCN('SPERRE: Schiebetor-Skript für ' . $action .
                    ' ist nicht vorhanden / nicht zugeordnet');
                return false;
            }
            try {
                $ok = IPS_RunScript($script) === true;
            } catch (Throwable $e) {
                $this->SendDebug('LCN Schiebetor', $e->getMessage(), 0);
                $ok = false;
            }
        }
        $this->RecordLCN($channel . ' ' . $description . ' → ' . $action .
            ($ok ? ' gesendet' : ' NICHT AUSGEFÜHRT'));
        return $ok;
    }

    /**
     * Die LCN-Host-Kommando-Instanz ist im Objektbaum Kind des sendenden
     * LCN-Moduls. Nur dessen Befehl darf diesen Tastenkanal auslösen.
     */
    private function LCNIsSourceOfModule(int $variableID, int $moduleID): bool
    {
        $parent = $variableID;
        for ($i = 0; $i < 8 && $parent > 0; $i++) {
            if ($parent === $moduleID) {
                return true;
            }
            if (!IPS_ObjectExists($parent)) {
                return false;
            }
            $parent = IPS_GetParent($parent);
        }
        return false;
    }

    private function RecordLCN(string $message): void
    {
        $message = date('H:i:s') . ' ' . $message;
        $this->SetValue('LCNActivity', $message);
        $this->SendDebug('LCN Tastendruck', $message, 0);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{"elements":[],"actions":[]}';
        }
        foreach (['Garage' => 'Garagentor', 'Schiebe' => 'Schiebetor'] as $channel => $title) {
            $table = $this->ReadPropertyString('LCN' . $channel . 'Table');
            $key = $this->ReadPropertyInteger('LCN' . $channel . 'Key');
            $form['elements'][] = [
                'type' => 'Label',
                'caption' => $title . ' LCN-PRO: Taste ' . $table . $key .
                ' – KURZ: ' . JVLCNCodec::command($table, $key, 'KURZ') .
                ', LANG: ' . JVLCNCodec::command($table, $key, 'LANG') .
                ', LOS: ' . JVLCNCodec::command($table, $key, 'LOS') .
                '. Ziel = Host-ID der LCN-Kopplung (oft M004, prüfen!).'
            ];
        }
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
