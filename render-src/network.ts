import dns from 'node:dns/promises';
import net from 'node:net';
import { config } from './config.js';

function privateIp(ip: string): boolean {
  if (net.isIPv4(ip)) {
    const [a,b] = ip.split('.').map(Number);
    return a === 0 || a === 10 || a === 127 || a >= 224 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168);
  }
  const value = ip.toLowerCase();
  return value === '::1' || value.startsWith('fc') || value.startsWith('fd') || value.startsWith('fe80:') || value === '::';
}

export async function assertPublicUrl(raw: string): Promise<URL> {
  const url = new URL(raw);
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Only HTTP/HTTPS URLs are allowed');
  if (url.username || url.password) throw new Error('Credentials in URLs are not allowed');
  const host = url.hostname.toLowerCase();
  if (host === 'localhost' || host.endsWith('.local') || host.endsWith('.internal')) throw new Error('Private hosts are not allowed');
  const addresses = await dns.lookup(host, { all: true });
  if (!addresses.length || addresses.some(item => privateIp(item.address))) throw new Error('Private or unresolved destination is not allowed');
  return url;
}

export async function safeFetch(raw: string, init: RequestInit = {}, maxBytes = 8_000_000): Promise<Response> {
  let url = await assertPublicUrl(raw);
  for (let redirects = 0; redirects < 5; redirects++) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), config.requestTimeoutMs);
    try {
      const response = await fetch(url, {
        ...init,
        redirect: 'manual',
        signal: controller.signal,
        headers: { 'user-agent': config.userAgent, accept: 'text/html,application/json;q=0.9,*/*;q=0.8', ...init.headers }
      });
      if ([301,302,303,307,308].includes(response.status)) {
        const location = response.headers.get('location');
        if (!location) throw new Error('Redirect without location');
        url = await assertPublicUrl(new URL(location, url).href);
        continue;
      }
      const length = Number(response.headers.get('content-length') || 0);
      if (length > maxBytes) throw new Error(`Response exceeds ${maxBytes} bytes`);
      return response;
    } finally { clearTimeout(timeout); }
  }
  throw new Error('Too many redirects');
}

export async function safeText(raw: string, maxBytes = 8_000_000): Promise<{ text: string; url: string }> {
  const response = await safeFetch(raw, {}, maxBytes);
  if (!response.ok) throw new Error(`HTTP ${response.status} from ${raw}`);
  const buffer = await response.arrayBuffer();
  if (buffer.byteLength > maxBytes) throw new Error(`Response exceeds ${maxBytes} bytes`);
  return { text: new TextDecoder().decode(buffer), url: response.url || raw };
}
