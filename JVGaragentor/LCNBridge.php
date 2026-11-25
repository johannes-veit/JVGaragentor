<?php
declare(strict_types=1);

trait JVLCNBridgeTrait
{
    /** Objektbaum dieser SymBox: M51 = Kategorie 37682, darunter
     *  Host-Kommando-Instanz 30888 mit Stringvariable 35739.
     *  Explizite Auswahlen haben Vorrang. Ohne Auswahl wird nur dieser
     *  nachprüfbare Vater-Kind-Pfad automatisch übernommen.
     */
    private function LCNHostVariable(string $channel): int
    {
        $configured = $this->ReadPropertyInteger('LCN' . $channel . 'HostVariable');
        if ($configured > 0) {
            return $configured;
        }
        return IPS_InstanceExists(30888) &&
            $this->ValidVariable(35739, VARIABLETYPE_STRING) &&
            IPS_GetParent(35739) === 30888 ? 35739 : 0;
    }

    private function LCNHostInstance(string $channel): int
    {
        $configured = $this->ReadPropertyInteger('LCN' . $channel . 'Module');
        if ($configured > 0) {
            return $configured;
        }
        return $this->LCNHostVariable($channel) === 35739 &&
            IPS_InstanceExists(30888) ? 30888 : 0;
    }

