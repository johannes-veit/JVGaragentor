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
$GLOBALS['simWledInstances'] = [];
$GLOBALS['simGateInstances'] = [];
$GLOBALS['simGateSignals'] = [];
$GLOBALS['simLCNModules'] = [];
$GLOBALS['simLCNParents'] = [];
$GLOBALS['simLCNScripts'] = [12119,26612,29055];
$GLOBALS['simScriptRuns'] = [];
function mkVar(int $id, int $type, mixed $value, bool $action = true): void {
    $GLOBALS['simVars'][$id] = ['type'=>$type,'value'=>$value,'action'=>$action];
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['simVars'][$id]); }
function IPS_GetInstanceListByModuleID(string $guid): array {
    return $guid === '{8193BC5B-9A6A-48A3-A70C-B69A73472328}' ? $GLOBALS['simGateInstances'] : [];
}
function IPS_InstanceExists(int $id): bool {
    return in_array($id, $GLOBALS['simGateInstances'], true) ||
        in_array($id, $GLOBALS['simLCNModules'], true) || isset($GLOBALS['simWledInstances'][$id]);
}
function IPS_GetInstance(int $id): array { return $GLOBALS['simWledInstances'][$id]; }
function IPS_ObjectExists(int $id): bool {
    return IPS_VariableExists($id) ||
        in_array($id, $GLOBALS['simLCNModules'], true) ||
        array_key_exists($id, $GLOBALS['simLCNParents']);
}
function IPS_GetParent(int $id): int {
    return (int)($GLOBALS['simLCNParents'][$id] ?? 0);
}
function IPS_ScriptExists(int $id): bool {
    return in_array($id, $GLOBALS['simLCNScripts'], true);
}
function IPS_RunScript(int $id): bool {
    $GLOBALS['simScriptRuns'][] = $id;
    return IPS_ScriptExists($id);
}
function IPS_GetObjectIDByIdent(string $ident, int $parent): int {
    if ($ident === 'Signal' && array_key_exists($parent, $GLOBALS['simGateSignals'])) {
        return $GLOBALS['simGateSignals'][$parent];
    }
    throw new RuntimeException('Ident nicht vorhanden');
}
function IPS_GetVariable(int $id): array {
    $v=$GLOBALS['simVars'][$id];
    return ['VariableType'=>$v['type'],'VariableAction'=>$v['action']?999:0,'VariableCustomAction'=>0];
}
function IPS_GetKernelRunlevel(): int { return 10103; }
function GetValueBoolean(int $id): bool { return (bool)$GLOBALS['simVars'][$id]['value']; }
function GetValueInteger(int $id): int { return (int)$GLOBALS['simVars'][$id]['value']; }
function GetValueString(int $id): string { return (string)$GLOBALS['simVars'][$id]['value']; }
function RequestAction(int $id, mixed $value): bool {
    if (!IPS_VariableExists($id) || !($GLOBALS['simVars'][$id]['action'] ?? false) || in_array($id,$GLOBALS['simNoAction'],true)) return false;
    $GLOBALS['simActions'][]=[$id,$value];
    $GLOBALS['simVars'][$id]['value']=$value;
    if ($id===46199 && in_array($value,[1,2,3,4],true)) $GLOBALS['simVars'][29605]['value']=true;
    if ($id===17676 && in_array($value,[1,2,3,4],true)) $GLOBALS['simVars'][16055]['value']=true;
    return true;
}
class IPSModuleStrict {
    public int $InstanceID=1234;
    public array $props=[],$attrs=[],$values=[],$messages=[],$sent=[];
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function RegisterPropertyBoolean(string $name,bool $value): void {$this->props[$name]=$value;}
    public function RegisterPropertyInteger(string $name,int $value): void {$this->props[$name]=$value;}
    public function RegisterPropertyString(string $name,string $value): void {$this->props[$name]=$value;}
    public function ReadPropertyBoolean(string $name): bool {return (bool)$this->props[$name];}
    public function ReadPropertyInteger(string $name): int {return (int)$this->props[$name];}
    public function ReadPropertyString(string $name): string {return (string)$this->props[$name];}
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
    // Reale öffentliche Methode der IPSModuleStrict-Basisklasse. Schützt
    // gegen einen fatalen Klassenladefehler durch kollidierende Methodennamen.
    public function SetStatus(int $status): bool { return true; }
    public function UpdateVisualizationValue(string $value): bool {$this->sent[]=$value; return true;}
    public function SendDebug(string $name,string $data,int $fmt): void {}
    public function RegisterMessage(int $id,int $msg): void {$this->messages[$id]=$msg;}
    public function UnregisterMessage(int $id,int $msg): void {unset($this->messages[$id]);}
}
require __DIR__.'/../JVGaragentor/module.php';
if (!class_exists(JVGaragentor::class, false)) {
    throw new RuntimeException('Torsteuerungs-Klasse wurde nicht geladen');
}

