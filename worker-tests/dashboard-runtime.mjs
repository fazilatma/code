import assert from 'node:assert/strict';
import {parseHTML} from 'linkedom';

const base=process.env.DASHBOARD_URL||'http://127.0.0.1:8787';
const nativeFetch=globalThis.fetch;
const html=await (await nativeFetch(base+'/')).text();
const script=await (await nativeFetch(base+'/dashboard.js')).text();
const {window}=parseHTML(html);
const store=new Map();
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,value)=>store.set(key,String(value)),removeItem:key=>store.delete(key),clear:()=>store.clear()};
const browserFetch=(input,init)=>nativeFetch(new URL(typeof input==='string'?input:input.url,base),init);
for(const [key,value] of Object.entries({window,document:window.document,navigator:window.navigator,location:window.location,history:window.history,HTMLElement:window.HTMLElement,HTMLSelectElement:window.HTMLSelectElement,Event:window.Event,CustomEvent:window.CustomEvent,localStorage,alert:()=>{},confirm:()=>true,fetch:browserFetch}))Object.defineProperty(globalThis,key,{value,writable:true,configurable:true});
for(const [key,value] of Object.entries({fetch:browserFetch,localStorage,alert:()=>{},confirm:()=>true,FormData:globalThis.FormData,Blob:globalThis.Blob,File:globalThis.File,Headers:globalThis.Headers,Request:globalThis.Request,Response:globalThis.Response}))try{Object.defineProperty(window,key,{value,writable:true,configurable:true})}catch{}
window.HTMLElement.prototype.scrollIntoView=()=>{};
window.HTMLElement.prototype.focus=()=>{};
Object.defineProperty(window.HTMLSelectElement.prototype,'value',{configurable:true,get(){return this.querySelector('option[selected]')?.getAttribute('value')??this.querySelector('option')?.getAttribute('value')??''},set(value){for(const option of this.querySelectorAll('option')){if((option.getAttribute('value')??option.textContent)===String(value))option.setAttribute('selected','');else option.removeAttribute('selected')}}});
const failures=[];
process.on('unhandledRejection',error=>failures.push(error));
try{(0,eval)(script)}catch(error){failures.push(error)}
await new Promise(resolve=>setTimeout(resolve,900));
assert.equal(failures.length,0,failures.map(error=>error?.stack||String(error)).join('\n'));
for(const pane of ['home','settings','selector','products','destination','jobs'])assert.ok(document.getElementById('pane-'+pane),`pane ${pane}`);
const tabs=[...document.querySelectorAll('.main-tab')];
assert.deepEqual(tabs.map(tab=>tab.textContent.trim().replace(/[۰-۹]+$/,'')),['شروع','تنظیمات','سلکتورها','نتایج','ارسال','درون‌ریزی']);
for(const tab of tabs){tab.click();assert.ok(document.getElementById('pane-'+tab.dataset.tab).classList.contains('active'),`tab ${tab.dataset.tab} activates`)}
document.getElementById('homeManualMode').click();
assert.ok(document.getElementById('homeManualMode').classList.contains('active'));
assert.match(document.getElementById('homeScrape').textContent,/دستی/);
document.getElementById('homeAutoMode').click();
assert.ok(document.getElementById('homeAutoMode').classList.contains('active'));
assert.match(document.getElementById('homeScrape').textContent,/اتوماتیک/);
assert.ok(document.querySelectorAll('#pane-settings .settings-card').length>=3);
assert.ok(document.querySelector('#pane-destination .quick-send-card'));
assert.ok(document.querySelector('#pane-jobs .import-card'));
assert.notEqual(document.getElementById('dbState').textContent.trim(),'—');
console.log('dashboard runtime OK: startup, API bootstrap, six tabs, extraction mode controls, and redesigned panes');
