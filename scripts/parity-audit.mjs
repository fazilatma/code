import { readFile, writeFile } from 'node:fs/promises';
const php=await readFile('scraper4.php','utf8'),server=await readFile('render-src/server.ts','utf8'),dashboard=await readFile('render-src/dashboard.ts','utf8');
const normalized=php.replace(/'([^']*)'\s*\.\s*'([^']*)'/g,(_,a,b)=>`'${a}${b}'`);
const collect=(regex,text)=>[...text.matchAll(regex)].map(x=>x[1]);
const phpGet=new Set([...collect(/isset\(\$_GET\[['"]([^'"]+)/g,normalized),...collect(/!empty\(\$_GET\[['"]([^'"]+)/g,normalized)]);
const phpActions=new Set([...collect(/\$_POST\[['"]action['"]\]\s*\?\?\s*''\)\s*===\s*['"]([^'"]+)/g,normalized),...collect(/\$_POST\[['"]action['"]\]\s*===\s*['"]([^'"]+)/g,normalized)]);
const routes=[...server.matchAll(/app\.(get|post|put|delete)\('([^']+)'/g)].map(x=>`${x[1].toUpperCase()} ${x[2]}`);
const menuActions=[...new Set(collect(/data-ma=\\?"([^"\\]+)/g,dashboard))].sort();
const groups={
 profile:['profiles','load_profile','save_profile','delete_profile','all_profiles'],extraction:['test_selector','suggest_selectors','suggest_detail_selectors','gallery_test','gallery_suggest','detail_stream','extract_stop','extract_report','extract_queue_status'],
 importExport:['csv','excel','upload_import','process_import','export'],woo:['woo_categories','woo_stream','woo_dedup_stream','woo_queue_status','woo_stop'],basalam:['bsl_products','bsl_categories','basalam_stream','bsl_send_one','bsl_change_status','bsl_delete_product','bsl_find_duplicates','bsl_fix_cat','bsl_ai_category','bsl_rejected_cats','bsl_orders_list','bsl_chats_list'],
 ai:['ai_providers_status','ai_test_all','ai_test_start','ai_test_status','ai_test_stop','ai_candidates','ai_category','ai_probe'],maintenance:['recon','recon_status','bulk_edit','photo_fix','retire_run','suffix_report','remote_map'],automation:['ar_rules','ar_test','ar_run','ar_log','digest','notif_test','queue_watchdog'],deployment:['backup_export','backup_restore','backup_status','vc_check','vc_files','vc_branches','selftest']
};
const report={generatedAt:new Date().toISOString(),php:{getConditions:phpGet.size,postActions:phpActions.size},typescript:{routes:routes.length,menuActions:menuActions.length},routes,menuActions,featureGroups:Object.fromEntries(Object.entries(groups).map(([name,items])=>[name,{phpItems:items,coveredByDedicatedTsRoute:items.filter(item=>server.includes(item.replaceAll('_','-'))).length}]))};
await writeFile('parity-audit.json',JSON.stringify(report,null,2));
console.log(JSON.stringify(report,null,2));