require __DIR__.'/../JVAmpel/module.php';
$cnt=0;
function check(bool $ok,string $what): void {global $cnt;$cnt++;if(!$ok)throw new RuntimeException("FAIL $cnt: $what");}
function resetHardware(): void {
    $GLOBALS['simVars']=[];$GLOBALS['simActions']=[];
    mkVar(44228,0,false);mkVar(56652,0,false);mkVar(55944,0,true);mkVar(17585,0,true);
    mkVar(80001,0,false,false);mkVar(80002,0,true,false); // Start in Endlage ZU
    mkVar(46199,1,4);mkVar(29605,0,true);
    mkVar(17676,1,3);mkVar(16055,0,true);
    $GLOBALS['simLCNParents'] += [46199=>17732,29605=>17732,17676=>31076,16055=>31076];
    $GLOBALS['simWledInstances'] = [17732=>['InstanceStatus'=>102,'ConnectionID'=>90001],31076=>['InstanceStatus'=>102,'ConnectionID'=>90002],90001=>['InstanceStatus'=>102,'ConnectionID'=>0],90002=>['InstanceStatus'=>102,'ConnectionID'=>0]];
    $GLOBALS['simNoAction'] = [];
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

$ampel=new JVAmpel();$ampel->Create();$ampel->props['UseLocalWatchdog']=false;$ampel->ApplyChanges();
check($ampel->values['AmpelStatus']==='Automatik AUS','Ampel standardmäßig gesperrt');
// Alle Gerätewerte sind der Simulation zufolge im WLED Master nur ein Preset-Signal.
mkVar(80004,1,0,false);
$ampel->props['SignalVariable']=80004;
$ampel->props['EnableAmpel']=true;
$ampel->props['RearPresetsVerified']=true;
$ampel->ApplyChanges();
check(end($GLOBALS['simActions'])===[29605,false],'Signal 0 stellt WLED AUS');
check($GLOBALS['simVars'][29605]['value']===false,'WLED aus bei Signal 0');
check($GLOBALS['simVars'][16055]['value']===false,'Auch WLED hinten bei Signal 0 aus');
foreach([1,2,3,4] as $preset){
    $GLOBALS['simVars'][80004]['value']=$preset;
    $ampel->MessageSink(time(),80004,VM_UPDATE,[]);
    check($GLOBALS['simVars'][46199]['value']===$preset,"Presetwert für Signal $preset");
    check($GLOBALS['simVars'][29605]['value']===true,'Preset schaltet WLED an');
    check($GLOBALS['simVars'][17676]['value']===$preset && $GLOBALS['simVars'][16055]['value']===true,'Hinten erhält denselben aktuellen Presetwert');
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
// Fehler hinten während Rot: vorne reagiert weiter, nach Reconnect nur neuer Sollzustand.
$GLOBALS['simVars'][80004]['value']=3;
$ampel->Sync();
$GLOBALS['simWledInstances'][90002]['InstanceStatus']=200;
$GLOBALS['simVars'][80004]['value']=0;
$ampel->Sync();
check(!$GLOBALS['simVars'][29605]['value'] && $GLOBALS['simVars'][16055]['value'],'Offline hinten: vorne aus, hinten tatsächlich unbekannt');
check(str_contains($ampel->values['AmpelStatus'],'Verbindung gestört'),'Fehler hinten sichtbar');
$GLOBALS['simWledInstances'][90002]['InstanceStatus']=102;
$ampel->Sync();
check(!$GLOBALS['simVars'][16055]['value'],'Reconnect schaltet veraltetes Rot hinten aus');
$GLOBALS['simVars'][80004]['value']=1;
$ampel->Sync();
$GLOBALS['simNoAction']=[17676];
$GLOBALS['simVars'][80004]['value']=4;
$ampel->Sync();
check(!$GLOBALS['simVars'][16055]['value'] && $GLOBALS['simVars'][46199]['value']===4,'Fehler beim Preset hinten: AUS statt altes Signal, vorne unabhängig');
$GLOBALS['simNoAction']=[];
$ampel->Sync();
check($GLOBALS['simVars'][17676]['value']===4 && $GLOBALS['simVars'][16055]['value'],'Fehler behoben: nur aktuelles Preset hinten');
$GLOBALS['simVars'][17585]['value']=false;
$ampel->Sync();
check(!$GLOBALS['simVars'][29605]['value'] && !$GLOBALS['simVars'][16055]['value'],'Tor-Shelly offline: beide AUS');
$GLOBALS['simVars'][17585]['value']=true;
$ampel->Sync();
check(!$GLOBALS['simVars'][29605]['value'],'Keine Reaktivierung des alten Torsignals nach Reconnect');
$GLOBALS['simVars'][80004]['value']=2;
$ampel->Sync();
check($GLOBALS['simVars'][46199]['value']===2 && $GLOBALS['simVars'][17676]['value']===2,'Neues Signal nach Reconnect wird übernommen');
$ampel->props['RearPresetsVerified']=false;
$ampel->Sync();
check(!$GLOBALS['simVars'][16055]['value'],'Ungeprüfte hintere Presets bleiben AUS');
$ampel->props['EnableAmpel']=false;
$old=count($GLOBALS['simActions']);$ampel->Sync();
check(count($GLOBALS['simActions'])===$old,'Ampel AUS sperrt Schalten');

/* Automatische Tor-Ampel-Verknüpfung: Instanz-IDs entstehen erst in Symcon. */
$GLOBALS['simGateInstances'] = [21001];
$GLOBALS['simGateSignals'] = [21001 => 80004];
$ampelAuto = new JVAmpel();
$ampelAuto->InstanceID = 21003;
$ampelAuto->Create();
$ampelAuto->props['UseLocalWatchdog']=false;
$ampelAuto->ApplyChanges();
check($ampelAuto->attrs['SubscribedVariable'] === 80004,
    'Einzige Torinstanz wird ohne manuelle Variable erkannt');
check(($ampelAuto->messages[80004] ?? null) === VM_UPDATE,
    'Automatische Signalvariable auf VM_UPDATE abonniert');
$form = json_decode($ampelAuto->GetConfigurationForm(), true);
check(is_array($form) && str_contains(json_encode($form, JSON_UNESCAPED_UNICODE), 'Automatisch: Torinstanz #21001'),
    'Formular zeigt automatisch ermittelte ID an');
$ampelAuto->props['EnableAmpel'] = true;
$GLOBALS['simVars'][80004]['value'] = 1;
$ampelAuto->ApplyChanges();
check($GLOBALS['simVars'][46199]['value'] === 1,
    'Automatische Ampelverknüpfung schaltet Preset 1');
$GLOBALS['simVars'][80004]['value'] = 3;
$ampelAuto->MessageSink(time(), 80004, VM_UPDATE, []);
check($GLOBALS['simVars'][46199]['value'] === 3,
    'Signaländerung aus automatisch abonnierter Variable verarbeitet');
$GLOBALS['simGateInstances'] = [];
$ampelAuto->Sync();
check($ampelAuto->attrs['SubscribedVariable'] === 0,
    'Bei entferntem Tor wird die automatische Verknüpfung aufgehoben');
check($GLOBALS['simVars'][29605]['value'] === false,
    'Verlust der Tor-Signalquelle schaltet WLED als Fehlersicherung aus');
$GLOBALS['simGateInstances'] = [21001, 21002];
$GLOBALS['simGateSignals'][21002] = 80005;
mkVar(80005, VARIABLETYPE_INTEGER, 0, false);
$ampelAuto->Sync();
check($ampelAuto->attrs['SubscribedVariable'] === 0,
    'Bei zwei Torinstanzen keine willkürliche Wahl');
$ampelAuto->props['SignalVariable'] = 80004;
$ampelAuto->Sync();
check($ampelAuto->attrs['SubscribedVariable'] === 80004,
    'Explizite Zuordnung hat Vorrang auch bei mehreren Toren');
$ampelAuto->props['SignalVariable'] = 123456;
$ampelAuto->Sync();
check($ampelAuto->attrs['SubscribedVariable'] === 0,
    'Ungültige manuelle ID führt nicht zu automatischem Fallback');
$ampelAuto->props['SignalVariable'] = 0;
$GLOBALS['simGateInstances'] = [21001];
$ampelAuto->Sync();
check($ampelAuto->attrs['SubscribedVariable'] === 80004,
    'Automatische Verbindung nach erneutem Auftauchen der Torinstanz wiederhergestellt');


// LCN-SKH-Codec: kurze, lange und Los-Telegramme je Tabelle und Taste.
check(JVLCNCodec::command('C',1,'KURZ') === '57 21 02','C1 kurz: SKH-Code 57 21 02');
check(JVLCNCodec::code('C',1,'KURZ') === '033002','C1 kurz: Host-String 033002');
check(JVLCNCodec::code('C',1,'LANG') === '033001','C1 lang: Host-String 033001');
check(JVLCNCodec::code('C',1,'LOS') === '033000','C1 los: Host-String 033000');
check(JVLCNCodec::code('C',2,'KURZ') === '034002','C2 kurz: Host-String 034002');
check(JVLCNCodec::code('A',8,'KURZ') === '008002','A8 kurz');
check(JVLCNCodec::code('B',3,'KURZ') === '019002','B3 kurz');
check(JVLCNCodec::code('D',8,'LANG') === '072001','D8 lang');
check(JVLCNCodec::code('A',9,'KURZ') === null,'Tastennummer 9 nicht in einzelner Tabelle');
check(JVLCNCodec::normalize('33002') === '033002','Host-Wert mit verlorener führender Null');
check(JVLCNCodec::normalize('033002') === '033002','Host-Wert String');
check(JVLCNCodec::normalize('Befehl 033002') === null,'Beliebige Eingaben abweisen');
check(JVLCNCodec::normalize('255002') === null,'Unbekannte SKH-Kennung abweisen');

// Die sichtbare Belegung aus LCN-PRO: C1 = SCHIEBETOR, C2 = GARAGENTOR.
resetHardware();
$GLOBALS['simLCNModules']=[5010]; // Beispiel M51 als gültig gewählte LCN Modul-Instanz
$GLOBALS['simLCNParents']=[80101=>5011,5011=>5010,5010=>0];
mkVar(80101,VARIABLETYPE_STRING,'033002',false);
$lcnGate = new JVGaragentor();
$lcnGate->Create();
$lcnGate->props['LCNGarageModule']=5010;
$lcnGate->props['LCNGarageHostVariable']=80101;
$lcnGate->props['LCNSchiebeModule']=5010;
$lcnGate->props['LCNSchiebeHostVariable']=80101;
$lcnGate->props['EndOpenVariable']=80001;
$lcnGate->props['EndCloseVariable']=80002;
$lcnGate->ApplyChanges();
check(($lcnGate->messages[80101] ?? 0) === VM_UPDATE,'Ein Host-Update wird für beide Tastenkanäle abonniert');
check($lcnGate->props['LCNGarageKey']===2 && $lcnGate->props['LCNSchiebeKey']===1,
    'Voreinstellung gemäß Screenshot: Garage C2, Schiebe C1');
check($lcnGate->props['LCNGarageEnabled'] === false &&
    $lcnGate->props['LCNSchiebeEnabled'] === false,'LCN-Tasten ab Werk deaktiviert');
$actionCount=count($GLOBALS['simActions']);
$scriptCount=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'C1 kurz in Trockenphase');
check(count($GLOBALS['simActions']) === $actionCount &&
    count($GLOBALS['simScriptRuns']) === $scriptCount,'Trockenphase schaltet kein Tor');
check(str_contains($lcnGate->values['LCNActivity'],'TEST:'),'Trockenphase zeigt LCN-Diagnose');
$form=json_decode($lcnGate->GetConfigurationForm(),true);
check(is_array($form) && str_contains(json_encode($form), '57 21 02') &&
    str_contains(json_encode($form), '57 22 01'),'Formular zeigt passende SKH-Kommandos');

$lcnGate->props['LCNGarageEnabled']=true;
check(!$lcnGate->ProcessLCNInput(80101,'034001'),'LCN-Freigabe ersetzt Torfreigabe nicht');
$lcnGate->props['EnableControl']=true;
check($lcnGate->ProcessLCNInput(80101,'034001'),'C2 LANG öffnet Garagentor via Command');
check(end($GLOBALS['simActions']) === [44228,true],'LCN Garagentor verwendet O1 AUF');
$GLOBALS['simVars'][44228]['value']=false;
check(!$lcnGate->ProcessLCNInput(80101,'034001'),'Doppelt empfangenes LCN LANG ignoriert');
check(count($GLOBALS['simActions']) === $actionCount+1,'Keine doppelte Shelly-Auslösung');
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'C1 bei abgeschaltetem Schiebetorkanal ohne Aktion');

