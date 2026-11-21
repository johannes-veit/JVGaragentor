<?php
declare(strict_types=1);
error_reporting(E_ALL);
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_STRING = 3;
const VM_UPDATE = 10603;
$GLOBALS['simVars'] = [];
$GLOBALS['simActions'] = [];
$GLOBALS['simNoAction'] = [];
function mkVar(int $id, int $type, mixed $value, bool $action = true): void {
    $GLOBALS['simVars'][$id] = ['type'=>$type,'value'=>$value,'action'=>$action];
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['simVars'][$id]); }
function IPS_GetVariable(int $id): array {
    $v=$GLOBALS['simVars'][$id];
    return ['VariableType'=>$v['type'],'VariableAction'=>$v['action']?999:0,'VariableCustomAction'=>0];
}
function IPS_GetKernelRunlevel(): int { return 10103; }
function GetValueBoolean(int $id): bool { return (bool)$GLOBALS['simVars'][$id]['value']; }
function GetValueInteger(int $id): int { return (int)$GLOBALS['simVars'][$id]['value']; }
function GetValueString(int $id): string { return (string)$GLOBALS['simVars'][$id]['value']; }
function RequestAction(int $id, mixed $value): bool {
    if (!IPS_VariableExists($id) || !($GLOBALS['simVars'][$id]['action'] ?? false)) return false;
    $GLOBALS['simActions'][]=[$id,$value];
    $GLOBALS['simVars'][$id]['value']=$value;
    if ($id===46199 && in_array($value,[1,2,3,4],true)) $GLOBALS['simVars'][29605]['value']=true; // WLED-Presets enthalten on:true.
    return true;
}
class IPSModuleStrict {
    public int $InstanceID=1234;
    public array $props=[],$attrs=[],$values=[],$messages=[],$sent=[];
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function RegisterPropertyBoolean(string $name,bool $value): void {$this->props[$name]=$value;}
    public function RegisterPropertyInteger(string $name,int $value): void {$this->props[$name]=$value;}
    public function ReadPropertyBoolean(string $name): bool {return (bool)$this->props[$name];}
    public function ReadPropertyInteger(string $name): int {return (int)$this->props[$name];}
    public function RegisterAttributeInteger(string $name,int $value): void {$this->attrs[$name]=$value;}
    public function RegisterAttributeBoolean(string $name,bool $value): void {$this->attrs[$name]=$value;}
    public function RegisterAttributeString(string $name,string $value): void {$this->attrs[$name]=$value;}
    public function WriteAttributeInteger(string $name,int $value): void {$this->attrs[$name]=$value;}
    public function WriteAttributeBoolean(string $name,bool $value): void {$this->attrs[$name]=$value;}
    public function WriteAttributeString(string $name,string $value): void {$this->attrs[$name]=$value;}
    public function ReadAttributeInteger(string $name): int {return (int)$this->attrs[$name];}
    public function ReadAttributeBoolean(string $name): bool {return (bool)$this->attrs[$name];}
    public function ReadAttributeString(string $name): string {return (string)$this->attrs[$name];}
    public function RegisterVariableString(string $ident,string $name,array $presentation,int $pos): bool {
       $new=!array_key_exists($ident,$this->values);
       if($new)$this->values[$ident]='';
       return $new;
    }
    public function RegisterVariableInteger(string $ident,string $name,array $presentation,int $pos): bool {
        $new=!array_key_exists($ident,$this->values);
        if($new)$this->values[$ident]=0;
        return $new;
    }
    public function GetValue(string $name): mixed {return $this->values[$name];}
    public function SetValue(string $name,mixed $v): void {$this->values[$name]=$v;}
    public function RegisterTimer(string $name,int $time,string $callback): void {}
    public function SetTimerInterval(string $name,int $time): void {}
    public function SetVisualizationType(int $type): void {}
    public function UpdateVisualizationValue(string $value): bool {$this->sent[]=$value; return true;}
    public function SendDebug(string $name,string $data,int $fmt): void {}
    public function RegisterMessage(int $id,int $msg): void {$this->messages[$id]=$msg;}
    public function UnregisterMessage(int $id,int $msg): void {unset($this->messages[$id]);}
}
require __DIR__.'/../JVGaragentor/module.php';
require __DIR__.'/../JVAmpel/module.php';
$cnt=0;
function check(bool $ok,string $what): void {global $cnt;$cnt++;if(!$ok)throw new RuntimeException("FAIL $cnt: $what");}
function resetHardware(): void {
    $GLOBALS['simVars']=[];$GLOBALS['simActions']=[];
    mkVar(44228,0,false);mkVar(56652,0,false);mkVar(55944,0,true);mkVar(17585,0,true);
    mkVar(80001,0,false,false);mkVar(80002,0,true,false); // Start in Endlage ZU
    mkVar(46199,1,4);mkVar(29605,0,true);
}
resetHardware();
$gate=new JVGaragentor();$gate->Create();$gate->props['EndOpenVariable']=80001;$gate->props['EndCloseVariable']=80002;$gate->ApplyChanges();
check($gate->values['Position']==='ZU','Initiale Endlage aus Sensor erkannt');
check($gate->values['Signal']===0,'Beim Start keine Schein-Nachleuchtzeit');
check($gate->attrs['SignalUntil']===0, 'Vorhandene Endlage beim Start bleibt neutral');
check($gate->props['MovementTimeoutSeconds']===120,'Maximaldauer 120 Sekunden');
check($gate->props['OpenHoldSeconds']===10 && $gate->props['CloseHoldSeconds']===5,'Einstellbare Nachleuchtzeiten 10/5 s');
check(!$gate->Command('AUF'),'Torsteuerung im Auslieferungszustand gesperrt');
check(count($GLOBALS['simActions'])===0,'Testmodus sendet nichts');
$gate->props['EnableControl']=true;
check($gate->Command('AUF'),'AUF senden');
check($gate->values['Position']==='LÄUFT AUF','Fahrzustand gesetzt');
check($gate->values['Signal']===1,'Gelbes Blinksignal bei AUF-Fahrt');
check($gate->attrs['SignalUntil']===0,'Bei Fahrt kein Nachleuchten aktiv');
check($GLOBALS['simActions'][0]===[44228,true],'Richtiges Relais O1');
check($gate->Command('AUF'),'doppelter AUF als Noop');
check(count($GLOBALS['simActions'])===1,'Keine doppelten Impulse');
check(!$gate->Command('ZU'),'Richtungswechsel ohne STOP gesperrt');
check(count($GLOBALS['simActions'])===1,'Sperre sendet nichts');
$GLOBALS['simVars'][44228]['value']=false; // Shelly Auto-OFF
$GLOBALS['simVars'][80002]['value']=false;
$GLOBALS['simVars'][80001]['value']=true;
$gate->Monitor();
check($gate->values['Position']==='AUF','Obere Endlage löst AUF aus');
check($gate->values['Signal']===2,'Obere Endlage -> Grün');
check($gate->attrs['SignalUntil']>=time()+9,'Grün leuchtet 10 Sekunden');
check($gate->Command('ZU'),'ZU senden');
check($gate->values['Signal']===3,'Rotes Blinksignal bei ZU-Fahrt');
check(end($GLOBALS['simActions'])===[56652,true],'O2 Impuls');
$GLOBALS['simVars'][56652]['value']=false;
check($gate->Command('STOP'),'STOP senden');
check(end($GLOBALS['simActions'])===[55944,false],'O3 Ausschalten als Stop');
check($gate->values['Position']==='AUF','STOP bei immer noch meldender Endlage bleibt AUF');
check($gate->values['Signal']===0,'STOP schaltet Anzeige sofort AUS, auch an Endlage');
$GLOBALS['simVars'][55944]['value']=true; // Shelly Auto-ON
$GLOBALS['simVars'][80001]['value']=false;
check($gate->Command('ZU'),'erneutes ZU nach Stop möglich');
$GLOBALS['simVars'][56652]['value']=false;
$gate->attrs['MovementStarted']=time()-200;
$gate->Monitor();
check($gate->values['Position']==='GESTOPPT','Laufzeitüberschreitung ohne Endlage');
check($gate->values['Signal']===0,'Timeout schaltet Ampelsignal AUS');
check($gate->values['Hinweis']!== '', 'Zeitüberschreitung dokumentiert');
check(!$gate->Command('AUF'),'Nach Timeout muss explizit STOP erfolgen');
check($gate->Command('STOP'),'STOP aus Zwischenposition');
$GLOBALS['simVars'][55944]['value']=true;
check($gate->values['Position']==='GESTOPPT','Zwischenposition bleibt GESTOPPT');
$GLOBALS['simVars'][80001]['value']=true;
$GLOBALS['simVars'][80002]['value']=true;
$gate->Monitor();
check($gate->values['Position']==='GESTOPPT','widersprüchliche Endlagen werden nicht bestätigt');
check(!$gate->Command('ZU'),'Torbewegung bei widersprüchlichen Endlagen gesperrt');
$GLOBALS['simVars'][80001]['value']=false;
$GLOBALS['simVars'][80002]['value']=true;
$gate->Monitor();
check($gate->values['Position']==='ZU','Endlage ZU nach Stopp erkannt');
check($gate->values['Signal']===4,'Endlage ZU zeigt rotes Nachleuchten nach Zwischenposition');
check($gate->attrs['SignalUntil']>=time()+4, 'Rot leuchtet fünf Sekunden nach tatsächlichem ZU-Ereignis');
$GLOBALS['simVars'][17585]['value']=false;
check(!$gate->Command('AUF'),'Offline-Sperre');
$GLOBALS['simVars'][17585]['value']=true;
$gate->props['EndOpenVariable']=0;
check(!$gate->Command('AUF'),'Sensorzuordnung fehlt - Sperre');
$gate->props['EndOpenVariable']=80001;
$gate->props['RelayOpenVariable']=56652;
check(!$gate->Command('AUF'),'gleiches Relais doppelt zugeordnet');
$gate->props['RelayOpenVariable']=44228;
$gate->props['EnableControl']=false;
check(!$gate->Command('ZU'),'Reset Testmodus verhindert Befehl');
$html=$gate->GetVisualizationTile();
check(str_contains($html,'data-jvg-command="AUF"')&&str_contains($html,'GESTOPPT'),'HTML-Kachel erzeugt');
check(!str_contains($html,'__JV_INITIAL__'),'HTML-Initialzustand eingefügt');
check(str_contains($html,'jvg-yellow-flash') && str_contains($html,'jvg-red-flash'), 'Statusfeld hat sanfte Blinkstile');
check(str_contains($html,'jvg-green') && str_contains($html,'jvg-red'), 'Statusfeld hat beide Nachleuchtfarben');
check(str_contains($html,'effectUntil') && str_contains($html, 'renderSignal'), 'Kachel kennt Zeitgrenze und lokales Ausblenden');
check(str_contains($html, 'blinkPeriodMs'), 'Kachel übernimmt konfigurierbare Blinkdauer');
check(str_contains($html,'"enabled":false'),'HTML-Kachel gesperrt');
check(str_contains($html, "b.dataset.jvgCommand === 'STOP'"), 'STOP-Taste bleibt bei Timeout-Sperre getrennt bedienbar');
// Nachleuchten endet, ohne den Positions-Text zu verändern.
$gate->props['EnableControl']=true;
$GLOBALS['simVars'][80001]['value']=true;
$GLOBALS['simVars'][80002]['value']=false;
$gate->Monitor();
check($gate->values['Position']==='AUF', 'Teststart für grüne Nachleuchtzeit');
$gate->attrs['SignalUntil']=time()-1;
$gate->Monitor();
check($gate->values['Position']==='AUF' && $gate->values['Signal']===0, 'Grün endet, AUF bleibt');
$GLOBALS['simVars'][80001]['value']=false;
$GLOBALS['simVars'][80002]['value']=true;
$gate->Monitor();
check($gate->values['Signal']===4, 'Neue Endlage ZU startet rotes Nachleuchten');
$gate->attrs['SignalUntil']=time()-1;
$gate->Monitor();
check($gate->values['Position']==='ZU' && $gate->values['Signal']===0, 'Rot endet, ZU bleibt');

