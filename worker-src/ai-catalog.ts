export type AiProviderCatalog={
  catalogVersion?:number;
  providers?:Array<{id:string;name?:string;baseUrl?:string;apiKey?:string;models?:string[];reasoningModels?:string[];enabled?:boolean}>;
};

/**
 * Every model carrying the explicit “Text-to-text” capability on Mistral's
 * official API pricing page (reviewed 2026-08-20). Some of these models use a
 * dedicated endpoint; see MISTRAL_MODEL_ENDPOINTS rather than assuming chat.
 */
export const MISTRAL_TEXT_TO_TEXT_MODELS=[
  'mistral-medium-latest',
  'mistral-small-latest',
  'mistral-large-latest',
  'zai-glm-5-2',
  'mistral-ocr-latest',
  'voxtral-small-latest',
  'codestral-latest',
  'ministral-3b-latest',
  'ministral-8b-latest',
  'ministral-14b-latest',
  'mistral-embed'
] as const;

export const MISTRAL_MODEL_ENDPOINTS:Record<string,'chat-completions'|'ocr'|'embeddings'>={
  'mistral-ocr-latest':'ocr',
  'mistral-embed':'embeddings'
};

/** The previous one-time catalog is kept as migration history. */
export const MISTRAL_CATALOG_V1_MODELS=[
  'mistral-medium-latest',
  'mistral-small-latest',
  'mistral-large-latest',
  'zai-glm-5-2',
  'voxtral-small-latest',
  'codestral-latest',
  'labs-leanstral-2603',
  'ministral-3b-latest',
  'ministral-8b-latest',
  'ministral-14b-latest'
] as const;

export const MISTRAL_CATALOG_VERSION=3;
const MISTRAL_CATALOG_V2_ADDITIONS=['mistral-ocr-latest','mistral-embed'] as const;
/** Leanstral 2603 retired 2026-06-30; replaced by the free Labs model labs-leanstral-1-5. */
export const MISTRAL_CATALOG_V3_ADDITIONS=['labs-leanstral-1-5'] as const;

function mistralProvider(ai:AiProviderCatalog){return ai.providers?.find(provider=>provider.id==='mistral'||/api\.mistral\.ai/i.test(String(provider.baseUrl||'')))}
function appendModels(provider:NonNullable<AiProviderCatalog['providers']>[number],models:readonly string[]){provider.models=[...new Set([...(Array.isArray(provider.models)?provider.models.map(String):[]),...models])]}

/**
 * Applies each catalog revision exactly once. Existing names, keys, URLs,
 * enabled flags, custom models, candidates and master choices are untouched;
 * a model deleted after a completed revision is therefore not re-added.
 */
export function upgradeAiProviderCatalog(ai:AiProviderCatalog):boolean{
  ai.providers=Array.isArray(ai.providers)?ai.providers:[];
  let version=Math.max(0,Math.trunc(Number(ai.catalogVersion)||0)),changed=false,provider=mistralProvider(ai);
  if(!provider){
    provider={id:'mistral',name:'Mistral AI',baseUrl:'https://api.mistral.ai/v1',apiKey:'',models:[],reasoningModels:[],enabled:false};
    ai.providers.push(provider);changed=true;
  }
  if(version<1){appendModels(provider,MISTRAL_CATALOG_V1_MODELS);version=1;changed=true}
  if(version<2){appendModels(provider,MISTRAL_CATALOG_V2_ADDITIONS);version=2;changed=true}
  if(version<3){appendModels(provider,MISTRAL_CATALOG_V3_ADDITIONS);version=3;changed=true}
  if(ai.catalogVersion!==version){ai.catalogVersion=version;changed=true}
  return changed;
}
