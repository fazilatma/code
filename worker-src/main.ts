import { app, scheduledTasks } from './app.js';
import { configureEnv, type Env } from './env.js';
import { ensureSchema, listQueuedJobs } from './db.js';
import { processJob } from './processor.js';
import { listQueuedBackgroundRuns, processBackgroundMessage } from './background.js';
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
          // Priority dispatch: each wake-up first tries the highest-priority
          // queued background run (task-manager drag order). If that run cannot
          // be claimed (another delivery is already processing it), the incoming
          // run gets its turn instead, and a displaced run's message is re-queued
          // so no run can ever starve while the queue drains.
          const queued=await listQueuedBackgroundRuns(1);
          let message=queued.length?queued[0]:item.body;
          const sameMessage=message.task===item.body.task&&message.runId===item.body.runId;
          let result=await processBackgroundMessage(message);
          if(result.outcome==='ignored'&&!sameMessage){
            result=await processBackgroundMessage(item.body);
            message=item.body;
          }
          console.log(JSON.stringify({event:'queue_background',task:message.task,runId:message.runId,result:result.outcome}));
          if(result.outcome==='continue'){
            if(env.JOBS)await env.JOBS.send(message,{delaySeconds:result.delaySeconds||1});
            else item.retry({delaySeconds:30});
          }else if(env.JOBS&&!sameMessage){
            // Keep the displaced run's message alive (short delay) until the
            // one-minute cron sweep can pick it up again.
            await env.JOBS.send(item.body,{delaySeconds:2});
          }
        }else{
          // Priority dispatch for queued jobs (task-manager drag order). The
          // displaced job's message is re-queued the same way so the queue can
          // never drain and stall a lower-priority job.
          const jobId=String('jobId' in item.body?item.body.jobId:'');
          const queued=await listQueuedJobs(1);
          let target=queued.length?queued[0].id:(jobId||'');
          let result=await processJob(target);
          if(result==='ignored'&&queued.length&&target!==jobId&&jobId){
            result=await processJob(jobId);
            target=jobId;
          }
          console.log(JSON.stringify({event:'queue_job',jobId,target,result}));
          if(result==='continue'){
            if(env.JOBS)await env.JOBS.send({task:'job',jobId:target},{delaySeconds:1});
            else item.retry({delaySeconds:30});
          }else if(env.JOBS&&target!==jobId&&jobId){
            await env.JOBS.send({task:'job',jobId},{delaySeconds:2});
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
