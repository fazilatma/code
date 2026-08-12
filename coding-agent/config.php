<?php

declare(strict_types=1);

/**
 * BONSAI ultra-expert coding agent — runtime configuration.
 *
 * TOGETHER_API_KEY in the environment always wins. The embedded key is the
 * one supplied for this agent so it runs out of the box.
 */
return [
    'api_key' => getenv('TOGETHER_API_KEY') ?: 'be7711af89dd9d10d1bcc10c3b64fc2fb0214953b6b29e9509a589d3ad015dba',
    'api_base' => getenv('TOGETHER_API_BASE') ?: 'https://api.together.xyz/v1',
    'model' => getenv('TOGETHER_MODEL') ?: 'Prism-ML/Ternary-Bonsai-27B',
    'temperature' => 0.3,
    'top_p' => 0.95,
    'top_k' => 20,
    'max_tokens' => 8192,
    'max_steps' => 18,
    'timeout' => 180,
    'connect_timeout' => 20,
    'run_timeout' => 15,
    'workspace' => __DIR__ . '/workspace',
    'sessions' => __DIR__ . '/sessions',
    'agent_name' => 'BONSAI',
    'agent_version' => '1.0.0',
];
