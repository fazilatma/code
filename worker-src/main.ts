import { app, scheduledTasks } from './app.js';
import { configureEnv, type Env } from './env.js';
import { ensureSchema } from './db.js';
import { processJob } from './processor.js';
import type { JobMessage } from './types.js';

type ExecutionContext={waitUntil(promise:Promise<unknown>):void};
type ScheduledController={cron:string;scheduledTime:number};
type QueueMessage<T>={body:T;ack():void;retry(options?:{delaySeconds?:number}):void};
type MessageBatch<T>={messages:Array<QueueMessage<T>>;queue:string};

export default {
  fetch: app.fetch,
  async queue(batch:MessageBatch<JobMessage>,env:Env,ctx:ExecutionContext):Promise<void>{
    configureEnv(env);await ensureSchema(env.DB);
    for(const item of batch.messages){
      try{
        const result=await processJob(String(item.body?.jobId||''));
        console.log(JSON.stringify({event:'queue_job',jobId:item.body?.jobId,result}));
        if(result==='continue'){
          if(env.JOBS)await env.JOBS.send({jobId:item.body.jobId},{delaySeconds:1});
          else item.retry({delaySeconds:30});
        }
        item.ack();
      }catch(error){console.error('queue delivery failed',error);item.retry({delaySeconds:30})}
    }
  },
  async scheduled(_controller:ScheduledController,env:Env,ctx:ExecutionContext):Promise<void>{
    await scheduledTasks(env,promise=>ctx.waitUntil(promise));
  }
};
