import { readFile, writeFile } from 'node:fs/promises';

const php=(await readFile('scraper4.php','utf8')).replace(/'([^']*)'\s*\.\s*'([^']*)'/g,(_,a,b)=>`'${a}${b}'`);
const collect=regex=>[...php.matchAll(regex)].map(match=>match[1]);
const get=[...new Set([...collect(/isset\(\$_GET\[['"]([^'"]+)/g),...collect(/!empty\(\$_GET\[['"]([^'"]+)/g)])].sort();
const post=[...new Set([...collect(/\$_POST\[['"]action['"]\]\s*\?\?\s*''\)\s*===\s*['"]([^'"]+)/g),...collect(/\$_POST\[['"]action['"]\]\s*===\s*['"]([^'"]+)/g)])].sort();
const all=[...get.map(key=>({phpMethod:'GET',key})),...post.map(key=>({phpMethod:'POST',key}))],entries=[];
const pending=new Map(all.map(item=>[`${item.phpMethod}:${item.key}`,item]));
function add(method,keys,section,status,workerMethod,workerRoute,adaptation){for(const key of keys.split(/\s+/).filter(Boolean)){const id=`${method}:${key}`,item=pending.get(id);if(!item)throw new Error(`Unknown or duplicate ${id}`);pending.delete(id);entries.push({...item,section,status,worker:{method:workerMethod,route:workerRoute},evidence:{file:'worker-src/app.ts',route:`${workerMethod} ${workerRoute}`},adaptation})}}
const op='Equivalent REST operation in the Worker; long-running work is persisted in D1 and dispatched through Cloudflare Queues.';
const ext='Requires the configured destination/account API; the Worker returns the upstream result without exposing credentials.';
const rest='PHP query/form dispatcher was replaced by a typed JSON REST route.';
const queue='PHP process/server queue controls are adapted to managed Cloudflare Queues, D1 job checkpoints, stop/retry/delete, Cron recovery and DLQ.';
const noR2='R2/remote backup controls are intentionally adapted to complete downloadable JSON backup and restore because this deployment is no-R2.';
const deploy='Filesystem/git deployment controls are adapted to immutable Cloudflare Worker versions, Workers Builds, deploy history and rollback.';

add('GET','action bg detailSelectors dry force_all full json keys now only_untested selectors send stream','request-shape','adapted','GET','/api/status',rest+' These legacy dispatcher modifiers are represented by route, JSON body, or job state rather than standalone query switches.');
add('GET','profiles all_profiles load_profile','profiles','operational','GET','/api/profiles',rest);
add('POST','save_profile','profiles','operational','POST','/api/profiles',rest);
add('POST','delete_profile','profiles','operational','DELETE','/api/profiles/:id',rest);
add('GET','sync sync_stream poll_bsl poll_extract poll_woo','jobs','adapted','GET','/api/jobs',queue);
add('GET','sync_status extract_queue_status woo_queue_status bsl_queue_status queues_overview','jobs','operational','GET','/api/jobs',queue);
add('GET','extract_stop woo_stop bsl_stop','jobs','operational','POST','/api/jobs/:id/stop',queue);
add('GET','extract_queue_delete woo_queue_delete bsl_queue_delete bsl_queue_cancel woo_queue_cancel','jobs','operational','DELETE','/api/jobs/:id',queue);
add('GET','extract_queue_clear_done woo_queue_clear_done bsl_queue_clear_done clear','jobs','operational','DELETE','/api/jobs',queue);
add('GET','bsl_queue_detail','jobs','operational','GET','/api/jobs/:id',queue);
add('GET','bsl_queue_pause bsl_queue_resume bsl_queue_mark_done bsl_queue_update_progress bsl_queue_save_products bsl_queue_add woo_queue_add woo_queue_save_products bsl_queue_start_next woo_queue_start_next bsl_queue_start_server bsl_queue_restart_server woo_queue_start_server','jobs','adapted','GET','/api/jobs',queue);
add('GET','queue_watchdog','jobs','operational','POST','/api/queue-watchdog',queue);

add('GET','test_selector gallery_test','scraping','operational','POST','/api/test-selector',op);
add('GET','suggest_selectors suggest_detail_selectors gallery_suggest','scraping','operational','POST','/api/suggest-selectors','The same endpoint supports list, detail, all, gallery and variation selector evidence.');
add('GET','detail_proxy detail_stream fullpage_inspect fetch_missing_stream visual_proxy image_proxy','scraping','adapted','POST','/api/source-test','PHP proxy/stream responses are adapted to bounded SSRF-hardened Worker fetches and Visual Selector tickets.');
add('GET','extract_report','scraping','operational','GET','/api/jobs/:id','Progress, counts, errors and checkpoint phase are stored in the D1 job record.');
add('GET','src_probe','scraping','operational','POST','/api/source-test',op);
add('GET','net_','scraping','adapted','GET','/api/connections','Direct Cloudflare fetch and Worker gateway mode are operational for source scraping and AI. DoH/manual DNS/SOCKS values remain transferable but are rejected explicitly because Workers fetch cannot implement those PHP transports.');

add('GET','export','transfer','operational','GET','/api/profiles/:id/export.csv',op);
add('POST','csv excel upload_import process_import','transfer','operational','POST','/api/profiles/:id/import','CSV/Excel-imported rows are normalized into the same D1 product format; JSON rows preserve variations.');
add('GET','backup_download backup_export backup_peek backup_run backup_status backup_remote_list','backup','adapted','GET','/api/backup',noR2);
add('POST','backup_run','backup','adapted','GET','/api/backup',noR2);
add('POST','backup_restore','backup','operational','POST','/api/restore',op);
add('POST','backup_save_cfg','backup','adapted','POST','/api/settings',noR2+' Browser downloads require no recurring backup configuration.');

add('GET','defaults cron_last cron_run','settings','adapted','GET','/api/settings','PHP file/cron metadata is represented by Worker settings and scheduled event state.');
add('POST','cron_run','settings','adapted','POST','/api/profiles/:id/scrape','Cron is a Cloudflare scheduled handler; this route provides the equivalent explicit run.');
add('POST','save_sync update_sync_state','profiles','operational','POST','/api/profiles','Sync target, interval and noExtract are persisted on the profile; run state lives in D1.');
add('POST','load_connections','connections','operational','GET','/api/connections',rest);
add('POST','save_connections','connections','operational','POST','/api/connections','Connections are AES-GCM encrypted using PBKDF2=100000 and VAULT_SECRET; secret values are never returned by diagnostics.');
add('POST','test_woo','connections','account-dependent','POST','/api/test-connection/:target',ext);
add('POST','test_basalam','connections','account-dependent','POST','/api/test-connection/:target',ext);
add('POST','test_ai','connections','account-dependent','POST','/api/test-connection/:target',ext);
add('POST','test_notif','connections','account-dependent','POST','/api/notifications/test',ext);
add('GET','notif_test notify suffix_notify bsl_notify_selected','notifications','account-dependent','POST','/api/notifications/test',ext);

add('GET','ai_candidates ai_candidates_category ai_candidates_reply ai_providers_status','ai','operational','GET','/api/ai/providers',op);
add('GET','ai_test_all ai_test_start ai_test_status ai_test_stop ai_probe','ai','adapted','POST','/api/ai/test-all','PHP background test lifecycle is adapted to a bounded Worker request returning all model results and leaderboard updates.');
add('GET','ai_category bsl_ai_category','ai','account-dependent','POST','/api/ai/call',ext);
add('POST','ai_candidates_save ai_import_providers ai_select ai_toggle_provider','ai','operational','POST','/api/connections','Provider, model, candidate and master selection are encrypted in the connection vault.');
add('POST','ai_test_one','ai','account-dependent','POST','/api/ai/call',ext);
add('POST','ai_vote','ai','operational','POST','/api/ai/vote',op);

add('GET','ar_chat ar_log ar_rules','autoreply','operational','GET','/api/autoreply/log',op);
add('GET','ar_test','autoreply','operational','POST','/api/autoreply/test',op);
add('GET','ar_run','autoreply','account-dependent','POST','/api/autoreply/run',ext);
add('POST','ar_save_rules','autoreply','operational','POST','/api/settings','Rules are persisted in D1 application state and are included in settings transfer.');
add('GET','digest','automation','operational','POST','/api/digest',op);

add('GET','catlearn','category','operational','GET','/api/category-learning',op);
add('GET','catlearn_bulk','category','operational','POST','/api/category-learning/record',op);
add('POST','catlearn_import','category','operational','POST','/api/settings-import','Category-learning data is imported from the PHP-compatible settings package.');

add('GET','dest_list bsl_products bsl_queue_get_products','destination','account-dependent','GET','/api/destination/:target/products',ext);
add('GET','bsl_status_overview','destination','account-dependent','GET','/api/destination/:target/overview',ext);
add('GET','bsl_find_duplicates','destination','account-dependent','GET','/api/destination/:target/duplicates',ext);
add('GET','bsl_change_status bsl_activate_batch','destination','account-dependent','POST','/api/destination/:target/:id/status',ext);
add('GET','bsl_delete_product bsl_delete_batch','destination','account-dependent','DELETE','/api/destination/:target/:id',ext);
add('GET','bsl_send_one','destination','account-dependent','POST','/api/products/:profileId/:sourceKey/sync/:target',ext);
add('GET','basalam_stream bsl_client_stream bsl_save_products','destination','account-dependent','POST','/api/profiles/:id/sync',ext);
add('POST','bsl_categories','destination','account-dependent','GET','/api/categories/:target',ext);
add('POST','woo_categories','destination','account-dependent','GET','/api/categories/:target',ext);
add('GET','woo_stream woo_save_products','destination','account-dependent','POST','/api/profiles/:id/sync',ext);
add('GET','bsl_orders_list','destination','account-dependent','GET','/api/basalam/orders',ext);
add('GET','bsl_chats_list','destination','account-dependent','GET','/api/basalam/chats',ext);
add('GET','bsl_rejected_cats bsl_fix_cat bsl_fix_ai_cat bsl_fix_ai_cat_batch bsl_master_fix bsl_download_ai_texts bsl_clear_temp','maintenance','adapted','POST','/api/maintenance/bulk/:target','Destination-specific PHP batch screens are consolidated into guarded maintenance reports and APPLY-confirmed bulk operations.');

add('GET','recon recon_result recon_status remote_map','maintenance','operational','POST','/api/maintenance/recon/:target',op);
add('GET','bulk_edit bulk_result bulk_status fix_price','maintenance','operational','POST','/api/maintenance/bulk/:target',op);
add('GET','photo_fix photo_status','maintenance','operational','POST','/api/maintenance/photo-fix',op);
add('GET','retire_run','maintenance','operational','POST','/api/maintenance/retire/:target',op);
add('GET','suffix_report suffix_result suffix_status','maintenance','adapted','POST','/api/maintenance/bulk/:target','Suffix work is a guarded bulk destination edit rather than a PHP background process.');
add('GET','woo_dedup_stream','maintenance','account-dependent','GET','/api/destination/:target/duplicates',ext);

add('GET','sec_check selftest','diagnostics','operational','GET','/api/selftest',op);
add('GET','whoami','diagnostics','adapted','GET','/health','Authentication was intentionally removed; health reports authenticationRequired=false without identity or secrets.');
add('GET','rp rp_base','diagnostics','adapted','GET','/api/debug','Runtime path checks are replaced by read-only Worker, binding, D1, queue and vault diagnostics.');

add('GET','vc_branches vc_check vc_deploy_info vc_files vc_settings','deployment','adapted','GET','/api/version',deploy);
add('GET','apply','request-shape','adapted','GET','/api/status','Destructive actions require explicit APPLY/DELETE/SEND confirmation in their JSON route; apply is not a standalone dispatcher.');

if(pending.size)throw new Error(`Unmapped: ${[...pending.keys()].join(', ')}`);
entries.sort((a,b)=>a.phpMethod.localeCompare(b.phpMethod)||a.key.localeCompare(b.key));
const manifest={schemaVersion:1,reference:{file:'scraper4.php',version:'9.80',extraction:'explicit $_GET/$_POST[action] dispatcher keys'},policy:{statuses:['operational','adapted','account-dependent'],missingAllowed:false},total:entries.length,entries};
await writeFile('parity-manifest.json',JSON.stringify(manifest,null,2)+'\n');
console.log(`wrote ${entries.length} mappings`);
