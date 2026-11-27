'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../JVGaragentor/module.html'), 'utf8');
const found = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.equal(found.length, 1, 'Genau ein Kachelscript');
let fakeTime = 1_800_000_000_000;
let checks = 0;
const classes = new Set();
const pos = {classList:{add(...x){x.forEach(y=>classes.add(y))}, remove(...x){x.forEach(y=>classes.delete(y))}},style:{setProperty(n,v){pos[n]=v}}};
const stateClasses = new Set();
const state = {textContent:'',classList:{toggle(name,on){if(on)stateClasses.add(name);else stateClasses.delete(name)}}};
const note = {textContent:''};
const buttons = ['AUF','STOP','ZU'].map(cmd=>({disabled:true,dataset:{jvgCommand:cmd}, addEventListener(type,handler){this[type]=handler}}));
const root = {querySelectorAll(){return buttons},querySelector(sel){return sel === '.jvg-position'?pos:sel === '.jvg-state'?state:sel === '.jvg-note'?note:null}};
const rootDoc={getElementById(id){return id==='jvg-root'?root:null}};
let tick;
const ctx = {
  document: rootDoc, window:{}, Date:{now:()=>fakeTime},
  setInterval(fn,ms){assert.equal(ms,250);tick=fn},
  console, requestAction(){}, Number, Math, JSON, Set
};
const initial = {position:'GESTOPPT', signal:0,effectUntil:0,blinkPeriodMs:1600,enabled:false,online:true,ready:false,warning:''};
const src = found[0][1].replace('__JV_INITIAL__', JSON.stringify(initial));
vm.runInNewContext(src, ctx, {filename:'module.html:<script>'});
function check(condition,label){assert.ok(condition,label);checks++}
function push(signal,position='GESTOPPT',expires=100,flags={}){
  ctx.window.handleMessage(JSON.stringify({position,signal,effectUntil:Math.floor(fakeTime/1000)+expires,blinkPeriodMs:1600,enabled:true,online:true,ready:true,warning:'',...flags}));
}
check(typeof tick==='function','Browser-Timer registriert');
check(buttons.every(b=>b.disabled), 'Initialzustand gesperrt');
push(1,'LÄUFT AUF');
check(classes.has('jvg-yellow-flash'),'Auf-Fahrt: gelb blinkend');
check(state.textContent==='LÄUFT\nAUF','Kachel zeigt LÄUFT AUF');
check(stateClasses.has('jvg-state-moving') && !stateClasses.has('jvg-state-long'),'LÄUFT AUF verwendet zweizeilige Schriftgröße');
check(buttons.every(b=>!b.disabled),'Freigabe der Tasten');
push(2,'AUF',10);
check(classes.has('jvg-green')&&!classes.has('jvg-yellow-flash'),'Endlage AUF: grün statisch');
check(!stateClasses.has('jvg-state-moving') && !stateClasses.has('jvg-state-long'),'AUF nutzt die große Standardschrift');
fakeTime += 10500;
tick();
check(classes.size===0 && state.textContent==='AUF','Nach zehn Sekunden Farbe aus, Text bleibt AUF');
push(3,'LÄUFT ZU');
check(classes.has('jvg-red-flash'),'Zu-Fahrt: rot blinkend');
push(4,'ZU',5);
check(classes.has('jvg-red'),'Endlage ZU: rot statisch');
fakeTime += 5500;
tick();
check(classes.size===0 && state.textContent==='ZU','Nach fünf Sekunden rot aus, ZU bleibt');
push(1,'LÄUFT AUF',120);
fakeTime += 121000;
tick();
check(classes.size===0,'Timeout-Schutz der Kachel bei fehlender Symcon-Meldung');
push(3,'LÄUFT ZU',10);
push(0,'GESTOPPT',0);
check(classes.size===0 && state.textContent==='GESTOPPT','STOP stellt Farbe sofort ab');
check(stateClasses.has('jvg-state-long') && !stateClasses.has('jvg-state-moving'),'GESTOPPT verwendet schmale Einzeilen-Schriftgröße');
check(html.includes('font-size:min(15cqw,54px)') && html.includes('min-width:0;flex:0 1 100%'), 'Schrift richtet sich nach Breite der rechten Positionsspalte');
push(9,'AUF');
check(classes.size===0,'Ungültiger Signalwert führt nicht zu Farbe');
push(2,'AUF',10,{online:false});
check(buttons.every(b=>b.disabled),'Offline sperrt Tasten');
push(2,'AUF',10,{ready:false});
check(buttons.find(b=>b.dataset.jvgCommand==='STOP').disabled===false,'STOP bleibt bei Konfigurationssperre bedienbar');
check(buttons.find(b=>b.dataset.jvgCommand==='AUF').disabled===true,'Fahrbefehle bleiben gesperrt');
check(html.includes('@media (prefers-reduced-motion: reduce)'),'Weniger Bewegung für sensible Nutzer');
check(html.includes('jvg-yellow') && html.includes('jvg-red'),'Beide sanften CSS-Blinkanimationen vorhanden');
// The native iOS button does not participate in icon sizing/centering.
check((html.match(/class="jvg-icon" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet"/g)||[]).length===3,'Alle drei Icons mit quadratischem, zentriertem SVG-ViewBox');
check(html.includes('position:absolute;inset:0;width:100%;height:100%;border:0')&&html.includes('-webkit-appearance:none;appearance:none'),'WebKit: Button füllt Karte ohne natives Layout');
check(html.includes('.jvg-control .jvg-icon {position:absolute;display:block;left:9%;top:12%;width:82%;height:82%'),'Icon unabhängig vom Button-Inhaltslayout');
check(!html.includes('cqh')&&!html.includes('aspect-ratio:1')&&!html.includes('jvg-circle'),'Keine problematische intrinsische Iconhöhe / Container-Höheneinheit');
check((html.match(/class="jvg-icon-bg" cx="50" cy="50" r="50"/g)||[]).length===3,'Kreise komplett innerhalb der SVG-ViewBox');
check(html.includes('.jvg-stop .jvg-icon {left:30.65%;top:22%;width:38.7%;height:68%}'),'STOP behält kompakte Größe');
push(1,'LÄUFT AUF',4);
check(classes.has('jvg-yellow-flash'),'Actual direction: short renewable deadline lights tile');
fakeTime+=3000;
push(1,'LÄUFT AUF',4);
fakeTime+=2000;tick();
check(classes.has('jvg-yellow-flash'),'Fresh server heartbeat extends active signal');
fakeTime+=2500;tick();
check(classes.size===0,'Lost heartbeat removes stale motion color');
push(3,'LÄUFT ZU',4);
check(classes.has('jvg-red-flash'),'Actual closing uses renewable deadline as well');
console.log('PASS '+checks+' HTML/JS-Visualisierungstests');
