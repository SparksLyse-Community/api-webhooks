<?php

declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(false);

$allowedOrigins = [
    'http://localhost:4321',
    'https://sparkslyse-community.github.io'
];

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];

    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Credentials: true");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)) {
        header("Access-Control-Allow-Methods: POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Vary: Origin");
    }
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Method not allowed'
    ]);
    exit;
}

$config = require __DIR__ . '/config.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');

header('X-Accel-Buffering: no');
header('Content-Encoding: none');

while (ob_get_level() > 0) {
    ob_end_clean();
}

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'Off');
ini_set('implicit_flush', '1');

ob_implicit_flush(true);

if (!empty($config['maintenance_mode'])) {
    $placeholderText = "Le service est actuellement en maintenance pour amélioration. Veuillez réessayer plus tard.";

    $words = explode(' ', $placeholderText);

    foreach ($words as $index => $word) {
        $chunk = ($index === 0) ? $word : ' ' . $word;

        $payload = [
            'choices' => [
                [
                    'delta' => [
                        'content' => $chunk
                    ],
                    'finish_reason' => null
                ]
            ]
        ];

        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();

        usleep(80000);
    }

    echo "data: [DONE]\n\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
    exit;
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo "data: " . json_encode([
        'error' => 'Invalid JSON'
    ]) . "\n\n";

    flush();
    exit;
}

$messages = $input['messages'] ?? [];

if (!is_array($messages)) {
    http_response_code(400);

    echo "data: " . json_encode([
        'error' => 'Invalid messages'
    ]) . "\n\n";

    flush();
    exit;
}

$openWebUIUrl = 'https://openwebui.marvideo.fr/api/chat/completions';
$apiKey = $config['openwebui_api_key'] ?? null;

if (!$apiKey) {
    http_response_code(500);

    echo "data: " . json_encode([
        'error' => 'openwebui_api_key is not configured'
    ]) . "\n\n";

    flush();
    exit;
}

$payload = json_encode([
    'model' => 'lyse.sparkslyse-336m',
    'messages' => $messages,
    'stream' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($openWebUIUrl);

$httpCode = 0;
$isSSE = false;
$buffer = '';

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Accept: text/event-stream',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_ENCODING => '',
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

    CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$httpCode, &$isSSE) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $httpCode = (int)$m[1];
        }
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/event-stream') !== false) {
            $isSSE = true;
        }
        return strlen($header);
    },

    CURLOPT_WRITEFUNCTION => function ($curl, string $data) use (&$isSSE, &$buffer, &$httpCode): int {
        if ($httpCode >= 400 || !$isSSE) {
            $buffer .= $data;
            return strlen($data);
        }
        echo $data;
        @ob_flush();
        flush();
        return strlen($data);
    },
]);

$result = curl_exec($ch);

if ($result === false) {
    echo "data: " . json_encode(['error' => curl_error($ch)]) . "\n\n";
} elseif ($httpCode >= 400) {
    echo "data: " . json_encode(['error' => "Upstream error ($httpCode)", 'details' => $buffer], JSON_UNESCAPED_UNICODE) . "\n\n";
} elseif (!$isSSE) {
    $json = json_decode($buffer, true);
    $text = $json['choices'][0]['message']['content'] ?? '';

    foreach (preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
        echo "data: " . json_encode([
            'choices' => [['delta' => ['content' => $chunk], 'finish_reason' => null]]
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        flush();
        usleep(30000);
    }
}

echo "data: [DONE]\n\n";
@ob_flush();
flush();
curl_close($ch);
