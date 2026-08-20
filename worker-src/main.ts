import { app, scheduledTasks } from './app.js';
import { configureEnv, type Env } from './env.js';
import { ensureSchema } from './db.js';
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
        if(item.body?.task==='ai-test'||item.body?.task==='category-all'){
          const result=await processBackgroundMessage(item.body);
          console.log(JSON.stringify({event:'queue_background',task:item.body.task,runId:item.body.runId,result:result.outcome}));
          if(result.outcome==='continue'){
            if(env.JOBS)await env.JOBS.send(item.body,{delaySeconds:result.delaySeconds||1});
            else item.retry({delaySeconds:30});
          }
        }else{
          const jobId=String('jobId' in item.body?item.body.jobId:'');const result=await processJob(jobId);
          console.log(JSON.stringify({event:'queue_job',jobId,result}));
          if(result==='continue'){
            if(env.JOBS)await env.JOBS.send({task:'job',jobId},{delaySeconds:1});
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