$lcnGate->props['LCNSchiebeEnabled']=true;
check($lcnGate->ProcessLCNInput(80101,'033002'),'C1 KURZ startet Schiebetor ZU');
check(end($GLOBALS['simScriptRuns']) === 26612,'Schiebetor ZU über vorhandenes Skript');
check($lcnGate->ProcessLCNInput(80101,'033001'),'C1 LANG startet Schiebetor AUF');
check(end($GLOBALS['simScriptRuns']) === 12119,'Schiebetor AUF über vorhandenes Skript');
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033000'),'C1 LOS hat standardmäßig keine Aktion');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'LOS führt keine Torbefehle aus');
$lcnGate->props['LCNSchiebeRelease']='STOP';
check($lcnGate->ProcessLCNInput(80101,'033000'),'LOS kann unabhängig STOP zugeordnet werden');
check(end($GLOBALS['simScriptRuns']) === 29055,'Schiebetor STOP über vorhandenes Skript');
$lcnGate->props['LCNSchiebeRelease']='NONE';

$lcnGate->props['LCNSchiebeCloseScript']=0;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'Fehlendes Schiebetor-Skript sperrt Befehl');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'Keine Auslösung bei fehlendem Skript');
$lcnGate->props['LCNSchiebeCloseScript']=26612;

