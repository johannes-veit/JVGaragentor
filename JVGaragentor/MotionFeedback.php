<?php
declare(strict_types=1);

/** Actual direction from EM 172 Q1/Q2 via the dedicated, local Shelly i4.
 *  No commands to the i4; no dependency on the Shelly cloud or MQTT instance IDs.
 */
trait JVMotionFeedbackTrait
{
    private const MOTION_WARNING = 'Fahrtrichtung: ';
    private const KNOWN_MOTION_MAC_HASH = '52e7d7656a3b4e5e8824214e88413dd1d0ac5c6982a2734fdab74499c31dccb7';

    private function MotionCreate(): void
    {
        $this->RegisterPropertyString('MotionFeedbackHost', 'auto');
        $this->RegisterAttributeBoolean('MotionFeedbackOK', false);
        $this->RegisterAttributeInteger('MotionPendingUntil', 0);
    }

    private function MotionHost(): string
    {
        $host = trim($this->ReadPropertyString('MotionFeedbackHost'));
        if ($host === 'auto') {
            return $this->InstanceID === 15957 ? '192.168.103.93' : '';
        }
        return $host === 'off' ? '' : $host;
    }

    /** Bounded read of both inputs in one snapshot, never two separate requests. */
    protected function MotionHttpStatus(string $host): ?array
    {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !function_exists('curl_init')) return null;
        $curl = curl_init('http://' . $host . '/rpc/Shelly.GetStatus');
        if ($curl === false) return null;
        try {
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 180,
                CURLOPT_TIMEOUT_MS => 350,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            $body = curl_exec($curl);
            if (!is_string($body) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) return null;
            $doc = json_decode($body, true);
            return is_array($doc) ? $doc : null;
        } catch (Throwable $e) {
            return null;
        } finally {
            curl_close($curl);
        }
    }

    /** 0=standing, 1=opening, 3=closing, null=invalid/unavailable.
     *  Require actual booleans, not casts of null, strings or missing fields.
     */
    protected function MotionExpectedMacHash(): string
    {
        return self::KNOWN_MOTION_MAC_HASH;
    }

    private function MotionRead(): ?int
    {
        $doc = $this->MotionHttpStatus($this->MotionHost());
        if (!is_array($doc)) return null;
        if ($this->InstanceID === 15957 && $this->MotionHost() === '192.168.103.93') {
            $mac = $doc['sys']['mac'] ?? null;
            if (!is_string($mac) ||
                hash('sha256', strtoupper(str_replace(':', '', $mac))) !== $this->MotionExpectedMacHash()) return null;
        }
        $up = $doc['input:0'] ?? null;
        $down = $doc['input:1'] ?? null;
        if (!is_array($up) || !is_array($down) ||
            ($up['id'] ?? null) !== 0 || ($down['id'] ?? null) !== 1 ||
            !is_bool($up['state'] ?? null) || !is_bool($down['state'] ?? null) ||
            ($up['state'] && $down['state'])) return null;
        return $up['state'] ? 1 : ($down['state'] ? 3 : 0);
    }

    private function MotionClearWarning(): void
    {
        if (str_starts_with((string)$this->GetValue('Hinweis'), self::MOTION_WARNING)) {
            $this->SetWarning('');
        }
    }

    private function MotionMonitor(): void
    {
        $motion = $this->MotionRead();
        $open = $this->EndSignal('EndOpenVariable');
        $closed = $this->EndSignal('EndCloseVariable');
        if ($motion === null || !$this->IsOnline() || $open === null || $closed === null || ($open && $closed)) {
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
