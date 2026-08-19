import { assertConfig } from './config.js';
import { automationTick } from './automation.js';
import { enqueueDueProfiles, migrate, pool } from './db.js';

assertConfig();
await migrate();
const count=await enqueueDueProfiles(),automation=await automationTick();
console.log(JSON.stringify({ok:true,enqueued:count,automation,at:new Date().toISOString()}));
await pool.end();