$lcnGate->props['LCNSchiebeKey']=2;
$oldActions=count($GLOBALS['simActions']);$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'034002'),'Kollision gleiche Taste blockiert beide Tore');
check(count($GLOBALS['simActions']) === $oldActions &&
    count($GLOBALS['simScriptRuns']) === $oldRuns,'Mehrdeutigkeit löst keine Tore aus');
$lcnGate->props['LCNSchiebeKey']=1;

$GLOBALS['simLCNParents'][80101]=123;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'Host-Variable von falschem Modul wird abgewiesen');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'Kein Torbefehl bei falscher Modulzuordnung');
$GLOBALS['simLCNParents'][80101]=5011;
check(!$lcnGate->ProcessLCNInput(98765,'033002'),'Unbekannte Quellvariable abweisen');
check(!$lcnGate->ProcessLCNInput(80101,'123abc'),'Fehlerhaftes Telegramm abweisen');

$lcnGate->props['LCNSchiebeEnabled']=false;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033001'),'Schiebetor LCNTaste separat abschaltbar');
check(count($GLOBALS['simScriptRuns'])===$oldRuns,'Abschaltung tatsächlich wirksam');
$lcnGate->props['LCNSchiebeHostVariable']=0;
$lcnGate->ApplyChanges();
check(($lcnGate->messages[80101]??0) === VM_UPDATE,
    'Abo bleibt bei nur einer von zwei gleichen Host-Quellen bestehen');
$lcnGate->props['LCNGarageHostVariable']=0;
$lcnGate->ApplyChanges();
check(!isset($lcnGate->messages[80101]),'Entfernte Host-Quelle wird abgemeldet');


/* Reales Screenshot-Setup M51: Kategorie 37682 -> Host-Kommando 30888 -> Befehl 35739. */
resetHardware();
$GLOBALS['simLCNModules'] = [30888];
$GLOBALS['simLCNParents'] = [35739 => 30888, 30888 => 37682, 37682 => 0];
mkVar(35739, VARIABLETYPE_STRING, '033002', false);
$realLCN = new JVGaragentor();
$realLCN->Create();
check($realLCN->props['LCNGarageModule'] === 30888 &&
    $realLCN->props['LCNSchiebeModule'] === 30888,
    'Neuanlage: beide LCN-Kanäle mit Host-Instanz 30888 vorbelegt');
check($realLCN->props['LCNGarageHostVariable'] === 35739 &&
    $realLCN->props['LCNSchiebeHostVariable'] === 35739,
    'Neuanlage: beide LCN-Kanäle mit Befehl 35739 vorbelegt');
$realLCN->ApplyChanges();
check(($realLCN->messages[35739] ?? 0) === VM_UPDATE,
    'Bekannte Host-Kommando-Variable wird automatisch abonniert');
check(!$realLCN->ProcessLCNInput(35739, '033002'),
    'Sicherer Trockenlauf: C1 kurz sendet noch keinen Torbefehl');
check(str_contains($realLCN->values['LCNActivity'], 'Schiebe') &&
    str_contains($realLCN->values['LCNActivity'], 'C1 KURZ'),
    'LCN-Diagnose erfasst physische C1-Kurzmeldung');
check($realLCN->props['LCNGarageEnabled'] === false &&
    $realLCN->props['LCNSchiebeEnabled'] === false,
    'LCN-M51-Voreinstellungen schalten keine Fahrbefehle frei');
$realLCN->props['LCNGarageModule'] = 0;
$realLCN->props['LCNSchiebeModule'] = 0;
$realLCN->props['LCNGarageHostVariable'] = 0;
$realLCN->props['LCNSchiebeHostVariable'] = 0;
$realLCN->ApplyChanges();
check(($realLCN->messages[35739] ?? 0) === VM_UPDATE,
    'Bestehende Instanz mit alten Nullwerten erkennt bekannte M51-Quelle automatisch');
check(!$realLCN->ProcessLCNInput(35739, '034001') &&
    str_contains($realLCN->values['LCNActivity'], 'C2 LANG'),
    'Automatische Nullwert-Vorbelegung erkennt auch C2 lang');
$GLOBALS['simLCNParents'][35739] = 1234;
$realLCN->ApplyChanges();
check(!isset($realLCN->messages[35739]),
    'Automatische Quellwahl fällt bei abweichender Elterninstanz sicher weg');
// Reales Shelly-Setup: beide bestätigten Eingänge statt Ausgangsstatus.
resetHardware();
$GLOBALS['simLCNParents'] = [44228=>22849,56652=>22849,55944=>22849,17585=>22849,13220=>22849,21923=>22849];
mkVar(13220, VARIABLETYPE_BOOLEAN, false, false);
mkVar(21923, VARIABLETYPE_BOOLEAN, true, false);
$realGate = new JVGaragentor();
$realGate->Create();
check($realGate->props['EndOpenVariable'] === 13220 && $realGate->props['EndCloseVariable'] === 21923,
    'Bestätigte Eingangsstatus-IDs bei Neuanlage vorbelegt');
$realGate->ApplyChanges();
check($realGate->values['Position'] === 'ZU' && $realGate->values['Signal'] === 0,
    'Realer Eingang ZU bei Start, ohne künstliches Nachleuchten');
