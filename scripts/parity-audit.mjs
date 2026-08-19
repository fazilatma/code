import { access, readFile, writeFile } from 'node:fs/promises';

const phpRaw=await readFile('scraper4.php','utf8');
const php=phpRaw.replace(/'([^']*)'\s*\.\s*'([^']*)'/g,(_,a,b)=>`'${a}${b}'`);
const app=await readFile('worker-src/app.ts','utf8');
const scraper=await readFile('worker-src/scraper.ts','utf8');
const sync=await readFile('worker-src/sync.ts','utf8');
const vault=await readFile('worker-src/vault.ts','utf8');
const bundle=await readFile('scraper4.worker.js','utf8').catch(()=> '');
const manifest=JSON.parse(await readFile('parity-manifest.json','utf8'));
const collect=regex=>[...php.matchAll(regex)].map(match=>match[1]);
const phpGet=[...new Set([...collect(/isset\(\$_GET\[['"]([^'"]+)/g),...collect(/!empty\(\$_GET\[['"]([^'"]+)/g)])].sort();
const phpPost=[...new Set([...collect(/\$_POST\[['"]action['"]\]\s*\?\?\s*''\)\s*===\s*['"]([^'"]+)/g),...collect(/\$_POST\[['"]action['"]\]\s*===\s*['"]([^'"]+)/g)])].sort();
const expected=[...phpGet.map(key=>`GET:${key}`),...phpPost.map(key=>`POST:${key}`)].sort();
const routes=[...app.matchAll(/app\.(get|post|put|delete)\('([^']+)'/g)].map(match=>({method:match[1].toUpperCase(),route:match[2]}));
const routeSet=new Set(routes.map(route=>`${route.method} ${route.route}`));
const errors=[],warnings=[];
const fail=message=>errors.push(message),warn=message=>warnings.push(message);

if(phpGet.length!==150)fail(`Reference GET dispatcher count changed: expected 150, got ${phpGet.length}`);
if(phpPost.length!==28)fail(`Reference POST action count changed: expected 28, got ${phpPost.length}`);
if(manifest.schemaVersion!==1)fail(`Unsupported manifest schemaVersion ${manifest.schemaVersion}`);
if(manifest.total!==manifest.entries?.length)fail(`Manifest total=${manifest.total}, entries=${manifest.entries?.length}`);
const actual=(manifest.entries||[]).map(entry=>`${entry.phpMethod}:${entry.key}`).sort();
const duplicates=actual.filter((id,index)=>actual.indexOf(id)!==index);
if(duplicates.length)fail(`Duplicate manifest mappings: ${[...new Set(duplicates)].join(', ')}`);
const missing=expected.filter(id=>!actual.includes(id)),extra=actual.filter(id=>!expected.includes(id));
if(missing.length)fail(`Missing PHP dispatcher mappings: ${missing.join(', ')}`);
if(extra.length)fail(`Unknown manifest mappings: ${extra.join(', ')}`);
const allowedStatuses=new Set(manifest.policy?.statuses||[]);
for(const entry of manifest.entries||[]){
  const id=`${entry.phpMethod}:${entry.key}`;
  if(!allowedStatuses.has(entry.status))fail(`${id}: invalid status ${entry.status}`);
  if(entry.status==='missing')fail(`${id}: missing status is forbidden`);
  if(!entry.section||!entry.adaptation)fail(`${id}: section/adaptation is required`);
  const route=`${entry.worker?.method} ${entry.worker?.route}`;
  if(!routeSet.has(route))fail(`${id}: Worker route not found: ${route}`);
  if(entry.evidence?.route!==route)fail(`${id}: evidence route does not match Worker mapping`);
  try{await access(entry.evidence?.file)}catch{fail(`${id}: evidence file does not exist: ${entry.evidence?.file}`)}
}

const securityChecks={
  pbkdf2AtCloudflareLimit:/VAULT_KDF_ITERATIONS\s*=\s*100_000/.test(vault),
  sourceHasNo120000:!/(?:120_000|120000)/.test(vault),
  bundleHasNo120000:bundle? !/(?:120_000|120000)/.test(bundle):false,
  diagnosticsRoute:routeSet.has('GET /api/debug'),
  selectorSuggestionRoute:routeSet.has('POST /api/suggest-selectors'),
  variationExtraction:/extractVariations/.test(scraper)&&/variationGroups/.test(scraper),
  wooVariableProducts:/type:groups\.length\?'variable':'simple'/.test(sync)&&/syncWooVariations/.test(sync),
  phpSettingsImport:routeSet.has('POST /api/settings-import'),
  noAuthentication:/authenticationRequired:false/.test(app)
};
for(const [name,ok] of Object.entries(securityChecks))if(!ok)fail(`Feature/security check failed: ${name}`);
if(!bundle)warn('Production bundle was not present; run npm run worker:build before the audit.');

const byStatus=Object.fromEntries([...allowedStatuses].map(status=>[status,manifest.entries.filter(entry=>entry.status===status).length]));
const bySection={};for(const entry of manifest.entries)bySection[entry.section]=(bySection[entry.section]||0)+1;
const report={
  generatedAt:new Date().toISOString(),ok:errors.length===0,
  reference:{file:'scraper4.php',version:'9.80',getDispatchers:phpGet.length,postActions:phpPost.length,total:expected.length},
  worker:{source:'worker-src',routes:routes.length,bundlePresent:Boolean(bundle)},
  manifest:{file:'parity-manifest.json',mappings:manifest.entries.length,uniqueMappings:new Set(actual).size,missing:missing.length,extra:extra.length,byStatus,bySection},
  securityChecks,errors,warnings,mappings:manifest.entries
};
await writeFile('parity-audit.json',JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify({...report,mappings:`${manifest.entries.length} entries written to parity-audit.json`},null,2));
if(errors.length)process.exitCode=1;
