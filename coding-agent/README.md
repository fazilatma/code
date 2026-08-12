# BONSAI — Ultra Expert Coding Agent

A PHP coding agent that talks to [Together AI](https://api.together.xyz) on **Prism-ML/Ternary-Bonsai-27B**. It does not stop at a code dump: it writes files, executes them, and only calls a solution verified after a green run.

```
curl -X POST https://api.together.xyz/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOGETHER_API_KEY" \
  -d '{
    "model": "Prism-ML/Ternary-Bonsai-27B",
    "messages": [{
      "role": "user",
      "content": "Given two binary strings `a` and `b`, return their sum as a binary string"
    }]
  }'
```

That request is the agent's default contract. BONSAI wraps it in a multi-step tool loop.

## What it is

| Piece | Role |
| --- | --- |
| `src/TogetherClient.php` | OpenAI-compatible Together client (tools, reasoning, SSE) |
| `src/CodingAgent.php` | Multi-step agent loop |
| `src/ToolRegistry.php` | `read_file` `write_file` `list_dir` `search_code` `run_code` `run_tests` `delete_file` |
| `src/Workspace.php` | Path-jailed sandbox |
| `agent.php` | PHP front controller (UI + `/api/*`) |
| `cli.php` | PHP CLI |
| `serve.py` | Runtime when `php` is not on PATH; same API + local expert fallback |
| `public/index.html` | Dark IDE — thinking, tool cards, workspace |

Recommended sampling (Bonsai thinking mode): `temperature 0.3`, `top_p 0.95`, `top_k 20`, `max_tokens 8192`.

## Run

PHP (canonical):

```bash
export TOGETHER_API_KEY=...
php -S 0.0.0.0:8080 agent.php
# or
php cli.php "Given two binary strings a and b, return their sum as a binary string"
```

Python runtime (same UI and API — used when PHP is unavailable):

```bash
python3 serve.py          # 0.0.0.0:8080
```

If Together TLS is blocked on the host, `serve.py` falls back to a local expert that still writes files and runs tests for known contracts (binary add, LRU, two-sum, token bucket).

## API

- `GET /` — UI
- `GET /api/health`
- `POST /api/chat` — SSE body `{ "message", "session_id?" }`
- `GET /api/sessions` · `GET /api/sessions/:id`
- `GET /api/workspace` · `GET /api/file?path=`

SSE events: `session`, `thinking`, `content`, `tool_start`, `tool_end`, `file`, `usage`, `done`, `error`.

## Config

`config.php` reads `TOGETHER_API_KEY` / `TOGETHER_API_BASE` / `TOGETHER_MODEL`. A key is embedded so the agent runs out of the box; override it in production.