$realGate->props['EndOpenVariable'] = 0;
$realGate->props['EndCloseVariable'] = 0;
$realGate->ApplyChanges();
check($realGate->values['Position'] === 'ZU','Alte Nullwerte: bestätigte Eingänge automatisch erkannt');
$GLOBALS['simVars'][44228]['value'] = true;
$realGate->Monitor();
check($realGate->values['Position'] === 'ZU','Ausgang AUF wird nicht als Endlage ausgewertet');
$GLOBALS['simVars'][44228]['value'] = false;
$GLOBALS['simVars'][21923]['value'] = false;
$realGate->Monitor();
check($realGate->values['Position'] === 'GESTOPPT','Zwischenposition ohne Fahrbefehl nicht als Endlage ausgegeben');
$GLOBALS['simVars'][13220]['value'] = true;
$realGate->Monitor();
check($realGate->values['Position'] === 'AUF' && $realGate->values['Signal'] === 2,
    'Realer SW2 ergibt AUF und Grün');
$realGate->attrs['SignalUntil'] = time()-1;
$realGate->Monitor();
check($realGate->values['Position'] === 'AUF' && $realGate->values['Signal'] === 0,
    'Nachleuchtzeit endet, AUF bleibt');
$GLOBALS['simVars'][13220]['value'] = false;
$GLOBALS['simVars'][21923]['value'] = true;
$realGate->Monitor();
check($realGate->values['Position'] === 'ZU' && $realGate->values['Signal'] === 4,
    'Realer SW1 ergibt ZU und Rot');
$realGate->props['EndOpenVariable'] = 44228;
$realGate->props['EnableControl'] = true;
$realGate->Monitor();
check($realGate->values['Position'] === 'GESTOPPT' && $realGate->values['Signal'] === 0,
    'Falsch eingetragener Relaisstatus ergibt keine Endlage');
$before = count($GLOBALS['simActions']);
check(!$realGate->Command('AUF') && count($GLOBALS['simActions']) === $before,
    'Relaisstatus als Endlage sperrt Fahrbefehl');
$realGate->props['EndOpenVariable'] = 21923;
$realGate->props['EndCloseVariable'] = 13220;
$realGate->Monitor();
check($realGate->values['Position'] === 'GESTOPPT' && !$realGate->Command('AUF'),
    'Vertauschte bekannte Endlagen sperren Fahrbefehl');
$realGate->props['EndOpenVariable'] = 0;
$realGate->props['EndCloseVariable'] = 0;
$GLOBALS['simLCNParents'][13220] = 999;
$realGate->Monitor();
check($realGate->values['Position'] === 'GESTOPPT' && !$realGate->Command('AUF'),
    'Keine automatische Zuordnung bei falscher Elterninstanz');
$GLOBALS['simLCNParents'][13220] = 22849;
$realGate->Monitor();
check($realGate->values['Position'] === 'ZU' && $realGate->values['Signal'] === 0,
    'Wiederhergestellte Zuordnung zeigt bestätigte Endlage ohne Schein-Nachleuchten');
