<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // API pública usada para buscar os dados cadastrais por CNPJ (Tarefa 1).
    // A URL e o timeout ficam aqui (e podem ir para o .env) para não ficarem
    // escritos direto no código. Assim dá para trocar sem mexer no código.
    'brasilapi' => [
        'url' => env('BRASILAPI_URL', 'https://brasilapi.com.br/api'),

        // Timeouts (Tarefa 3), em segundos:
        // connect_timeout = tempo máximo para abrir a conexão;
        // timeout = tempo máximo da requisição inteira (conexão + resposta).
        'connect_timeout' => (int) env('BRASILAPI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('BRASILAPI_TIMEOUT', 10),

        // Novas tentativas em falhas temporárias (rede ou erro 5xx).
        // tentativas = total de tentativas, contando a primeira (1 = sem retry);
        // intervalo_ms = espera entre uma tentativa e outra.
        // Pior caso de espera do cliente: tentativas x timeout (2 x 10s = 20s).
        'tentativas' => max(1, (int) env('BRASILAPI_TENTATIVAS', 2)),
        'intervalo_ms' => (int) env('BRASILAPI_INTERVALO_MS', 300),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
