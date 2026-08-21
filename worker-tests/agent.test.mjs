import assert from 'node:assert/strict';
import {mkdtemp} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import test from 'node:test';
import {build} from 'esbuild';

const temporary=await mkdtemp(join(tmpdir(),'scraper4-agent-'));
await build({entryPoints:{agent:new URL('../worker-src/agent.ts',import.meta.url).pathname,ai:new URL('../worker-src/ai.ts',import.meta.url).pathname},bundle:true,format:'esm',platform:'browser',target:'es2022',outdir:temporary,entryNames:'[name]',outExtension:{'.js':'.mjs'}});
const agent=await import(pathToFileURL(join(temporary,'agent.mjs')));
const ai=await import(pathToFileURL(join(temporary,'ai.mjs')));

test('the free tool-calling model catalog is present, unique and clearly tagged',()=>{
  assert.ok(agent.AGENT_TOOL_MODELS.length>=5,'at least five curated tool-calling models');
  const ids=new Set(agent.AGENT_TOOL_MODELS.map(m=>m.id));
  assert.equal(ids.size,agent.AGENT_TOOL_MODELS.length,'model ids are unique');
  assert.ok(agent.AGENT_TOOL_MODELS.every(m=>m.name&&m.note),'every model has a name and a Persian note');
  assert.ok(agent.AGENT_TOOL_MODELS.filter(m=>m.free).length>=5,'the free models are listed');
  assert.ok(agent.AGENT_TOOL_MODELS.filter(m=>m.id!=='*configured').every(m=>m.toolCalling===true),'every curated model carries the toolCalling tag');
  assert.equal(agent.AGENT_TOOL_MODELS.find(m=>m.id==='*configured').toolCalling,false,'the configured-provider option is explicitly non-tagged');
  const leanstral=agent.AGENT_TOOL_MODELS.find(m=>m.id==='labs-leanstral-2603');
  assert.ok(leanstral&&leanstral.toolCalling===true,'labs-leanstral-2603 is listed as a tool-calling model');
  assert.equal(leanstral.free,false,'Leanstral is a paid lab model');
  const prism=agent.AGENT_TOOL_MODELS.find(m=>m.id==='Prism-ML/Ternary-Bonsai-27B');
  assert.ok(prism&&prism.toolCalling===true,'Together Prism (Ternary Bonsai 27B) is listed as a tool-calling model');
  assert.equal(prism.free,true,'Together Prism is on the free tier');
});

test('ready-made prompt templates exist, are unique and reference valid tools',()=>{
  assert.ok(agent.AGENT_PROMPT_TEMPLATES.length>=4,'at least four quick-start templates');
  const ids=new Set(agent.AGENT_PROMPT_TEMPLATES.map(t=>t.id));
  assert.equal(ids.size,agent.AGENT_PROMPT_TEMPLATES.length,'template ids are unique');
  const toolIds=new Set(agent.AGENT_TOOLS.map(t=>t.id));
  for(const template of agent.AGENT_PROMPT_TEMPLATES){
    assert.ok(template.name&&template.prompt&&template.prompt.length>20,'every template has a name and a real prompt');
    assert.ok(template.tools.length>0&&template.tools.every(tool=>toolIds.has(tool)),'template tools are valid');
    assert.ok(template.maxSteps>=2&&template.maxSteps<=12,'template maxSteps is in range');
  }
});

test('the tool catalog covers every requested site domain with unique ids',()=>{
  assert.ok(agent.AGENT_TOOLS.length>=8,'at least eight tools');
  const ids=new Set(agent.AGENT_TOOLS.map(t=>t.id));
  assert.equal(ids.size,agent.AGENT_TOOLS.length,'tool ids are unique');
  const names=agent.AGENT_TOOLS.map(t=>t.name).join(' ');
  assert.match(names,/وضعیت/);
  assert.match(names,/جست‌وجو/);
  assert.match(names,/تکراری/);
  assert.ok(agent.AGENT_TOOLS.every(t=>t.description&&t.description.length>10),'every tool has a useful description');
});

test('agentToolSchemas produces OpenAI-compatible function definitions with parameters',()=>{
  const schemas=agent.agentToolSchemas(['site_status','products_search']);
  assert.equal(schemas.length,2);
  assert.ok(schemas.every(s=>s.type==='function'&&s.function.name&&s.function.parameters));
  const products=schemas.find(s=>s.function.name==='products_search');
  assert.equal(products.function.parameters.type,'object');
  assert.ok(products.function.parameters.properties.q,'search tool requires a query argument');
});

test('tool-call JSON arguments parse without crashing, including invalid JSON',()=>{
  assert.deepEqual(ai.parseToolArguments('{"q":"عطر","limit":5}'),{q:'عطر',limit:5});
  assert.deepEqual(ai.parseToolArguments('not json'),{raw:'not json'});
  assert.deepEqual(ai.parseToolArguments(''),{});
});

test('parseAgentTurn extracts text and tool_calls from chat-completions bodies',()=>{
  const body={choices:[{message:{role:'assistant',content:'بررسی میکنم',tool_calls:[{id:'call_1',type:'function',function:{name:'site_status',arguments:'{}'}}]}}]};
  const turn=ai.parseAgentTurn(body);
  assert.equal(turn.text,'بررسی میکنم');
  assert.equal(turn.toolCalls.length,1);
  assert.equal(turn.toolCalls[0].name,'site_status');
  assert.deepEqual(turn.toolCalls[0].arguments,{});
  assert.deepEqual(ai.parseAgentTurn({choices:[{message:{content:'فقط متن'}}]}).toolCalls,[]);
  assert.equal(ai.parseAgentTurn({}).text,'');
});


