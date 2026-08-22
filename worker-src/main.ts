import { app, scheduledTasks } from './app.js';
import { configureEnv, type Env } from './env.js';
import { ensureSchema, listQueuedJobs } from './db.js';
import { processJob } from './processor.js';
import { processBackgroundMessage } from './background.js';
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
        if(item.body?.task==='ai-test'||item.body?.task==='category-all'||item.body?.task==='dedup'||item.body?.task==='agent'){
          const result=await processBackgroundMessage(item.body);
          console.log(JSON.stringify({event:'queue_background',task:item.body.task,runId:item.body.runId,result:result.outcome}));
          if(result.outcome==='continue'){
            if(env.JOBS)await env.JOBS.send(item.body,{delaySeconds:result.delaySeconds||1});
            else item.retry({delaySeconds:30});
          }
        }else{
          // Each wake-up processes the highest-priority queued job (task-manager
          // drag order) instead of blindly following FIFO message order. A job
          // displaced by a higher-priority one stays queued and is re-dispatched
          // by the next message or the one-minute cron sweep.
          const jobId=String('jobId' in item.body?item.body.jobId:'');
          const queued=await listQueuedJobs(1),target=queued.length?queued[0].id:(jobId||'');
          const result=await processJob(target);
          console.log(JSON.stringify({event:'queue_job',jobId,target,result}));
          if(result==='continue'){
            if(env.JOBS)await env.JOBS.send({task:'job',jobId:target},{delaySeconds:1});
            else item.retry({delaySeconds:30});
          }
        }
        item.ack();
      }catch(error){console.error('queue delivery failed',error);item.retry({delaySeconds:30})}
    }
  },
  async scheduled(_controller:ScheduledController,env:Env,ctx:ExecutionContext):Promise<void>{
    await scheduledTasks(env,promise=>ctx.waitUntil(promise));
  }
};
