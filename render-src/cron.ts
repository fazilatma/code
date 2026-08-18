import { assertConfig } from './config.js';
import { enqueueDueProfiles, migrate, pool } from './db.js';

assertConfig();
await migrate();
const count = await enqueueDueProfiles();
console.log(JSON.stringify({ ok: true, enqueued: count, at: new Date().toISOString() }));
await pool.end();
