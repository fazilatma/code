import { connectionDiagnostics } from './connections.js';
import { getEnv, validSecret } from './env.js';
import { VAULT_KDF_ITERATIONS } from './vault.js';

type DiagnosticCheck={name:string;ok:boolean;severity:'error'|'warning'|'info';detail:string;data?:unknown};

/** Read-only installation diagnostics. No secret or connection value is returned. */
export async function runDiagnostics(){
  const env=getEnv(),checks:DiagnosticCheck[]=[],start=Date.now();
  const add=(name:string,ok:boolean,detail:string,severity:DiagnosticCheck['severity']='error',data?:unknown)=>checks.push({name,ok,severity,detail,...(data===undefined?{}:{data})});
  add('runtime',typeof crypto?.subtle?.deriveKey==='function'&&typeof fetch==='function','Web Crypto, PBKDF2 and Fetch are available.');
  add('vault-secret',validSecret(env.VAULT_SECRET),validSecret(env.VAULT_SECRET)?'VAULT_SECRET is configured with at least 8 characters.':'VAULT_SECRET is missing or shorter than 8 characters.');
  add('vault-kdf',VAULT_KDF_ITERATIONS<=100_000,`PBKDF2 iterations=${VAULT_KDF_ITERATIONS}; Cloudflare maximum=100000.`);
  add('d1-binding',Boolean(env.DB),env.DB?'D1 binding DB is present.':'D1 binding DB is missing.');
  add('queue-binding',Boolean(env.JOBS),env.JOBS?'Producer binding JOBS is present.':'Producer binding JOBS is missing.');
  add('dlq-binding',Boolean(env.JOBS_DLQ),env.JOBS_DLQ?'DLQ producer binding JOBS_DLQ is present.':'DLQ producer binding JOBS_DLQ is missing.');
  add('r2-disabled',!env.BACKUPS,!env.BACKUPS?'R2 is intentionally disabled; JSON download/restore remains available.':'R2 is bound although this installation is documented as no-R2.','warning');
  if(env.DB){
    try{const vault=await connectionDiagnostics(),iterations=Number(vault.iterations||VAULT_KDF_ITERATIONS);add('vault-envelope',!vault.encrypted||(vault.version===2&&iterations<=100_000),vault.encrypted?`Encrypted D1 envelope version=${vault.version}, iterations=${iterations}.`:'No D1 vault yet; environment fallback is active.');add('vault-decrypt',true,'Vault decrypt/normalization succeeded.','info',{source:vault.source,status:vault.status})}catch(error){add('vault-decrypt',false,msg(error));}
    try{const integrity=await env.DB.prepare('PRAGMA quick_check').first<Record<string,unknown>>(),value=String(integrity?.quick_check??Object.values(integrity||{})[0]??'');add('d1-integrity',value==='ok',value||'No quick_check result');}catch(error){add('d1-integrity',false,msg(error));}
    try{const row=await env.DB.prepare("SELECT count(*) AS n FROM sqlite_master WHERE type='table' AND name IN ('profiles','products','jobs','app_state','destination_map','category_learning','autoreply_log')").first<{n:number}>();add('d1-schema',Number(row?.n)===7,`${Number(row?.n)||0}/7 required tables found.`);}catch(error){add('d1-schema',false,msg(error));}
    try{const counts=await env.DB.prepare("SELECT (SELECT count(*) FROM profiles) profiles,(SELECT count(*) FROM products) products,(SELECT count(*) FROM jobs) jobs,(SELECT count(*) FROM jobs WHERE status IN ('queued','running')) active_jobs,(SELECT count(*) FROM jobs WHERE status='failed') failed_jobs,(SELECT count(*) FROM products p LEFT JOIN profiles r ON r.id=p.profile_id WHERE r.id IS NULL) orphan_products,(SELECT count(*) FROM destination_map m LEFT JOIN products p ON p.profile_id=m.profile_id AND p.source_key=m.source_key WHERE p.source_key IS NULL) orphan_maps").first<Record<string,number>>();const orphans=Number(counts?.orphan_products||0)+Number(counts?.orphan_maps||0);add('d1-relations',orphans===0,orphans?'Orphan D1 rows were found.':'No orphan product or destination-map rows found.',orphans?'warning':'info',counts);}catch(error){add('d1-relations',false,msg(error));}
    try{const stalled=await env.DB.prepare("SELECT count(*) AS n FROM jobs WHERE status='running' AND updated_at < datetime('now','-30 minutes')").first<{n:number}>();add('queue-stalled',Number(stalled?.n||0)===0,`${Number(stalled?.n)||0} running jobs have been inactive for more than 30 minutes.`,Number(stalled?.n||0)?'warning':'info');}catch(error){add('queue-stalled',false,msg(error));}
  }
  return{ok:checks.filter(c=>c.severity==='error').every(c=>c.ok),generatedAt:new Date().toISOString(),durationMs:Date.now()-start,checks,summary:{passed:checks.filter(c=>c.ok).length,failed:checks.filter(c=>!c.ok).length,warnings:checks.filter(c=>!c.ok&&c.severity==='warning').length}};
}
function msg(error:unknown){return error instanceof Error?error.message:String(error)}
