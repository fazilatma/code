import { getState, setState } from './db.js';
import { decryptVault, encryptVault, mergeConnections, type ConnectionVault } from './vault.js';
const KEY='connection_vault';let cached:{value:ConnectionVault;expires:number}|null=null;
export async function loadConnections(fresh=false):Promise<ConnectionVault>{if(!fresh&&cached&&cached.expires>Date.now())return cached.value;const value=await decryptVault(await getState<unknown>(KEY,null));cached={value,expires:Date.now()+30_000};return value;}
export async function saveConnections(input:unknown):Promise<ConnectionVault>{const value=mergeConnections(await loadConnections(true),input);await setState(KEY,await encryptVault(value));cached={value,expires:Date.now()+30_000};return value;}
export function connectionStatus(value:ConnectionVault){return{woo:Boolean(value.woo.url&&value.woo.key&&value.woo.secret),basalam:Boolean(value.basalam.token&&value.basalam.vendorId),ai:Boolean((value.ai.baseUrl&&value.ai.apiKey&&value.ai.model)||value.ai.providers.some(p=>p.enabled&&p.baseUrl&&p.apiKey&&p.models.length)),notifications:Boolean(value.notifications.url||value.notifications.baleToken||value.notifications.rubikaToken)}}
