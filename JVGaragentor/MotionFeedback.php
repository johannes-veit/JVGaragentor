<?php
declare(strict_types=1);

/** EM 172 direction via existing IP-Symcon ShellyDevice variables.
 * No HTTP, MQTT client or direct connection to the i4 in this module.
 */
trait JVMotionFeedbackTrait
{
    private const MOTION_WARNING = 'Fahrtrichtung: ';
    private const MOTION_OPEN_ID = 36019;
    private const MOTION_CLOSE_ID = 41452;
    private const MOTION_ONLINE_ID = 53400;
    private const MOTION_INSTANCE = 14386;

    private function MotionCreate(): void
    {
        $this->RegisterPropertyBoolean('MotionFeedbackEnabled', $this->InstanceID === 15957);
        $this->RegisterPropertyInteger('MotionOpenVariable', self::MOTION_OPEN_ID);
        $this->RegisterPropertyInteger('MotionCloseVariable', self::MOTION_CLOSE_ID);
        $this->RegisterPropertyInteger('MotionOnlineVariable', self::MOTION_ONLINE_ID);
        $this->RegisterPropertyBoolean('MotionLCNShortStop', true);
        $this->RegisterAttributeBoolean('MotionFeedbackOK', false);
        $this->RegisterAttributeInteger('MotionPendingUntil', 0);
        $this->RegisterAttributeString('MotionSubscriptions', '[]');
        $this->RegisterAttributeString('MotionMapping', '');
        $this->RegisterAttributeInteger('MotionZeroSinceMs', 0);
        $this->RegisterTimer('MotionSettle', 0, 'JVG_Monitor($_IPS["TARGET"]);');
    }

    private function MotionEnabled(): bool
    {
        return $this->ReadPropertyBoolean('MotionFeedbackEnabled');
    }

    private function MotionIds(): array
    {
        return [$this->ReadPropertyInteger('MotionOpenVariable'),
            $this->ReadPropertyInteger('MotionCloseVariable'),
            $this->ReadPropertyInteger('MotionOnlineVariable')];
    }

    private function MotionMappingValid(): bool
    {
        $ids = $this->MotionIds();
        if (count(array_unique($ids)) !== 3) return false;
        $forbidden = array_merge(array_values($this->RelayIds()), [
            $this->EndVariableId('EndOpenVariable'), $this->EndVariableId('EndCloseVariable'),
            $this->ReadPropertyInteger('OnlineVariable')
        ]);
        foreach ($ids as $id) {
            if (!$this->ValidVariable($id, VARIABLETYPE_BOOLEAN) || in_array($id, $forbidden, true)) return false;
        }
        $parent = IPS_GetParent($ids[0]);
        if ($parent <= 0 || !IPS_InstanceExists($parent) ||
            IPS_GetParent($ids[1]) !== $parent || IPS_GetParent($ids[2]) !== $parent) return false;
        $known = [self::MOTION_OPEN_ID, self::MOTION_CLOSE_ID, self::MOTION_ONLINE_ID];
        // Known IDs must not be interchanged. A completely different ShellyDevice
        // can be selected explicitly, with all three variables under one instance.
        if (array_intersect($ids, $known) !== [] &&
            ($ids !== $known || $parent !== self::MOTION_INSTANCE)) return false;
        return true;
    }

    private function MotionApplyChanges(): void
    {
        $old = json_decode($this->ReadAttributeString('MotionSubscriptions'), true);
        $old = is_array($old) ? array_map('intval', $old) : [];
        $wanted = [];
        if ($this->MotionEnabled()) {
            foreach (array_merge($this->MotionIds(), [
                $this->EndVariableId('EndOpenVariable'), $this->EndVariableId('EndCloseVariable'),
                $this->ReadPropertyInteger('OnlineVariable')
            ]) as $id) {
                if ($this->ValidVariable($id, VARIABLETYPE_BOOLEAN)) $wanted[] = $id;
            }
        }
        $wanted = array_values(array_unique($wanted));
        foreach (array_unique(array_merge($old, $wanted)) as $id) {
            $this->UnregisterMessage($id, VM_UPDATE);
        }
        foreach ($wanted as $id) $this->RegisterMessage($id, VM_UPDATE);
        $this->WriteAttributeString('MotionSubscriptions', json_encode($wanted) ?: '[]');
        $this->MotionClearZero();
        $mapping = json_encode([$this->MotionEnabled(), $this->MotionIds()]) ?: '';
        if ($mapping !== $this->ReadAttributeString('MotionMapping')) {
            $this->WriteAttributeString('MotionMapping', $mapping);
            $this->WriteAttributeBoolean('StateInitialized', false);
            $this->WriteAttributeBoolean('MotionFeedbackOK', false);
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->WriteAttributeInteger('MotionPendingUntil', 0);
        }
    }

    private function MotionMessage(int $sender): bool
    {
        if (!$this->MotionEnabled()) return false;
        $ids = json_decode($this->ReadAttributeString('MotionSubscriptions'), true);
        if (!is_array($ids) || !in_array($sender, $ids, true)) return false;
        // Read actual current Symcon values, not the possibly superseded event payload.
        $this->MotionMonitor();
        return true;
    }

