export const AI_PROVIDER_CATALOG_VERSION=1;

/**
 * Models listed on Mistral's API pricing page that are compatible with this
 * app's text/chat-completions workflow. OCR, embeddings, moderation, TTS, and
 * transcription-only endpoints are intentionally excluded.
 * Source: https://mistral.ai/pricing/api/ (checked 2026-08-20).
 */
export const MISTRAL_CHAT_MODELS=[
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

type AiCatalogTarget={catalogVersion?:number;providers:Array<{id:string;name:string;baseUrl:string;apiKey:string;models:string[];enabled:boolean}>};

/** Applies each catalog release once, so users can still edit/remove models afterwards. */
export function upgradeAiProviderCatalog(ai:AiCatalogTarget):boolean{
  if(Number(ai.catalogVersion||0)>=AI_PROVIDER_CATALOG_VERSION)return false;
  let provider=ai.providers.find(item=>String(item.id).toLowerCase()==='mistral'||/api\.mistral\.ai/i.test(String(item.baseUrl||'')));
  if(!provider){
    provider={id:'mistral',name:'Mistral AI',baseUrl:'https://api.mistral.ai/v1',apiKey:'',models:[],enabled:false};
    ai.providers.push(provider);
  }
  provider.name=provider.name||'Mistral AI';
  provider.baseUrl=String(provider.baseUrl||'https://api.mistral.ai/v1').replace(/\/$/,'');
  provider.models=[...new Set([...(Array.isArray(provider.models)?provider.models.map(String):[]),...MISTRAL_CHAT_MODELS])];
  ai.catalogVersion=AI_PROVIDER_CATALOG_VERSION;
  return true;
}