    private function LCNCreate(): void
    {
        // Jede physische Taste kann ein eigenes LCN-Modul haben.
        // Ohne ausdrückliche Freigabe werden niemals Fahrbefehle gesendet.
        foreach (['Garage', 'Schiebe'] as $channel) {
            $this->RegisterPropertyBoolean('LCN' . $channel . 'Enabled', false);
            $this->RegisterPropertyInteger('LCN' . $channel . 'Module', 30888);
            $this->RegisterPropertyInteger('LCN' . $channel . 'HostVariable', 35739);
            $this->RegisterPropertyString('LCN' . $channel . 'Table', 'C');
            $this->RegisterPropertyInteger('LCN' . $channel . 'Key', $channel === 'Garage' ? 2 : 1);
            $this->RegisterPropertyString('LCN' . $channel . 'Short', 'ZU');
            $this->RegisterPropertyString('LCN' . $channel . 'Long', 'AUF');
            $this->RegisterPropertyString('LCN' . $channel . 'Release', 'NONE');
        }
        // Vorhandene Schiebetor-Skripte aus dem SymBox-Backup vom 07.11.2026.
        // Bewusst NICHT direkt an die Shelly-Relais senden.
        $this->RegisterPropertyInteger('LCNSchiebeOpenScript', 12119);
        $this->RegisterPropertyInteger('LCNSchiebeCloseScript', 26612);
        $this->RegisterPropertyInteger('LCNSchiebeStopScript', 29055);
        $this->RegisterAttributeBoolean('LCNCommissionDone125', false);
        // Execute configuration change outside the instance method, not recursively in ApplyChanges.
        $this->RegisterTimer('LCNCommission125', 0, 'if (JVG_LCNCommissionReady($_IPS["TARGET"])) { $id = $_IPS["TARGET"]; if (!IPS_HasChanges($id) && IPS_SetProperty($id, "EndOpenVariable", 13220) && IPS_SetProperty($id, "EndCloseVariable", 21923) && IPS_SetProperty($id, "RequireEndStops", true) && IPS_SetProperty($id, "LCNGarageEnabled", true)) { IPS_ApplyChanges($id); } }');
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
            $id = $this->LCNHostVariable($channel);
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

    /**
     * One-time commissioning requested for existing, tested garage #15957 only.
     * Does not enable the master switch, alter C1 or send a relay command.
     * If mapping differs, no automatic activation. Later manual disable stays disabled.
     */
    private function LCNScheduleCommission(): void
    {
        if ($this->InstanceID !== 15957 || $this->ReadAttributeBoolean('LCNCommissionDone125')) {
            $this->SetTimerInterval('LCNCommission125', 0);
            return;
        }
        if ($this->LCNCommissionMappingOK() &&
            $this->ReadPropertyBoolean('LCNGarageEnabled') &&
            $this->ReadPropertyBoolean('RequireEndStops') &&
            $this->ReadPropertyInteger('EndOpenVariable') === self::END_OPEN_ID &&
            $this->ReadPropertyInteger('EndCloseVariable') === self::END_CLOSE_ID) {
            $this->WriteAttributeBoolean('LCNCommissionDone125', true);
            $this->SetTimerInterval('LCNCommission125', 0);
            return;
        }
        $this->SetTimerInterval('LCNCommission125', $this->LCNCommissionMappingOK() ? 1000 : 0);
    }

    private function LCNCommissionMappingOK(): bool
    {
        if ($this->InstanceID !== 15957 ||
            $this->LCNHostInstance('Garage') !== 30888 || $this->LCNHostVariable('Garage') !== 35739 ||
            !IPS_InstanceExists(30888) || !$this->ValidVariable(35739, VARIABLETYPE_STRING) ||
            IPS_GetParent(35739) !== 30888 || IPS_GetParent(30888) !== 37682 ||
            $this->ReadPropertyString('LCNGarageTable') !== 'C' ||
            $this->ReadPropertyInteger('LCNGarageKey') !== 2 ||
            $this->ReadPropertyString('LCNGarageShort') !== 'ZU' ||
            $this->ReadPropertyString('LCNGarageLong') !== 'AUF' ||
            $this->ReadPropertyString('LCNGarageRelease') !== 'NONE' ||
            ($this->LCNHostVariable('Schiebe') === 35739 &&
             strtoupper($this->ReadPropertyString('LCNSchiebeTable')) === 'C' &&
             $this->ReadPropertyInteger('LCNSchiebeKey') === 2) ||
            $this->ReadPropertyInteger('OnlineVariable') !== 17585 ||
            $this->RelayIds() !== ['AUF'=>44228,'STOP'=>55944,'ZU'=>56652] ||
            $this->EndVariableId('EndOpenVariable') !== self::END_OPEN_ID ||
            $this->EndVariableId('EndCloseVariable') !== self::END_CLOSE_ID) return false;
        foreach ([44228,56652,55944,17585,self::END_OPEN_ID,self::END_CLOSE_ID] as $id) {
            if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN) || IPS_GetParent($id) !== self::SHELLY_INSTANCE) return false;
        }
        foreach ([44228,56652,55944] as $id) if (!$this->HasAction($id)) return false;
        return true;
    }

    /** Called by separate timer; never executes or replays a stored LCN telegram. */
    public function LCNCommissionReady(): bool
    {
        if ($this->ReadAttributeBoolean('LCNCommissionDone125') || !$this->LCNCommissionMappingOK() ||
            !$this->ReadPropertyBoolean('EnableControl') || !$this->IsOnline() ||
            $this->ReadAttributeBoolean('NeedsStop') || $this->ReadAttributeInteger('MovementStarted') > 0 ||
            GetValueBoolean(44228) || GetValueBoolean(56652) || !GetValueBoolean(55944) ||
            $this->ResolvedEnd() === null) return false;
        return true;
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
            'Garage' => $this->LCNHostVariable('Garage'),
            'Schiebe' => $this->LCNHostVariable('Schiebe')
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
        $moduleID = $this->LCNHostInstance($channel);
        if (!in_array($action, ['AUF', 'ZU', 'STOP', 'NONE'], true)) {
            $this->RecordLCN('SPERRE: ungültiger Fahrbefehl für ' . $description);
            return false;
        }
        if ($moduleID <= 0 || !IPS_InstanceExists($moduleID) ||
            !$this->LCNIsSourceOfModule($sourceID, $moduleID)) {
            $this->RecordLCN('SPERRE: Host-Kommando ' . $description .
                ' liegt nicht unter der gewählten Host-Kommando-Instanz');
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
                $this->WriteAttributeString('LCNLastDispatch', '{}');
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
        if (!$ok) {
            // Eine nicht ausgeführte Aktion darf einen erneuten Tastendruck
            // nach Behebung der Störung nicht künstlich blockieren.
            $this->WriteAttributeString('LCNLastDispatch', '{}');
        }
        $this->RecordLCN($channel . ' ' . $description . ' → ' . $action .
            ($ok ? ' gesendet' : ' NICHT AUSGEFÜHRT'));
        return $ok;
    }

    /**
     * Das sendende LCN M51 erscheint in Symcon als Kategorie, die
     * Host-Kommando-Instanz ist das tatsächlich auswählbare Kind.
     * Eine Quelle darf nur über ihren überprüften Elternpfad wirken.
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
                '. LCN-PRO-Ziel = M4 (VISU PCHK-ID 4 ist bestätigt). ' .
                'Symcon-Quelle: Host-Kommando #' . $this->LCNHostInstance($channel) .
                ', Befehl #' . $this->LCNHostVariable($channel) .
                '. Freigabe: ' . ($this->ReadPropertyBoolean('LCN' . $channel . 'Enabled') ? 'EIN' : 'AUS') . '.'
            ];
        }
        if ($this->InstanceID === 15957) {
            $form['elements'][] = ['type'=>'Label','caption'=>'Einmalige C2-Freigabe v1.2.5: ' .
                ($this->ReadAttributeBoolean('LCNCommissionDone125') ? 'abgeschlossen; manuelle Freigabe bleibt maßgeblich' :
                    ($this->LCNCommissionMappingOK() ? 'warte auf freigegebenes, erreichbares und in Endlage ruhendes Tor' : 'abweichende Zuordnung – keine automatische Freigabe'))];
        }
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
