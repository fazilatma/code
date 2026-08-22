/**
 * Complete Cloudflare Workers AI model catalog (84 models) — verified against
 * https://developers.cloudflare.com/workers-ai/models/ on 2026-08-22.
 * Only the `@cf/...` hosted identifiers are listed (external HF models are
 * reachable via `@hf/...` and are not enumerated here).
 */
export type WorkersAiModel = {
  /** API identifier, e.g. "@cf/meta/llama-4-scout-17b-16e-instruct" */
  id: string;
  /** Display name from the official catalog */
  name: string;
  /** Author / vendor */
  author: string;
  /** Task type: Text Generation, Text Embeddings, Text-to-Image, ... */
  task: string;
  /** Official capability tags */
  tags: string[];
  /** "deprecated" if marked deprecated in the catalog */
  status?: 'deprecated';
};

export const WORKERS_AI_MODELS: WorkersAiModel[] = [
  {id:'@cf/deepgram/aura-1',name:'Aura 1',author:'Deepgram',task:'Text-to-Speech',tags:['Cloudflare-hosted','Batch','Partner','Real-time']},
  {id:'@cf/deepgram/aura-2-en',name:'Aura 2 EN',author:'Deepgram',task:'Text-to-Speech',tags:['Cloudflare-hosted','Batch','Partner','Real-time']},
  {id:'@cf/deepgram/aura-2-es',name:'Aura 2 ES',author:'Deepgram',task:'Text-to-Speech',tags:['Cloudflare-hosted','Batch','Partner','Real-time']},
  {id:'@cf/meta/bart-large-cnn',name:'BART Large CNN',author:'Meta',task:'Summarization',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/baai/bge-base-en-v1.5',name:'BGE Base EN v1.5',author:'BAAI',task:'Text Embeddings',tags:['Cloudflare-hosted','Batch']},
  {id:'@cf/baai/bge-large-en-v1.5',name:'BGE Large EN v1.5',author:'BAAI',task:'Text Embeddings',tags:['Cloudflare-hosted','Batch']},
  {id:'@cf/baai/bge-m3',name:'BGE M3',author:'BAAI',task:'Text Embeddings',tags:['Cloudflare-hosted']},
  {id:'@cf/baai/bge-reranker-base',name:'BGE Reranker Base',author:'BAAI',task:'Text Classification',tags:['Cloudflare-hosted']},
  {id:'@cf/baai/bge-small-en-v1.5',name:'BGE Small EN v1.5',author:'BAAI',task:'Text Embeddings',tags:['Cloudflare-hosted','Batch']},
  {id:'@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',name:'DeepSeek R1 Distill Qwen 32B',author:'DeepSeek',task:'Text Generation',tags:['Cloudflare-hosted','Reasoning']},
  {id:'@cf/deepseek-ai/deepseek-v4-flash-0731',name:'DeepSeek V4 Flash 0731',author:'DeepSeek',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/deepseek-ai/deepseek-v4-pro-0813',name:'DeepSeek V4 Pro 0813',author:'DeepSeek',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/meta/detr-resnet-50',name:'DETR ResNet 50',author:'Meta',task:'Object Detection',tags:['Cloudflare-hosted']},
  {id:'@cf/huggingface/distilbert-sst-2-int8',name:'DistilBERT SST-2 INT8',author:'Hugging Face',task:'Text Classification',tags:['Cloudflare-hosted']},
  {id:'@cf/lykon/dreamshaper-8-lcm',name:'Dreamshaper 8 LCM',author:'Lykon',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/google/embeddinggemma-300m',name:'EmbeddingGemma 300M',author:'Google',task:'Text Embeddings',tags:['Cloudflare-hosted']},
  {id:'@cf/deepgram/flux',name:'Flux',author:'Deepgram',task:'Automatic Speech Recognition',tags:['Cloudflare-hosted','Partner','Real-time']},
  {id:'@cf/blackforestlabs/flux-1-schnell',name:'FLUX.1 Schnell',author:'Black Forest Labs',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/blackforestlabs/flux-2-dev',name:'FLUX.2 Dev',author:'Black Forest Labs',task:'Text-to-Image',tags:['Cloudflare-hosted','Partner']},
  {id:'@cf/blackforestlabs/flux-2-klein-4b',name:'FLUX.2 Klein 4B',author:'Black Forest Labs',task:'Text-to-Image',tags:['Cloudflare-hosted','Partner']},
  {id:'@cf/blackforestlabs/flux-2-klein-9b',name:'FLUX.2 Klein 9B',author:'Black Forest Labs',task:'Text-to-Image',tags:['Cloudflare-hosted','Partner']},
  {id:'@cf/google/gemma-2b-it-lora',name:'Gemma 2B IT LoRA',author:'Google',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/google/gemma-3-12b-it',name:'Gemma 3 12B IT',author:'Google',task:'Text Generation',tags:['Cloudflare-hosted','LoRA'],status:'deprecated'},
  {id:'@cf/google/gemma-4-26b-a4b-it',name:'Gemma 4 26B A4B IT',author:'Google',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning','Vision']},
  {id:'@cf/google/gemma-7b-it',name:'Gemma 7B IT',author:'Google',task:'Text Generation',tags:['Cloudflare-hosted','LoRA'],status:'deprecated'},
  {id:'@cf/google/gemma-7b-it-lora',name:'Gemma 7B IT LoRA',author:'Google',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/aisingapore/gemma-sea-lion-v4-27b-it',name:'Gemma SEA-LION V4 27B IT',author:'AI Singapore',task:'Text Generation',tags:['Cloudflare-hosted']},
  {id:'@cf/zai-org/glm-4.7-flash',name:'GLM-4.7 Flash',author:'Zhipu AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/zai-org/glm-5.2',name:'GLM-5.2',author:'Zhipu AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/openai/gpt-oss-120b',name:'GPT-OSS 120B',author:'OpenAI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/openai/gpt-oss-20b',name:'GPT-OSS 20B',author:'OpenAI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/ibm/granite-4.0-h-micro',name:'Granite 4.0 H Micro',author:'IBM',task:'Text Generation',tags:['Cloudflare-hosted','Function calling']},
  {id:'@hf/nousresearch/hermes-2-pro-mistral-7b',name:'Hermes 2 Pro Mistral 7B',author:'Nous Research',task:'Text Generation',tags:['Cloudflare-hosted','Function calling'],status:'deprecated'},
  {id:'@cf/ai4bharat/indictrans2-en-indic-1B',name:'IndicTrans2 EN-Indic 1B',author:'AI4Bharat',task:'Translation',tags:['Cloudflare-hosted']},
  {id:'@cf/moonshotai/kimi-k2.5',name:'Kimi K2.5',author:'Moonshot AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning','Vision'],status:'deprecated'},
  {id:'@cf/moonshotai/kimi-k2.6',name:'Kimi K2.6',author:'Moonshot AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning','Vision']},
  {id:'@cf/moonshotai/kimi-k2.7-code',name:'Kimi K2.7 Code',author:'Moonshot AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning','Vision']},
  {id:'@cf/meta/llama-2-7b-chat-fp16',name:'Llama 2 7B Chat FP16',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-2-7b-chat-hf-lora',name:'Llama 2 7B Chat HF LoRA',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/meta/llama-2-7b-chat-int8',name:'Llama 2 7B Chat INT8',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3-8b-instruct',name:'Llama 3 8B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3-8b-instruct-awq',name:'Llama 3 8B Instruct AWQ',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3.1-70b-instruct',name:'Llama 3.1 70B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3.1-8b-instruct',name:'Llama 3.1 8B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3.1-8b-instruct-awq',name:'Llama 3.1 8B Instruct AWQ',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/meta/llama-3.1-8b-instruct-fast',name:'Llama 3.1 8B Instruct Fast',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted']},
  {id:'@cf/meta/llama-3.1-8b-instruct-fp8',name:'Llama 3.1 8B Instruct FP8',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted']},
  {id:'@cf/meta/llama-3.2-11b-vision-instruct',name:'Llama 3.2 11B Vision Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted','LoRA','Vision']},
  {id:'@cf/meta/llama-3.2-1b-instruct',name:'Llama 3.2 1B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted']},
  {id:'@cf/meta/llama-3.2-3b-instruct',name:'Llama 3.2 3B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted']},
  {id:'@cf/meta/llama-3.3-70b-instruct-fp8-fast',name:'Llama 3.3 70B Instruct FP8 Fast',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted','Batch','Function calling']},
  {id:'@cf/meta/llama-4-scout-17b-16e-instruct',name:'Llama 4 Scout 17B 16E Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted','Batch','Function calling','Vision']},
  {id:'@cf/meta/llama-guard-3-8b',name:'Llama Guard 3 8B',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/llava-hf/llava-1.5-7b-hf',name:'LLaVA 1.5 7B HF',author:'LLaVA HF',task:'Image-to-Text',tags:['Cloudflare-hosted']},
  {id:'@cf/leonardo/lucid-origin',name:'Lucid Origin',author:'Leonardo',task:'Text-to-Image',tags:['Cloudflare-hosted','Partner']},
  {id:'@cf/meta/m2m100-1.2b',name:'M2M100 1.2B',author:'Meta',task:'Translation',tags:['Cloudflare-hosted','Batch']},
  {id:'@cf/myshell/melotts',name:'MeloTTS',author:'MyShell',task:'Text-to-Speech',tags:['Cloudflare-hosted']},
  {id:'@cf/meta/meta-llama-3-8b-instruct',name:'Meta Llama 3 8B Instruct',author:'Meta',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/mistralai/mistral-7b-instruct-v0.1',name:'Mistral 7B Instruct v0.1',author:'Mistral AI',task:'Text Generation',tags:['Cloudflare-hosted','LoRA'],status:'deprecated'},
  {id:'@cf/mistralai/mistral-7b-instruct-v0.2',name:'Mistral 7B Instruct v0.2',author:'Mistral AI',task:'Text Generation',tags:['Cloudflare-hosted','LoRA'],status:'deprecated'},
  {id:'@cf/mistralai/mistral-7b-instruct-v0.2-lora',name:'Mistral 7B Instruct v0.2 LoRA',author:'Mistral AI',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/mistralai/mistral-small-3.1-24b-instruct',name:'Mistral Small 3.1 24B Instruct',author:'Mistral AI',task:'Text Generation',tags:['Cloudflare-hosted','Function calling']},
  {id:'@cf/moondream/moondream3.1-9B-A2B',name:'Moondream 3.1 9B A2B',author:'Moondream',task:'Image-to-Text',tags:['Cloudflare-hosted','Vision']},
  {id:'@cf/nvidia/nemotron-3-120b-a12b',name:'Nemotron 3 120B A12B',author:'NVIDIA',task:'Text Generation',tags:['Cloudflare-hosted','Function calling','Reasoning']},
  {id:'@cf/deepgram/nova-3',name:'Nova 3',author:'Deepgram',task:'Automatic Speech Recognition',tags:['Cloudflare-hosted','Batch','Partner','Real-time']},
  {id:'@cf/microsoft/phi-2',name:'Phi-2',author:'Microsoft',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/leonardo/phoenix-1.0',name:'Phoenix 1.0',author:'Leonardo',task:'Text-to-Image',tags:['Cloudflare-hosted','Partner']},
  {id:'@cf/pfnet/plamo-embedding-1b',name:'PLaMo Embedding 1B',author:'Preferred Networks',task:'Text Embeddings',tags:['Cloudflare-hosted']},
  {id:'@cf/qwen/qwen2.5-coder-32b-instruct',name:'Qwen2.5 Coder 32B Instruct',author:'Qwen',task:'Text Generation',tags:['Cloudflare-hosted','LoRA']},
  {id:'@cf/qwen/qwen3-30b-a3b-fp8',name:'Qwen3 30B A3B FP8',author:'Qwen',task:'Text Generation',tags:['Cloudflare-hosted','Batch','Function calling','Reasoning']},
  {id:'@cf/qwen/qwen3-embedding-0.6b',name:'Qwen3 Embedding 0.6B',author:'Qwen',task:'Text Embeddings',tags:['Cloudflare-hosted']},
  {id:'@cf/qwen/qwen3.8-27b',name:'Qwen 3.8 27B',author:'Qwen',task:'Image-Text-to-Text',tags:['Cloudflare-hosted','Function calling','Reasoning','Vision']},
  {id:'@cf/qwen/qwq-32b',name:'QwQ 32B',author:'Qwen',task:'Text Generation',tags:['Cloudflare-hosted','LoRA','Reasoning']},
  {id:'@cf/microsoft/resnet-50',name:'ResNet 50',author:'Microsoft',task:'Image Classification',tags:['Cloudflare-hosted']},
  {id:'@cf/pipecat/smart-turn-v2',name:'Smart Turn V2',author:'Pipecat',task:'Voice Activity Detection',tags:['Cloudflare-hosted','Batch','Real-time']},
  {id:'@cf/defog/sqlcoder-7b-2',name:'SQLCoder 7B 2',author:'Defog',task:'Text Generation',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/runwayml/stable-diffusion-v1-5-img2img',name:'Stable Diffusion v1.5 Img2Img',author:'RunwayML',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/runwayml/stable-diffusion-v1-5-inpainting',name:'Stable Diffusion v1.5 Inpainting',author:'RunwayML',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/stabilityai/stable-diffusion-xl-base-1.0',name:'Stable Diffusion XL Base 1.0',author:'Stability AI',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/bytedance/stable-diffusion-xl-lightning',name:'Stable Diffusion XL Lightning',author:'ByteDance',task:'Text-to-Image',tags:['Cloudflare-hosted']},
  {id:'@cf/unum/uform-gen2-qwen-500m',name:'UForm-Gen2 Qwen 500M',author:'Unum',task:'Image-to-Text',tags:['Cloudflare-hosted'],status:'deprecated'},
  {id:'@cf/openai/whisper',name:'Whisper',author:'OpenAI',task:'Automatic Speech Recognition',tags:['Cloudflare-hosted']},
  {id:'@cf/openai/whisper-large-v3-turbo',name:'Whisper Large V3 Turbo',author:'OpenAI',task:'Automatic Speech Recognition',tags:['Cloudflare-hosted','Batch']},
  {id:'@cf/openai/whisper-tiny-en',name:'Whisper Tiny EN',author:'OpenAI',task:'Automatic Speech Recognition',tags:['Cloudflare-hosted']},
];

/** Distinct task types for grouping the catalog in the UI. */
export function workersAiTaskGroups(): Array<{task:string;models:WorkersAiModel[]}> {
  const map = new Map<string, WorkersAiModel[]>();
  for (const m of WORKERS_AI_MODELS) {
    const list = map.get(m.task) || [];
    list.push(m);
    map.set(m.task, list);
  }
  return [...map.entries()].map(([task, models]) => ({ task, models }));
}