// Konfigurationsänderung beeinflusst neue Endlagenereignisse.
$gate->props['OpenHoldSeconds']=3;
$GLOBALS['simVars'][80002]['value']=false;
$GLOBALS['simVars'][80001]['value']=true;
$gate->Monitor();
check($gate->values['Signal']===2 && $gate->attrs['SignalUntil']>=time()+2, 'Grün editierbar auf drei Sekunden');
$gate->props['CloseHoldSeconds']=0;
$GLOBALS['simVars'][80001]['value']=false;
$GLOBALS['simVars'][80002]['value']=true;
$gate->Monitor();
check($gate->values['Signal']===0 && $gate->values['Position']==='ZU','Rote Nachleuchtzeit darf 0 s sein');

// Die 120-s-Grenze gilt unabhängig davon, ob jemand größere Werte konfiguriert.
$gate->props['MovementTimeoutSeconds']=999;
$GLOBALS['simVars'][80002]['value']=false;
check($gate->Command('AUF'),'Fahrt bei maximalem Timeout 120 s');
$GLOBALS['simVars'][44228]['value']=false;
$gate->attrs['MovementStarted']=time()-121;
$gate->Monitor();
check($gate->values['Position']==='GESTOPPT' && $gate->values['Signal']===0, 'Auch bei 999-s-Einstellung nach 120 s Timeout');
check(!$gate->Command('ZU'), 'Timeout sperrt Weiterfahrt');
check($gate->Command('STOP'), 'STOP bei Timeout dennoch möglich');
$GLOBALS['simVars'][55944]['value']=true;
$gate->props['MovementTimeoutSeconds']=8;
check($gate->Command('ZU'),'Kürzerer Timeout kann eingestellt werden');
$GLOBALS['simVars'][56652]['value']=false;
$gate->attrs['MovementStarted']=time()-9;
$gate->Monitor();
check($gate->values['Position']==='GESTOPPT' && $gate->values['Signal']===0, '8-s-Timeout wirkt vor der globalen 120-s-Grenze');