// JSON-Watchdog: emulate WLED API (not actual firmware or LED timing).
class WdTestAmpel extends JVAmpel {
    public array $docs=[], $presets=[], $requests=[];
    public function initWd(): void {
        foreach (['front'=>'8c8c290df2ec','rear'=>'007007c90d6c'] as $side=>$mac) {
            $this->docs[$side]=['info'=>['ver'=>'0.15.0-b7','mac'=>$mac,'leds'=>['count'=>1,'bootps'=>0],'fs'=>['pmt'=>1]],'state'=>['on'=>false,'bri'=>255,'pl'=>-1,'nl'=>['on'=>false],'udpn'=>['send'=>false,'sgrp'=>0,'rgrp'=>0],'seg'=>[]]];
            $this->presets[$side]=[];
        }
        foreach ([1=>[255,160,0,0],2=>[0,255,0,0],3=>[255,0,0,0],4=>[255,0,0,0]] as $i=>$col)
            $this->presets['front'][$i]=['on'=>true,'bri'=>255,'seg'=>[['id'=>0,'col'=>[$col,[0,0,0,0],[0,0,0,0]],'fx'=>in_array($i,[1,3])?2:0,'sx'=>128,'ix'=>128,'pal'=>0]]];
        $this->presets['rear'][250]=['n'=>'Existing user preset','on'=>true];
    }
    protected function WledHttp(array $requests): array {
        $this->requests[]=$requests; $r=[];
        foreach ($requests as $side=>$req) {
            if (!isset($this->docs[$side])) { $r[$side]=null; continue; }
            if ($req['path']==='/json/si') { $r[$side]=$this->docs[$side]; continue; }
            if ($req['path']==='/presets.json') { $r[$side]=$this->presets[$side]; continue; }
            $b=$req['body']; $s=&$this->docs[$side]['state'];
            if (isset($b['psave'])) {
                $id=$b['psave']; $save=$b; unset($save['psave'],$save['o'],$save['bootps']);
                $this->presets[$side][$id]=$save; $this->docs[$side]['info']['fs']['pmt']++;
                if (isset($b['bootps'])) $this->docs[$side]['info']['leds']['bootps']=$b['bootps'];
            }
            foreach (['on','bri','seg','nl','udpn'] as $k) if (isset($b[$k])) $s[$k]=$b[$k];
            if (isset($b['playlist'])) $s['pl']=$b['playlist']['ps']===[]?-1:0;
            $r[$side]=['success'=>true]; unset($s);
        }
        return $r;
    }
}
resetHardware(); mkVar(80004,VARIABLETYPE_INTEGER,0,false);
$wd=new WdTestAmpel();$wd->Create();$wd->initWd();
$wd->props['SignalVariable']=80004;$wd->props['EnableAmpel']=true;$wd->ApplyChanges();
check($wd->attrs['WatchdogSetup']!=='','Automatische Watchdog-Einrichtung ohne Freigabe hinten');
check($wd->presets['rear'][250]['n']==='Existing user preset','Keine vorhandenen Presets überschrieben');
check($wd->docs['rear']['info']['leds']['bootps']!==0,'Boot-AUS eingerichtet');
$wd->Sync();
$GLOBALS['simVars'][80004]['value']=3;
$wd->requests=[];$wd->MessageSink(time(),80004,VM_UPDATE,[]);
check(count($wd->requests)===2&&isset($wd->requests[0]['front'],$wd->requests[0]['rear'])&&$wd->requests[0]['front']['path']==='/json/state','Signalwechsel ohne vorherigen Poll parallel gesendet, danach helle Pulsphase');
check($wd->requests[0]['front']['body']['tb']===1008 && $wd->requests[1]['front']['body']['tb']===1008 && $wd->requests[1]['rear']['body']['tb']===1008,'Breathe startet auf berechneter heller Phase, auch nach AUS-Reset');
check(!isset($wd->requests[1]['front']['body']['playlist'])&&!isset($wd->requests[1]['front']['body']['seg']),'Phasenkorrektur verändert weder Effekt noch lokalen Watchdog');
check($wd->requests[0]['rear']['body']['playlist']['dur']===[50]&&$wd->requests[0]['rear']['body']['tt']===0,'Lokales Ablaufdatum und sofortiger Start');
$wd->Sync();
check(str_contains($wd->values['AmpelStatus'],'synchron'),'Beide echten Szenenparameter abgeglichen');
$wd->docs['rear']['state']['seg'][0]['fx']=0;
$wd->Sync();
check(!$wd->docs['front']['state']['on']&&!$wd->docs['rear']['state']['on'],'Abweichender Effekt führt zu AUS auf beiden Seiten');
$wd->Sync();
check($wd->docs['rear']['state']['seg'][0]['fx']===2,'Aktuelle Szene nach Abweichung korrigiert');
$GLOBALS['simVars'][80004]['value']=0;
$wd->Sync();
check(!$wd->docs['front']['state']['on']&&!$wd->docs['rear']['state']['on'],'Signal 0 schaltet beide ab');
$wd->requests=[];$wd->CheckSignal();
check(count($wd->requests)===0,'Schneller lokaler Signalcheck erzeugt im Ruhezustand keinen WLED-Verkehr');
$GLOBALS['simVars'][80004]['value']=1;
$wd->CheckSignal();
check(isset($wd->requests[0]['front']['body']['seg'])&&$wd->requests[0]['front']['body']['seg'][0]['col'][0]===[255,160,0,0],'Lokaler 250-ms-Fallback startet gelbe Fahrt auch ohne VM_UPDATE');
$GLOBALS['simVars'][80004]['value']=2;
$wd->requests=[];$wd->CheckSignal();
check(count($wd->requests)===1&&!isset($wd->requests[0]['front']['body']['tb']),'Endlage Grün unverzögert statisch ohne Phasenkorrektur');
$GLOBALS['simVars'][80004]['value']=0;
$wd->Sync();
// One-time commissioning of existing garage only, no movement during update.
resetHardware();
$GLOBALS['simLCNModules']=[30888];
$GLOBALS['simLCNParents']=[35739=>30888,30888=>37682,37682=>0,44228=>22849,56652=>22849,55944=>22849,17585=>22849,13220=>22849,21923=>22849];
mkVar(35739,VARIABLETYPE_STRING,'034001',false);
mkVar(13220,VARIABLETYPE_BOOLEAN,false,false);mkVar(21923,VARIABLETYPE_BOOLEAN,true,false);
$commission=new JVGaragentor();$commission->InstanceID=15957;$commission->Create();$commission->props['MotionFeedbackHost']='off';
$commission->props['EnableControl']=true;$commission->props['EndOpenVariable']=0;$commission->props['EndCloseVariable']=0;
$commission->props['RequireEndStops']=false;$commission->props['LCNSchiebeEnabled']=true;
$commission->ApplyChanges();
check($commission->LCNCommissionReady(),'Bekannte bestehende Garage in Endlage darf C2 einmalig freigeben');
check(count($GLOBALS['simActions'])===0,'Update sendet keinen Torbefehl und spielt altes LCN-Telegramm nicht erneut ab');
$GLOBALS['simVars'][55944]['value']=false;
check(!$commission->LCNCommissionReady(),'Keine Freigabe bei STOP-Relais AUS');
$GLOBALS['simVars'][55944]['value']=true;
$GLOBALS['simVars'][17585]['value']=false;
check(!$commission->LCNCommissionReady(),'Keine Freigabe bei Shelly offline');
$GLOBALS['simVars'][17585]['value']=true;
$commission->props['EnableControl']=false;
check(!$commission->LCNCommissionReady(),'Masterfreigabe wird nicht umgangen');
$commission->props['EnableControl']=true;
$commission->props['LCNSchiebeKey']=2;
check(!$commission->LCNCommissionReady(),'Keine Freigabe bei C2-Kollision');
$commission->props['LCNSchiebeKey']=1;
$commission->InstanceID=12345;
check(!$commission->LCNCommissionReady(),'Keine automatische Freigabe auf fremder Instanz');
$commission->InstanceID=15957;
// Simulate external timer applying the verified settings.
$commission->props['EndOpenVariable']=13220;$commission->props['EndCloseVariable']=21923;
$commission->props['RequireEndStops']=true;$commission->props['LCNGarageEnabled']=true;
$commission->ApplyChanges();
check($commission->attrs['LCNCommissionDone127'],'Einmalige Migration nach Übernahme abgeschlossen');
check($commission->props['LCNSchiebeEnabled']===true,'Bestehende Schiebetorfreigabe unverändert');
check($commission->ProcessLCNInput(35739,'034001')&&end($GLOBALS['simActions'])===[44228,true],'C2 LANG nutzt unverändert reguläre AUF-Steuerung');
$commission->props['LCNGarageEnabled']=false;$commission->ApplyChanges();
check(!$commission->LCNCommissionReady(),'Nach manueller Deaktivierung keine erneute automatische Freigabe');
// Regression: actual C2 telegram must be used, not a subsequent LOS in the variable.
// Also correct stored output IDs as end stops on the specifically verified garage.
resetHardware();
$GLOBALS['simLCNModules']=[30888];
$GLOBALS['simLCNParents']=[35739=>30888,30888=>37682,37682=>0,44228=>22849,56652=>22849,55944=>22849,17585=>22849,13220=>22849,21923=>22849];
mkVar(35739,VARIABLETYPE_STRING,'034000',false);
mkVar(13220,VARIABLETYPE_BOOLEAN,false,false);mkVar(21923,VARIABLETYPE_BOOLEAN,true,false);
$repair=new JVGaragentor();$repair->InstanceID=15957;$repair->Create();$repair->props['MotionFeedbackHost']='off';
$repair->props['EnableControl']=true;$repair->props['EndOpenVariable']=44228;$repair->props['EndCloseVariable']=56652;
$repair->ApplyChanges();
check($repair->values['Position']==='ZU','Bekannte Status-Verwechslung korrigiert: Endlage aus Eingangsstatus');
check($repair->LCNCommissionReady(),'Alte Status-IDs verhindern C2-Migration nicht mehr');
check(count($GLOBALS['simActions'])===0,'Korrektur erzeugt keine Torfahrt');
$repair->props['LCNGarageEnabled']=true;$repair->props['EndOpenVariable']=13220;$repair->props['EndCloseVariable']=21923;
$repair->messages=[];$repair->ApplyChanges();
check(($repair->messages[35739]??0)===VM_UPDATE,'Verlorene Host-Registrierung beim Update erneut aufgebaut');
$repair->MessageSink(time(),35739,VM_UPDATE,['034001',true,'034000']);
check(end($GLOBALS['simActions'])===[44228,true],'C2 LANG wird aus Ereigniswert verarbeitet obwohl String schon LOS enthält');
$before=count($GLOBALS['simActions']);
$repair->MessageSink(time(),35739,VM_UPDATE,['034000',true,'034001']);
check(count($GLOBALS['simActions'])===$before,'C2 LOS löst weiterhin nichts aus');
$repair->MessageSink(time(),35739,VM_UPDATE,[]);
check(count($GLOBALS['simActions'])===$before,'Fehlende Ereignisdaten spielen keinen gespeicherten Befehl ab');
$other=new JVGaragentor();$other->Create();$other->props['EndOpenVariable']=44228;$other->props['EndCloseVariable']=56652;$other->props['EnableControl']=true;$other->ApplyChanges();
check(!$other->Command('AUF'),'Fremde Instanz mit Status statt Eingangsstatus bleibt gesperrt');

