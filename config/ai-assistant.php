<?php

return [
    'ollama' => [
        'base_url' => env('AI_ASSISTANT_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'timeout' => (int) env('AI_ASSISTANT_REQUEST_TIMEOUT', 90),
    ],

    'models' => [
        'planning' => env('AI_ASSISTANT_MODEL_PLANNING', 'glm-5:cloud'),
        'coding' => env('AI_ASSISTANT_MODEL_CODING', 'qwen3-coder-next:cloud'),
        'embedding' => env('AI_ASSISTANT_MODEL_EMBEDDING', 'qwen3-embedding:0.6b'),
    ],

    'retrieval' => [
        'max_chunks' => (int) env('AI_ASSISTANT_MAX_CONTEXT_CHUNKS', 4),
        'max_files' => (int) env('AI_ASSISTANT_MAX_INDEX_FILES', 40),
        'max_file_chars' => (int) env('AI_ASSISTANT_MAX_FILE_CHARS', 2400),
        'chunk_size' => (int) env('AI_ASSISTANT_CHUNK_SIZE', 900),
        'chunk_overlap' => (int) env('AI_ASSISTANT_CHUNK_OVERLAP', 180),
        'cache_ttl' => (int) env('AI_ASSISTANT_INDEX_CACHE_TTL', 86400),
        'lazy_warm' => (bool) env('AI_ASSISTANT_RETRIEVAL_LAZY_WARM', false),
        'paths' => [
            'app',
            'routes',
            'config',
            'resources/js/pages',
        ],
    ],

    'tools' => [
        'filesystem' => [
            'enabled' => (bool) env('AI_ASSISTANT_FS_TOOLS_ENABLED', true),
            'allow_any_path' => (bool) env('AI_ASSISTANT_FS_ALLOW_ANY_PATH', true),
            'roots' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AI_ASSISTANT_FS_ROOTS', base_path()))
            ))),
            'max_read_chars' => (int) env('AI_ASSISTANT_FS_MAX_READ_CHARS', 200000),
            'max_write_chars' => (int) env('AI_ASSISTANT_FS_MAX_WRITE_CHARS', 400000),
            'max_tool_rounds' => (int) env('AI_ASSISTANT_FS_MAX_TOOL_ROUNDS', 16),
            'max_search_results' => (int) env('AI_ASSISTANT_FS_MAX_SEARCH_RESULTS', 60),
            'max_search_file_bytes' => (int) env('AI_ASSISTANT_FS_MAX_SEARCH_FILE_BYTES', 300000),
            'shell' => [
                'enabled' => (bool) env('AI_ASSISTANT_FS_SHELL_ENABLED', false),
                'allow_any_command' => (bool) env('AI_ASSISTANT_FS_SHELL_ALLOW_ANY_COMMAND', false),
                'allowed_prefixes' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'AI_ASSISTANT_FS_SHELL_ALLOWED_PREFIXES',
                        'php artisan,npm run,composer,git status,git diff,ls,cat,rg'
                    ))
                ))),
                'timeout_seconds' => (int) env('AI_ASSISTANT_FS_SHELL_TIMEOUT_SECONDS', 30),
                'max_output_chars' => (int) env('AI_ASSISTANT_FS_SHELL_MAX_OUTPUT_CHARS', 12000),
            ],
        ],
    ],
];
