<?php
declare(strict_types=1);

/**
 * WLED 0.15.0-b7, two explicitly identified An-Penta-Plus.
 * Only reads original front presets. Creates two named, unused local slots per
 * controller (hold and off). Every lit state has a controller-local 5 s lease.
 * No real-world safety guarantee: requires functioning firmware and outputs.
 */
trait JVWledWatchdogTrait
{
    private const WD_DEVICES = ['front' => ['ip'=>'192.168.103.91','mac'=>'8c8c290df2ec'],
                                'rear' => ['ip'=>'192.168.103.92','mac'=>'007007c90d6c']];
    private const WD_OFF = 'JV Ampel Watchdog AUS';
    private const WD_HOLD = 'JV Ampel Watchdog HALTEN';
    private const WD_UDP = ['send'=>false,'sgrp'=>0,'rgrp'=>0,'nn'=>true];
    private const WD_FIELDS = ['on','bri','col','fx','sx','ix','pal','cct','c1','c2','c3','o1','o2','o3','frz','rev','mi','grp','spc','of'];

    /** Two HTTP requests concurrently; never wait seconds for an unreachable peer. */
    protected function WledHttp(array $requests): array
    {
        $result = array_fill_keys(array_keys($requests), null);
        if (!function_exists('curl_multi_init')) return $result;
        $mh = curl_multi_init(); $handles = [];
        try {
            foreach ($requests as $side=>$req) {
                $ch = curl_init('http://' . self::WD_DEVICES[$side]['ip'] . $req['path']);
                if ($ch === false) continue;
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>250,
                    CURLOPT_TIMEOUT_MS=>700, CURLOPT_NOSIGNAL=>true, CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_PROXY=>'', CURLOPT_HTTPHEADER=>['Content-Type: application/json','Cache-Control: no-cache']]);
                if (isset($req['body'])) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['body'], JSON_THROW_ON_ERROR));
                }
                $handles[$side]=$ch; curl_multi_add_handle($mh,$ch);
            }
            do {
                $code=curl_multi_exec($mh,$running);
                if ($running > 0 && $code === CURLM_OK) {
                    if (curl_multi_select($mh,0.05) === -1) usleep(1000);
                }
            } while ($running > 0 && $code === CURLM_OK);
            foreach ($handles as $side=>$ch) {
                if (curl_errno($ch)===0 && (int)curl_getinfo($ch,CURLINFO_HTTP_CODE)===200) {
                    $v=json_decode((string)curl_multi_getcontent($ch),true);
                    if (is_array($v)) $result[$side]=$v;
                }
            }
        } finally {
            foreach ($handles as $ch) { curl_multi_remove_handle($mh,$ch); curl_close($ch); }
            curl_multi_close($mh);
        }
        return $result;
    }

    private function WdBoth(string $path, ?array $body=null): array
    {
        $request=['path'=>$path]; if ($body!==null) $request['body']=$body;
        return $this->WledHttp(['front'=>$request,'rear'=>$request]);
    }

    private function WdIdentified(string $side, mixed $doc): bool
    {
        return is_array($doc) && ($doc['info']['ver']??'')==='0.15.0-b7' &&
            strtolower((string)($doc['info']['mac']??''))===self::WD_DEVICES[$side]['mac'] &&
            ($doc['info']['leds']['count']??0)===1 && is_array($doc['state']??null);
    }

    private function WdOffBody(): array
    {
        return ['on'=>false,'transition'=>0,'tt'=>0,'nl'=>['on'=>false],
            'udpn'=>self::WD_UDP,'playlist'=>['ps'=>[]],'np'=>false];
    }

    private function WdOff(array $docs, array $trusted=[]): void
    {
        $req=[];
        foreach (self::WD_DEVICES as $side=>$device) {
            // Never write to a different device that happens to use the same address.
            if ($this->WdIdentified($side,$docs[$side]??null) ||
                (($docs[$side]??null)===null && isset($trusted[$side]))) {
                $req[$side]=['path'=>'/json/state','body'=>$this->WdOffBody()];
            }
        }
        if ($req!==[]) $this->WledHttp($req);
        $this->WriteAttributeInteger('WatchdogLastSignal',-1);
    }

    private function WdSlot(array $presets,string $name,array $exclude=[]): int
    {
        foreach ($presets as $id=>$preset) {
            if (is_array($preset) && ($preset['n']??'')===$name &&
                (int)$id>=200 && (int)$id<=250 && !in_array((int)$id,$exclude,true)) return (int)$id;
        }
        for ($id=250;$id>=200;$id--) if (!array_key_exists($id,$presets) && !in_array($id,$exclude,true)) return $id;
        return 0;
    }

    private function WdSlotOK(mixed $p,string $name,bool $off): bool
    {
        if (!is_array($p) || ($p['n']??'')!==$name) return false;
        $allowed=$off ? ['n','on','transition','tt','nl','udpn','ql'] : ['n','udpn','ql'];
        if (array_diff(array_keys($p),$allowed)!==[]) return false;
        if ($off && (($p['on']??true)!==false || ($p['transition']??-1)!==0 ||
                     ($p['nl']['on']??true)!==false)) return false;
        return ($p['udpn']['send']??true)===false && ($p['udpn']['sgrp']??-1)===0 &&
            ($p['udpn']['rgrp']??-1)===0;
    }

    /** Validate front signal colors/effects; no API commands, nested playlists or unknown fields copied. */
    private function WdScene(array $preset,int $signal): ?array
    {
        if (isset($preset['win']) || isset($preset['playlist']) ||
            ($preset['on']??true)!==true || !is_array($preset['seg']??null)) return null;
        $segments=array_values(array_filter($preset['seg'],static fn($s)=>is_array($s)&&($s['stop']??1)>0));
        if (count($segments)!==1) return null;
        $seg=$segments[0];
        if (($seg['id']??0)!==0 || ($seg['on']??true)!==true || ($seg['frz']??false)!==false) return null;
        $color=$seg['col'][0]??null; $fx=$seg['fx']??null;
        if (!is_array($color) || count($color)<3 || !is_int($fx) || $fx<0) return null;
        [$r,$g,$b]=$color;
        if (!is_int($r)||!is_int($g)||!is_int($b)||min($r,$g,$b)<0||max($r,$g,$b)>255||($color[3]??0)!==0) return null;
        $isColor=match($signal) {
            1=>$r>0&&$g>0&&$b<min($r,$g)&&$r>=($g/2),
            2=>$g>0&&$g>$r&&$g>$b,
            3,4=>$r>0&&$r>$g&&$r>$b,
            default=>false
        };
        if (!$isColor || (in_array($signal,[1,3],true)?$fx===0:$fx!==0)) return null;
        if (!isset($seg['col'],$seg['fx'],$seg['sx'],$seg['ix'],$seg['pal'])) return null;
        $bodySeg=['id'=>0,'on'=>true,'frz'=>false];
        foreach (self::WD_FIELDS as $field) if (array_key_exists($field,$seg)) $bodySeg[$field]=$seg[$field];
        $bri=$preset['bri']??255;
        if (!is_int($bri)||$bri<=0||$bri>255) return null;
        return ['on'=>true,'bri'=>$bri,'seg'=>[$bodySeg]];
    }

    private function WdMatches(array $state,array $scene): bool
    {
        if (($state['on']??false)!==true || ($state['bri']??-1)!==$scene['bri'] ||
            ($state['nl']['on']??true)!==false || ($state['pl']??-1)<0 ||
            ($state['udpn']['send']??true)!==false || ($state['udpn']['rgrp']??-1)!==0 ||
            ($state['udpn']['sgrp']??-1)!==0) return false;
        $actual=$state['seg'][0]??null;
        if (!is_array($actual)||count($state['seg'])!==1) return false;
        foreach ($scene['seg'][0] as $k=>$v) {
            if (!array_key_exists($k,$actual)||$actual[$k]!==$v) return false;
        }
        return true;
    }

    private function WdPrepare(array $docs): ?array
    {
        $presets=$this->WdBoth('/presets.json');
        $setup=[];
        foreach (self::WD_DEVICES as $side=>$device) {
            if (!$this->WdIdentified($side,$docs[$side]??null)||!is_array($presets[$side]??null)) return null;
            $pp=$presets[$side];
            $off=$this->WdSlot($pp,self::WD_OFF);
            $hold=$this->WdSlot($pp,self::WD_HOLD,[$off]);
            if ($off===0||$hold===0) return null;
            // A slot with our name but different contents is not overwritten.
            if (isset($pp[$off])&&!$this->WdSlotOK($pp[$off],self::WD_OFF,true)) return null;
            if (isset($pp[$hold])&&!$this->WdSlotOK($pp[$hold],self::WD_HOLD,false)) return null;
            $setup[$side]=['off'=>$off,'hold'=>$hold,'mac'=>$device['mac']];
        }
        $scenes=[];
        for($i=1;$i<=4;$i++){
            $scene=$this->WdScene($presets['front'][$i]??[],$i);
            if ($scene===null) return null;
            $scenes[$i]=$scene;
        }
        // Provision only on an identified device, while dark. Existing color presets untouched.
        $req=[];
        foreach ($setup as $side=>$s) {
            if (!isset($presets[$side][$s['off']])||($docs[$side]['info']['leds']['bootps']??-1)!==$s['off']) {
                $req[$side]=['path'=>'/json/state','body'=>['psave'=>$s['off'],'n'=>self::WD_OFF,'o'=>true,
                    'bootps'=>$s['off'],'on'=>false,'transition'=>0,'tt'=>0,'nl'=>['on'=>false],'udpn'=>self::WD_UDP]];
            }
        }
        if ($req!==[]) {
            $res=$this->WledHttp($req);
            foreach ($req as $side=>$unused) if (($res[$side]['success']??false)!==true) return null;
        }
        $req=[];
        foreach ($setup as $side=>$s) if (!isset($presets[$side][$s['hold']])) {
            $req[$side]=['path'=>'/json/state','body'=>['psave'=>$s['hold'],'n'=>self::WD_HOLD,'o'=>true,'udpn'=>self::WD_UDP]];
        }
        if ($req!==[]) {
            $res=$this->WledHttp($req);
            foreach ($req as $side=>$unused) if (($res[$side]['success']??false)!==true) return null;
        }
        $read=$this->WdBoth('/presets.json');
        $current=$this->WdBoth('/json/si');
        foreach ($setup as $side=>&$s) {
            if (!$this->WdIdentified($side,$current[$side]??null)||
                !$this->WdSlotOK($read[$side][$s['off']]??null,self::WD_OFF,true)||
                !$this->WdSlotOK($read[$side][$s['hold']]??null,self::WD_HOLD,false)||
                ($current[$side]['info']['leds']['bootps']??-1)!==$s['off']) return null;
            $s['pmt']=$current[$side]['info']['fs']['pmt']??null;
        }
        unset($s);
        $setup['scenes']=$scenes;
        return $setup;
    }

    /** A local playlist starts immediately, holds without resetting the effect, then applies OFF. */
    private function WdLease(array $s): array
    {
        return ['udpn'=>self::WD_UDP,'nl'=>['on'=>false],
            'playlist'=>['ps'=>[$s['hold']],'dur'=>[50],'transition'=>[0],'repeat'=>1,'end'=>$s['off'],'r'=>false],
            'np'=>true];
    }

    private function WdCurrent(int $source,int $raw): bool
    {
        $online=$this->ReadPropertyInteger('GateOnlineVariable');
        return $this->IsVariable($source,VARIABLETYPE_INTEGER)&&GetValueInteger($source)===$raw&&
            $this->IsVariable($online,VARIABLETYPE_BOOLEAN)&&GetValueBoolean($online);
    }

    private function SyncWatchdog(int $source,int $raw,int $signal,string $note): void
    {
        $lock='JVWledWatchdog'.$this->InstanceID;
        $useLock=function_exists('IPS_SemaphoreEnter')&&function_exists('IPS_SemaphoreLeave');
        if ($useLock&&!IPS_SemaphoreEnter($lock,0)) { $this->SetTimerInterval('Synchronize',250); return; }
        try {
            $setup=json_decode($this->ReadAttributeString('WatchdogSetup'),true);
            if (!is_array($setup)) $setup=[];
            $last=$this->ReadAttributeInteger('WatchdogLastSignal');
            $this->SetTimerInterval('Synchronize',2000);
            // Direct signal change: immediately send the scene and lease, in parallel.
            // No pre-flight HTTP poll if identities, slots and scenes were already verified.
            if ($signal>0&&$signal!==$last&&isset($setup['scenes'][$signal],$setup['front'],$setup['rear'])&&$this->WdCurrent($source,$raw)) {
                $req=[];
                foreach (self::WD_DEVICES as $side=>$device) {
                    $req[$side]=['path'=>'/json/state','body'=>$setup['scenes'][$signal]+['transition'=>0,'tt'=>0]+$this->WdLease($setup[$side])];
                }
                $res=$this->WledHttp($req);
                if (($res['front']['success']??false)!==true||($res['rear']['success']??false)!==true||!$this->WdCurrent($source,$raw)) {
                    $this->WdOff([], $setup);
                    $this->WriteAttributeString('WatchdogSetup','');
                    $this->SetStatusText($note.'Signal '.$signal.' – Start nicht beidseitig bestätigt; AUS angefordert');
                    return;
                }
                $this->WriteAttributeInteger('WatchdogLastSignal',$signal);
                $this->WriteAttributeInteger('WatchdogLastSent',time());
                $this->SetStatusText($note.'Signal '.$signal.' – vorne/hinten gleichzeitig angefordert, Prüfung folgt');
                $this->SetTimerInterval('Synchronize',500);
                return;
            }
            $docs=$this->WdBoth('/json/si');
            if (!$this->WdIdentified('front',$docs['front']??null)||!$this->WdIdentified('rear',$docs['rear']??null)) {
                $this->WdOff($docs,$setup);
                $this->WriteAttributeString('WatchdogSetup','');
                $this->SetStatusText($note.'Signal '.$signal.' – Verbindung/Identität gestört: vorne '.
                    ($this->WdIdentified('front',$docs['front']??null)?'erreichbar':'unbekannt').' / hinten '.
                    ($this->WdIdentified('rear',$docs['rear']??null)?'erreichbar':'unbekannt').' – AUS angefordert');
                return;
            }
            $changed=false;
            foreach (self::WD_DEVICES as $side=>$device) {
                if (!isset($setup[$side])||($setup[$side]['pmt']??null)!==($docs[$side]['info']['fs']['pmt']??null)||
                    ($docs[$side]['info']['leds']['bootps']??-1)!==($setup[$side]['off']??0)) $changed=true;
            }
            if ($changed||!isset($setup['scenes'])) {
                $this->WdOff($docs);
                $new=$this->WdPrepare($docs);
                if ($new===null) {
                    $this->WriteAttributeString('WatchdogSetup','');
                    $this->SetStatusText('Ampel AUS – Frontpresets, freie Watchdog-Slots oder Boot-AUS konnten nicht bestätigt werden.');
                    return;
                }
                $this->WriteAttributeString('WatchdogSetup',json_encode($new,JSON_THROW_ON_ERROR));
                $this->SetStatusText('Watchdog vorbereitet; beide Controller geprüft, AUS.');
                $this->SetTimerInterval('Synchronize',250);
                return;
            }
            if ($signal===0||!$this->WdCurrent($source,$raw)) {
                $off=true;
                foreach ($docs as $doc) {
                    $s=$doc['state'];
                    if (($s['on']??true)!==false||($s['pl']??0)>=0||($s['udpn']['rgrp']??-1)!==0||
                        ($s['udpn']['sgrp']??-1)!==0||($s['udpn']['send']??true)!==false) $off=false;
                }
                if (!$off||$last!==0) $this->WdOff($docs);
                $this->WriteAttributeInteger('WatchdogLastSignal',0);
                $this->SetStatusText($note.($off?'Ampel AUS – vorne und hinten bestätigt':'Ampel AUS – Abschaltung angefordert'));
                $this->SetTimerInterval('Synchronize',$off?10000:500);
                return;
            }
            $scene=$setup['scenes'][$signal]??null;
            if (!is_array($scene)) { $this->WdOff($docs); $this->SetStatusText('Ampel AUS – Szene fehlt'); return; }
            $front=$this->WdMatches($docs['front']['state'],$scene);
            $rear=$this->WdMatches($docs['rear']['state'],$scene);
            if (!$front||!$rear) {
                // No renewal of a divergent display. Switch both off; next tick may start fresh.
                $this->WdOff($docs);
                $this->SetStatusText('Signal '.$signal.' – Synchronisationsfehler: vorne '.($front?'OK':'abweichend').
                    ' / hinten '.($rear?'OK':'abweichend').' – beide AUS angefordert');
                return;
            }
            if (time()-$this->ReadAttributeInteger('WatchdogLastSent')>=1) {
                $req=[];
                foreach (self::WD_DEVICES as $side=>$device) $req[$side]=['path'=>'/json/state','body'=>$this->WdLease($setup[$side])];
                if (!$this->WdCurrent($source,$raw)) { $this->WdOff($docs); return; }
                $res=$this->WledHttp($req);
                if (($res['front']['success']??false)!==true||($res['rear']['success']??false)!==true) {
                    $this->WdOff($docs);
                    $this->SetStatusText('Signal '.$signal.' – Watchdog-Erneuerung fehlgeschlagen; AUS angefordert');
                    return;
                }
                $this->WriteAttributeInteger('WatchdogLastSent',time());
            }
            $this->SetStatusText('Signal '.$signal.' – vorne/hinten synchron (Farbe, Effekt, Helligkeit, Watchdog)');
        } catch (Throwable $e) {
            $this->WdOff([],is_array($setup??null)?$setup:[]);
            $this->WriteAttributeString('WatchdogSetup','');
            $this->SetStatusText('Ampel nicht bestätigt – '.$e->getMessage());
        } finally {
            if ($useLock) IPS_SemaphoreLeave($lock);
        }
    }
}
