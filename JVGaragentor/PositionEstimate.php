<?php
declare(strict_types=1);

/** Display-only linear time estimate, anchored at real end switches.
 * Closing percentage: 0 fully open, 100 fully closed. Never controls the motor.
 */
trait JVPositionEstimateTrait
{
    private function EstimateCreate(): void
    {
        $this->RegisterPropertyInteger('TravelOpenSeconds',39);
        $this->RegisterPropertyInteger('TravelCloseSeconds',40);
        $this->RegisterAttributeInteger('EstimateAnchor',-1); // 0..100000 = 0..100%
        $this->RegisterAttributeInteger('EstimateAnchorMs',0);
        $this->RegisterAttributeInteger('EstimateLastMs',0);
        $this->RegisterAttributeInteger('EstimateDirection',0);
        $this->RegisterAttributeInteger('EstimateRemaining',-1);
        $this->RegisterAttributeString('EstimateMapping','');
    }

    protected function EstimateNowMs(): int
    {
        return (int)floor(microtime(true)*1000);
    }

    private function EstimateTravelSeconds(int $direction): int
    {
        return min(300,max(5,$this->ReadPropertyInteger($direction===1?'TravelOpenSeconds':'TravelCloseSeconds')));
    }

    private function EstimateConfigure(): void
    {
        if ($this->RegisterVariableInteger('PositionPercent','Torposition (%) – Schließgrad',[],4)) {
            $this->SetValue('PositionPercent',-1);
        }
        $mapping=json_encode([$this->MotionEnabled(),$this->MotionIds(),
            $this->ReadPropertyInteger('OnlineVariable'),$this->EndVariableId('EndOpenVariable'),
            $this->EndVariableId('EndCloseVariable'),$this->EstimateTravelSeconds(1),
            $this->EstimateTravelSeconds(3)]) ?: '';
        if ($mapping!==$this->ReadAttributeString('EstimateMapping')) {
            $this->WriteAttributeString('EstimateMapping',$mapping);
            $this->EstimateInvalidate();
        }
    }

    private function EstimatePublish(int $percent,int $remaining): void
    {
        if ((int)$this->GetValue('PositionPercent')!==$percent) $this->SetValue('PositionPercent',$percent);
        if ($this->ReadAttributeInteger('EstimateRemaining')!==$remaining)
            $this->WriteAttributeInteger('EstimateRemaining',$remaining);
    }

    private function EstimateInvalidate(): void
    {
        $this->WriteAttributeInteger('EstimateAnchor',-1);
        $this->WriteAttributeInteger('EstimateAnchorMs',0);
        $this->WriteAttributeInteger('EstimateLastMs',0);
        $this->WriteAttributeInteger('EstimateDirection',0);
        $this->EstimatePublish(-1,-1);
    }

    private function EstimateSetAnchor(int $position,int $now,int $direction): void
    {
        $this->WriteAttributeInteger('EstimateAnchor',$position);
        $this->WriteAttributeInteger('EstimateAnchorMs',$now);
        $this->WriteAttributeInteger('EstimateDirection',$direction);
    }

    /** The estimate is integrated from one absolute time anchor, never from rounded percent. */
    private function EstimateSample(?int $motion,?bool $open,?bool $closed): void
    {
        if ($motion===null || $open===null || $closed===null || ($open&&$closed) || !$this->IsOnline()) {
            $this->EstimateInvalidate();
            return;
        }
        $now=$this->EstimateNowMs();
        $last=$this->ReadAttributeInteger('EstimateLastMs');
        // On restart, time adjustment or missed supervision, do not integrate an unknown gap.
        if ($last>0 && ($now<$last || $now-$last>5000)) $this->EstimateInvalidate();
        $this->WriteAttributeInteger('EstimateLastMs',$now);
        $old=$this->ReadAttributeInteger('EstimateDirection');
        $anchor=$this->ReadAttributeInteger('EstimateAnchor');
        $at=$this->ReadAttributeInteger('EstimateAnchorMs');
        $position=$anchor;
        if ($anchor>=0 && in_array($old,[1,3],true)) {
            $delta=(int)round(max(0,$now-$at)*100000/($this->EstimateTravelSeconds($old)*1000));
            $position=min(100000,max(0,$anchor+($old===1?-$delta:$delta)));
        }
        // Physical target end switches take precedence even if the drive relay
        // is still on for a short time. This is a new absolute calibration.
        if (($motion===0 && ($open||$closed)) || ($motion===1 && $open) || ($motion===3 && $closed)) {
            $position=$open?0:100000;
            $this->EstimateSetAnchor($position,$now,0);
            $this->EstimatePublish($open?0:100,-1);
            return;
        }
        // Start from a known departure end, also on startup while it is still active.
        if ($motion!==0 && $old!==$motion &&
            (($motion===1&&$closed)||($motion===3&&$open))) {
            $position=$motion===1?100000:0;
        }
        if ($motion!==$old || $anchor<0) $this->EstimateSetAnchor($position,$now,$motion);
        if ($position<0) {
            $this->EstimatePublish(-1,-1);
            return;
        }
        // Never announce either exact end point without its physical confirmation.
        $percent=$open?0:($closed?100:min(99,max(1,(int)round($position/1000))));
        $remaining=-1;
        if ($motion===1 || $motion===3) {
            $distance=$motion===1?$position:100000-$position;
            // Half a fixed-point unit can otherwise round an exact whole second up.
            $seconds=$this->EstimateTravelSeconds($motion);
            $remaining=max(0,(int)ceil(($distance-0.5)*$seconds/100000));
        }
        $this->EstimatePublish($percent,$remaining);
    }
}
