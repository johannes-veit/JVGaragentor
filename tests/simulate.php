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
        in_array($id, $GLOBALS['simLCNModules'], true);
}
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

/* Automatische Tor-Ampel-Verknüpfung: Instanz-IDs entstehen erst in Symcon. */
$GLOBALS['simGateInstances'] = [21001];
$GLOBALS['simGateSignals'] = [21001 => 80004];
$ampelAuto = new JVAmpel();
$ampelAuto->InstanceID = 21003;
$ampelAuto->Create();
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

// Neue Torinstanz, damit vorangehende Zustandstests nicht beeinflusst werden.
resetHardware();
$GLOBALS['simLCNModules']=[5010]; // Beispiel M51 als gültig gewählte LCN Module Instanz
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
check(($lcnGate->messages[80101] ?? 0) === VM_UPDATE,'Ein LCN-Host-Update für beide Tasten abonniert');
check($lcnGate->props['LCNGarageEnabled'] === false &&
    $lcnGate->props['LCNSchiebeEnabled'] === false,'LCN-Tasten ab Werk deaktiviert');
$actionCount=count($GLOBALS['simActions']);
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'C1 kurz in Trockenphase');
check(count($GLOBALS['simActions']) === $actionCount,'Trockenphase schaltet kein Shelly');
check(str_contains($lcnGate->values['LCNActivity'],'TEST:'),'Trockenphase zeigt LCN-Diagnose');
$form=json_decode($lcnGate->GetConfigurationForm(),true);
check(is_array($form) && str_contains(json_encode($form), '57 21 02') &&
    str_contains(json_encode($form), '57 22 01'),'Formular zeigt passende SKH-Kommandos');

$lcnGate->props['LCNGarageEnabled']=true;
check(!$lcnGate->ProcessLCNInput(80101,'033001'),'LCN-Freigabe ersetzt Torfreigabe nicht');
$lcnGate->props['EnableControl']=true;
$lcnGate->attrs['LCNLastDispatch']='{}'; // Isolierter Test nach geänderter Torfreigabe.
check($lcnGate->ProcessLCNInput(80101,'033001'),'C1 LANG öffnet Garagentor via Command');
check(end($GLOBALS['simActions']) === [44228,true],'LCN Garagentor verwendet O1 AUF');
$GLOBALS['simVars'][44228]['value']=false;
check(!$lcnGate->ProcessLCNInput(80101,'033001'),'Doppelt empfangenes LCN LANG ignoriert');
check(count($GLOBALS['simActions']) === $actionCount+1,'Keine doppelte Shelly-Auslösung');
check(!$lcnGate->ProcessLCNInput(80101,'034002'),'C2 bei abgeschaltetem Kanal ohne Aktion');

$lcnGate->props['LCNSchiebeEnabled']=true;
check($lcnGate->ProcessLCNInput(80101,'034002'),'C2 KURZ startet Schiebetor ZU');
check(end($GLOBALS['simScriptRuns']) === 26612,'Schiebetor ZU über vorhandenes Skript');
check($lcnGate->ProcessLCNInput(80101,'034001'),'C2 LANG startet Schiebetor AUF');
check(end($GLOBALS['simScriptRuns']) === 12119,'Schiebetor AUF über vorhandenes Skript');
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'034000'),'C2 LOS hat standardmäßig keine Aktion');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'LOS führt keine Torbefehle aus');
$lcnGate->props['LCNSchiebeRelease']='STOP';
check($lcnGate->ProcessLCNInput(80101,'034000'),'LOS kann unabhängig STOP zugeordnet werden');
check(end($GLOBALS['simScriptRuns']) === 29055,'Schiebetor STOP über vorhandenes Skript');
$lcnGate->props['LCNSchiebeRelease']='NONE';

$lcnGate->props['LCNSchiebeCloseScript']=0;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'034002'),'Fehlendes Schiebetor-Skript sperrt Befehl');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'Keine Auslösung bei fehlendem Skript');
$lcnGate->props['LCNSchiebeCloseScript']=26612;

$lcnGate->props['LCNSchiebeKey']=1;
$oldActions=count($GLOBALS['simActions']);$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'033002'),'Kollision gleiche Taste blockiert beide Tore');
check(count($GLOBALS['simActions']) === $oldActions &&
    count($GLOBALS['simScriptRuns']) === $oldRuns,'Mehrdeutigkeit löst keine Tore aus');
$lcnGate->props['LCNSchiebeKey']=2;

$GLOBALS['simLCNParents'][80101]=123;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'034002'),'Host-Variable von falschem Modul wird abgewiesen');
check(count($GLOBALS['simScriptRuns']) === $oldRuns,'Kein Torbefehl bei falscher Modulzuordnung');
$GLOBALS['simLCNParents'][80101]=5011;
check(!$lcnGate->ProcessLCNInput(98765,'034002'),'Unbekannte Quellvariable abweisen');
check(!$lcnGate->ProcessLCNInput(80101,'123abc'),'Fehlerhaftes Telegramm abweisen');

$lcnGate->props['LCNSchiebeEnabled']=false;
$oldRuns=count($GLOBALS['simScriptRuns']);
check(!$lcnGate->ProcessLCNInput(80101,'034001'),'Schiebetor LCNTaste separat abschaltbar');
check(count($GLOBALS['simScriptRuns'])===$oldRuns,'Abschaltung tatsächlich wirksam');
$lcnGate->props['LCNSchiebeHostVariable']=0;
$lcnGate->ApplyChanges();
check(($lcnGate->messages[80101]??0) === VM_UPDATE,
    'Abo bleibt bei Freigabe nur einer von zwei gleichen Host-Quellen bestehen');
$lcnGate->props['LCNGarageHostVariable']=0;
$lcnGate->ApplyChanges();
check(!isset($lcnGate->messages[80101]),'Entfernte Host-Quelle wird abgemeldet');

echo "PASS $cnt Prüfungen, Testsimulation ohne echte Tor-/WLED-Hardware.\n";