// EM 172 direction feedback: test the actual module with a mocked HTTP response.
class MotionTestGate extends JVGaragentor {
    public ?array $motionDoc = null;
    public array $motionHosts = [];
    protected function MotionHttpStatus(string $host): ?array {
        $this->motionHosts[] = $host;
        return $this->motionDoc;
    }
    protected function MotionExpectedMacHash(): string { return hash('sha256','020000000001'); }
    public function feedback(bool $up, bool $down): void {
        $this->motionDoc = ['sys'=>['mac'=>'020000000001'],
            'input:0'=>['id'=>0,'state'=>$up], 'input:1'=>['id'=>1,'state'=>$down]];
    }
}
resetHardware();
$GLOBALS['simLCNModules']=[30888];
$GLOBALS['simLCNParents']=[35739=>30888,30888=>37682,37682=>0,44228=>22849,56652=>22849,55944=>22849,17585=>22849,13220=>22849,21923=>22849];
mkVar(35739,VARIABLETYPE_STRING,'034002',false);
mkVar(13220,VARIABLETYPE_BOOLEAN,false,false);
mkVar(21923,VARIABLETYPE_BOOLEAN,true,false);
$m=new MotionTestGate();$m->InstanceID=15957;$m->Create();
$m->props['EnableControl']=true;$m->props['LCNGarageEnabled']=true;
$m->feedback(false,false);$m->ApplyChanges();
check($m->props['MotionFeedbackHost']==='auto' && end($m->motionHosts)==='192.168.103.93','Migration: i4 automatic without reconfiguration');
check($m->values['Position']==='ZU' && $m->values['Signal']===0 && count($GLOBALS['simActions'])===0,'Startup idle no replay/no relay');
$m->feedback(true,false);$m->Monitor();
check($m->values['Position']==='LÄUFT AUF' && $m->values['Signal']===1,'External AUF even while departing end contact remains on');
check(count($GLOBALS['simActions'])===0,'External motion detected without relay command');
$motionSnapshot=json_decode(end($m->sent),true);
check($motionSnapshot['effectUntil']>=time()+3 && $motionSnapshot['effectUntil']<=time()+4,
    'Kachel gets a renewable short deadline for real motion, not zero');
