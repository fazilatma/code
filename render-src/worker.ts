import { assertConfig, config } from './config.js';
import { migrate, pool } from './db.js';
import { requestWorkerStop, workerLoop } from './processor.js';

assertConfig();
await migrate();
process.on('SIGTERM', () => requestWorkerStop());
process.on('SIGINT', () => requestWorkerStop());
await workerLoop(config.workerPollMs);
await pool.end();