$ampel=new JVAmpel();$ampel->Create();$ampel->ApplyChanges();
check($ampel->values['AmpelStatus']==='Automatik AUS','Ampel standardmäßig gesperrt');
// Alle Gerätewerte sind der Simulation zufolge im WLED Master nur ein Preset-Signal.
mkVar(80004,1,0,false);
$ampel->props['SignalVariable']=80004;
$ampel->props['EnableAmpel']=true;
$ampel->ApplyChanges();
check(end($GLOBALS['simActions'])===[29605,false],'Signal 0 stellt WLED AUS');
check($GLOBALS['simVars'][29605]['value']===false,'WLED aus bei Signal 0');
foreach([1,2,3,4] as $preset){
    $GLOBALS['simVars'][80004]['value']=$preset;
    $ampel->MessageSink(time(),80004,VM_UPDATE,[]);
    check($GLOBALS['simVars'][46199]['value']===$preset,"Presetwert für Signal $preset");
    check($GLOBALS['simVars'][29605]['value']===true,'Preset schaltet WLED an');
}
$old=count($GLOBALS['simActions']);
$ampel->Sync();
check(count($GLOBALS['simActions'])===$old,'Ampel spamt nicht bei gleichem Zustand');
$GLOBALS['simVars'][80004]['value']=0;
$ampel->Sync();
check($GLOBALS['simVars'][29605]['value']===false,'Nachleuchtzeit / Stopp => WLED aus');
$GLOBALS['simVars'][80004]['value']=3;
$ampel->Sync();
check($GLOBALS['simVars'][29605]['value']===true,'WLED startet erneut bei neuer Fahrt');
$GLOBALS['simVars'][80004]['value']=9;
$ampel->Sync();
check($GLOBALS['simVars'][29605]['value']===false,'Ungültiges Signal schaltet vorsorglich aus');
$ampel->props['EnableAmpel']=false;
$old=count($GLOBALS['simActions']);$ampel->Sync();
check(count($GLOBALS['simActions'])===$old,'Ampel AUS sperrt Schalten');
echo "PASS $cnt Prüfungen, Testsimulation ohne echte Tor-/WLED-Hardware.\n";