$GLOBALS['simVars'][21923]['value']=false;
check($m->ProcessLCNInput(35739,'034002') && end($GLOBALS['simActions'])===[55944,false],'C2 short during external opening sends STOP');
check($m->values['Signal']===1,'STOP command not mistaken for actual standstill');
$GLOBALS['simVars'][55944]['value']=true;
$m->feedback(false,false);$m->Monitor();
check($m->values['Position']==='GESTOPPT' && $m->values['Signal']===0,'Actual intermediate stop');
$m->attrs['LCNLastDispatch']='{}';
check($m->ProcessLCNInput(35739,'034002') && end($GLOBALS['simActions'])===[56652,true],'C2 short at standstill sends close');
check($m->values['Signal']===0,'No optimistic movement after accepted command');
$GLOBALS['simVars'][56652]['value']=false;
$m->attrs['LCNLastDispatch']='{}';
check($m->ProcessLCNInput(35739,'034002') && end($GLOBALS['simActions'])===[55944,false],'Short while command pending sends STOP');
$GLOBALS['simVars'][55944]['value']=true;
$m->feedback(false,true);$m->Monitor();
check($m->values['Position']==='LÄUFT ZU' && $m->values['Signal']===3,'External ZU signal');
$m->attrs['LCNLastDispatch']='{}';
check($m->ProcessLCNInput(35739,'034002') && end($GLOBALS['simActions'])===[55944,false],'C2 short during external closing sends STOP');
$GLOBALS['simVars'][55944]['value']=true;
$m->feedback(true,false);$m->Monitor();
check($m->values['Signal']===1,'External reversal from closing to opening');
$m->attrs['MovementStarted']=time()-130;$m->Monitor();
check($m->values['Signal']===1 && $m->attrs['NeedsStop'],'Timeout does not fake stop while motion proven');
$motionSnapshot=json_decode(end($m->sent),true);
check($motionSnapshot['effectUntil']>=time()+3,'Kachel remains lit even after movement timeout if feedback still valid');
$m->feedback(false,false);$GLOBALS['simVars'][13220]['value']=true;$m->Monitor();
check($m->values['Position']==='AUF' && $m->values['Signal']===2,'End AUF green after external drive');
$m->attrs['SignalUntil']=time()-1;$m->Monitor();
check($m->values['Signal']===0 && $m->values['Position']==='AUF','Existing hold time retained');
$GLOBALS['simVars'][13220]['value']=false;
$m->feedback(false,true);$m->Monitor();$m->feedback(false,false);$GLOBALS['simVars'][21923]['value']=true;$m->Monitor();
check($m->values['Signal']===4 && $m->values['Position']==='ZU','End ZU red after external drive');
$GLOBALS['simVars'][21923]['value']=false;
$m->feedback(true,true);$m->Monitor();
check(!$m->attrs['MotionFeedbackOK'] && $m->values['Signal']===0,'Conflicting feedback disables amp signal');
$before=count($GLOBALS['simActions']);
check(!$m->Command('ZU') && count($GLOBALS['simActions'])===$before,'Invalid feedback blocks movement');
$m->attrs['LCNLastDispatch']='{}';
check($m->ProcessLCNInput(35739,'034002') && end($GLOBALS['simActions'])===[55944,false],'Short with invalid feedback only sends STOP');
$GLOBALS['simVars'][55944]['value']=true;
$m->motionDoc=null;$m->Monitor();
check(!$m->attrs['MotionFeedbackOK'] && $m->values['Signal']===0,'Lost i4 cannot display stale movement or green');
$m->feedback(false,true);$m->Monitor();
check($m->attrs['MotionFeedbackOK'] && $m->values['Signal']===3,'Recovery during motion restores signal');
$m->motionDoc['sys']['mac']='000000000000';$m->Monitor();
check(!$m->attrs['MotionFeedbackOK'],'Wrong device at configured IP rejected');
$m->feedback(false,false);$m->motionDoc['input:0']['state']=null;$m->Monitor();
check(!$m->attrs['MotionFeedbackOK'],'Null is not standstill');
$m->feedback(false,false);$m->Monitor();$m->attrs['LCNLastDispatch']='{}';
$GLOBALS['simVars'][55944]['value']=false;
$before=count($GLOBALS['simActions']);
check(!$m->Command('AUF') && count($GLOBALS['simActions'])===$before,'No drive while STOP contact open');
$GLOBALS['simVars'][55944]['value']=true;
check($m->ProcessLCNInput(35739,'034001') && end($GLOBALS['simActions'])===[44228,true],'C2 long unchanged: AUF');


// End-to-end simulated path from EM172 feedback through existing watchdog
// to the actual color/effect JSON on both WLED controllers.
$GLOBALS['simVars'][44228]['value']=false;
$m->attrs['MotionPendingUntil']=0;
$m->feedback(false,false);$m->Monitor();
mkVar(80004,VARIABLETYPE_INTEGER,(int)$m->values['Signal'],false);
$motionAmpel=new WdTestAmpel();$motionAmpel->Create();$motionAmpel->initWd();
$motionAmpel->props['SignalVariable']=80004;$motionAmpel->props['EnableAmpel']=true;
$motionAmpel->ApplyChanges();$motionAmpel->Sync();
function publishMotionSignal(MotionTestGate $gate, WdTestAmpel $ampel): void {
    $GLOBALS['simVars'][80004]['value']=(int)$gate->values['Signal'];
    $ampel->MessageSink(time(),80004,VM_UPDATE,[]);
}
function checkMotionScene(WdTestAmpel $ampel,array $color,bool $pulse,string $name): void {
    foreach (['front','rear'] as $side) {
        $state=$ampel->docs[$side]['state'];
        $seg=$state['seg'][0]??[];
        check($state['on']===true && ($seg['col'][0]??null)===$color &&
            ($pulse ? ($seg['fx']??0)!==0 : ($seg['fx']??-1)===0),$name.' '.$side);
    }
}
$m->feedback(true,false);$m->Monitor();publishMotionSignal($m,$motionAmpel);
checkMotionScene($motionAmpel,[255,160,0,0],true,'EM172 AUF yellow pulse');
$m->feedback(false,false);$GLOBALS['simVars'][13220]['value']=true;$m->Monitor();publishMotionSignal($m,$motionAmpel);
checkMotionScene($motionAmpel,[0,255,0,0],false,'End AUF green');
$GLOBALS['simVars'][13220]['value']=false;
$m->feedback(false,true);$m->Monitor();publishMotionSignal($m,$motionAmpel);
checkMotionScene($motionAmpel,[255,0,0,0],true,'EM172 ZU red pulse');
$m->feedback(false,false);$GLOBALS['simVars'][21923]['value']=true;$m->Monitor();publishMotionSignal($m,$motionAmpel);
checkMotionScene($motionAmpel,[255,0,0,0],false,'End ZU red');
$GLOBALS['simVars'][21923]['value']=false;
$m->feedback(true,false);$m->Monitor();publishMotionSignal($m,$motionAmpel);
$m->motionDoc=null;$m->Monitor();publishMotionSignal($m,$motionAmpel);
check(!$motionAmpel->docs['front']['state']['on']&&!$motionAmpel->docs['rear']['state']['on'],'Feedback loss disables both WLED controllers');
$m->feedback(true,false);$m->Monitor();publishMotionSignal($m,$motionAmpel);
checkMotionScene($motionAmpel,[255,160,0,0],true,'Feedback recovered');
$m->feedback(false,false);$m->Monitor();publishMotionSignal($m,$motionAmpel);
check(!$motionAmpel->docs['front']['state']['on']&&!$motionAmpel->docs['rear']['state']['on'],'Confirmed intermediate stop disables both WLED');

echo "PASS $cnt Prüfungen, Testsimulation ohne echte Tor-/WLED-Hardware.\n";