    /** 0=standing, 1=opening, 3=closing, null=invalid/offline. */
    private function MotionRead(): ?int
    {
        if (!$this->MotionMappingValid()) return null;
        [$upID, $downID, $onlineID] = $this->MotionIds();
        if (!GetValueBoolean($onlineID)) return null;
        $up = GetValueBoolean($upID);
        $down = GetValueBoolean($downID);
        if ($up && $down) return null;
        return $up ? 1 : ($down ? 3 : 0);
    }

    private function MotionClearWarning(): void
    {
        if (str_starts_with((string)$this->GetValue('Hinweis'), self::MOTION_WARNING)) {
            $this->SetWarning('');
        }
    }

    private function MotionClearZero(): void
    {
        if ($this->ReadAttributeInteger('MotionZeroSinceMs')!==0) {
            $this->WriteAttributeInteger('MotionZeroSinceMs',0);
        }
        $this->SetTimerInterval('MotionSettle',0);
    }

    private function MotionMonitor(): void
    {
        $motion = $this->MotionRead();
        $open = $this->EndSignal('EndOpenVariable');
        $closed = $this->EndSignal('EndCloseVariable');
        if ($motion === null || !$this->IsOnline() || $open === null || $closed === null || ($open && $closed)) {
            $this->MotionClearZero();
            $this->WriteAttributeBoolean('MotionFeedbackOK', false);
            $this->WriteAttributeBoolean('StateInitialized', false);
            $this->WriteAttributeInteger('MovementStarted', 0);
            $this->SetWarning(self::MOTION_WARNING . 'Rückmeldung ungültig/offline oder Endlagen widersprüchlich. Stillstand NICHT bestätigt – nur STOP möglich.');
            $this->UpdateGateState(self::STOPPED, 0);
            return;
        }

        $this->WriteAttributeBoolean('MotionFeedbackOK', true);
        $initial = !$this->ReadAttributeBoolean('StateInitialized');
        $this->WriteAttributeBoolean('StateInitialized', true);
        $previous = (string)$this->GetValue('Position');
        if ($motion !== 0) {
            if ($this->ReadAttributeInteger('MotionZeroSinceMs')>0) $this->SendDebug('Fahrtrichtung','Kurzer Aussetzer der Fahrmeldung beendet',0);
            $this->MotionClearZero();
            $position = $motion === 1 ? self::OPENING : self::CLOSING;
            $this->WriteAttributeInteger('MotionPendingUntil', 0);
            if ($previous !== $position || $this->ReadAttributeInteger('MovementStarted') <= 0) {
                $this->WriteAttributeInteger('MovementStarted', time());
            }
            // Actual motion remains displayed even after the time limit.
            // Never fake a stop or send an automatic motion command.
            if (time() - $this->ReadAttributeInteger('MovementStarted') >= $this->MovementTimeout()) {
                $this->WriteAttributeBoolean('NeedsStop', true);
                $this->SetWarning(self::MOTION_WARNING . 'Laufzeitgrenze erreicht; Bewegung weiterhin bestätigt. STOP möglich.');
            } else {
                $this->MotionClearWarning();
            }
            $this->UpdateGateState($position, $motion);
            return;
        }

        // Only a brief all-off transition between active motion and intermediate stop
        // is filtered. Valid target end, offline or conflicting inputs are immediate.
        // During the confirmation window no new driving command is allowed.
        $targetReached=($previous===self::OPENING && $open) || ($previous===self::CLOSING && $closed);
        if (!$initial && !$targetReached && in_array($previous,[self::OPENING,self::CLOSING],true)) {
            $now=(int)floor(microtime(true)*1000);
            $since=$this->ReadAttributeInteger('MotionZeroSinceMs');
            if ($since<=0 || $now<$since) {
                $since=$now;
                $this->WriteAttributeInteger('MotionZeroSinceMs',$since);
            }
            if ($now-$since<200) {
                $this->WriteAttributeBoolean('MotionFeedbackOK',false);
                $this->SetTimerInterval('MotionSettle',200);
                $this->Broadcast();
                return;
            }
        }
        $this->MotionClearZero();
        $this->WriteAttributeInteger('MovementStarted', 0);
        // Here actual standstill is confirmed, unlike the old time-only model.
        $this->WriteAttributeBoolean('NeedsStop', false);
        $pending = $this->ReadAttributeInteger('MotionPendingUntil');
        $pendingExpired = $pending > 0 && time() >= $pending;
        if ($pendingExpired) {
            $this->WriteAttributeInteger('MotionPendingUntil', 0);
        }
        $end = $open ? 'AUF' : ($closed ? 'ZU' : null);
        if ($initial) {
            $this->MotionClearWarning();
            $this->UpdateGateState($end ?? self::STOPPED, 0);
        } elseif ($end === null) {
            $this->MotionClearWarning();
            $this->UpdateGateState(self::STOPPED, 0);
        } elseif ($previous !== $end) {
            $this->Arrived($end);
        } else {
            if ((int)$this->GetValue('Signal') !== 0 &&
                time() >= $this->ReadAttributeInteger('SignalUntil')) {
                $this->UpdateGateState($end, 0);
            }
            $this->MotionClearWarning();
            $this->Broadcast();
        }
        if ($pendingExpired) {
            $this->SetWarning(self::MOTION_WARNING . 'Fahrbefehl ohne bestätigte Fahrt; kein automatischer Wiederholungsimpuls.');
        }
    }
}
