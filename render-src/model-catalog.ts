export const MISTRAL_TEXT_MODELS = [
  'mistral-large-latest',
  'mistral-medium-latest',
  'mistral-small-latest',
  'ministral-3b-latest',
  'ministral-8b-latest',
  'ministral-14b-latest',
  'magistral-small-latest',
  'magistral-medium-latest',
  'devstral-small-latest',
  'devstral-medium-latest',
  'codestral-latest',
  'open-mistral-nemo'
] as const;

export function looksReasoningModel(model:string):boolean {
  return /(?:^|[\/_-])(reason(?:ing)?|r1|qwq|magistral|gpt-oss)(?:$|[\/_-])/i.test(model)
    || /deepseek[^/]*r1/i.test(model);
}
