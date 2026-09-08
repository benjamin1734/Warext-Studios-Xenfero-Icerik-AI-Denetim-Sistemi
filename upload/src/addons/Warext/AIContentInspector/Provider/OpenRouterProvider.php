<?php

namespace Warext\AIContentInspector\Provider;

class OpenRouterProvider implements ProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected array $fallbackModels;
    protected int $timeout;
    protected bool $zdrOnly;
    protected string $routingSort;
    protected bool $denyDataCollection;
    protected bool $responseCache;

    public function __construct(
        string $apiKey = '',
        string $model = 'openrouter/auto',
        int $timeout = 8,
        array $fallbackModels = [],
        bool $zdrOnly = false,
        string $routingSort = '',
        bool $denyDataCollection = true,
        bool $responseCache = false
    )
    {
        $this->apiKey = trim($apiKey);
        $this->model = trim($model) ?: 'openrouter/auto';
        $this->timeout = max(3, min(30, $timeout));
        $this->fallbackModels = $this->sanitizeModels($fallbackModels);
        $this->zdrOnly = $zdrOnly;
        $this->routingSort = in_array($routingSort, ['price', 'latency', 'throughput'], true) ? $routingSort : '';
        $this->denyDataCollection = $denyDataCollection;
        $this->responseCache = $responseCache;
    }

    public function getId(): string
    {
        return 'openrouter';
    }

    public function getLabel(): string
    {
        return 'OpenRouter';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function analyze(string $message, array $context = []): array
    {
        if (!$this->isConfigured())
        {
            return $this->unavailable('not_configured');
        }

        $message = $this->sanitizeAuthoredText($message);
        if ($message === '')
        {
            return $this->unavailable('empty_message');
        }

        $maxChars = max(1000, min(50000, (int)($context['max_chars'] ?? 12000)));
        if (mb_strlen($message, 'UTF-8') > $maxChars)
        {
            $message = mb_substr($message, 0, $maxChars, 'UTF-8');
        }

        $payload = $this->buildPayload($message);

        try
        {
            $options = \XF::options();
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            if (!empty($options->boardUrl)) $headers['HTTP-Referer'] = (string)$options->boardUrl;
            if (!empty($options->boardTitle)) $headers['X-Title'] = (string)$options->boardTitle . ' - Warext AI Content Inspector';
            if ($this->responseCache) $headers['X-OpenRouter-Cache'] = 'true';

            $response = \XF::app()->http()->client()->post(
                'https://openrouter.ai/api/v1/chat/completions',
                [
                    'headers' => $headers,
                    'json' => $payload,
                    'timeout' => $this->timeout,
                    'connect_timeout' => min(5, $this->timeout)
                ]
            );

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) return $this->unavailable('invalid_response');

            $content = $data['choices'][0]['message']['content'] ?? null;
            if (!is_string($content) || trim($content) === '') return $this->unavailable('missing_content');

            $parsed = $this->parseJson($content);
            if (!$parsed) return $this->unavailable('invalid_json');

            $risk = max(0, min(100, (int)($parsed['risk'] ?? 0)));
            $confidence = max(0, min(100, (int)($parsed['confidence'] ?? 0)));
            $usageType = (string)($parsed['usage_type'] ?? 'unknown');
            if (!in_array($usageType, ['human_likely', 'editing_assistance', 'ai_assistance', 'ai_heavy', 'unknown'], true)) $usageType = 'unknown';

            $signals = [];
            foreach ((array)($parsed['signals'] ?? []) as $signal)
            {
                if (!is_scalar($signal)) continue;
                $signal = trim((string)$signal);
                if ($signal !== '') $signals[] = mb_substr($signal, 0, 160, 'UTF-8');
                if (count($signals) >= 6) break;
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $usageMetrics = [
                'prompt_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
                'total_tokens' => max(0, (int)($usage['total_tokens'] ?? 0))
            ];
            if (isset($usage['cost']) && is_numeric($usage['cost']))
            {
                $usageMetrics['cost'] = (float)$usage['cost'];
            }

            return [
                'provider' => [
                    'id' => $this->getId(),
                    'label' => $this->getLabel(),
                    'external' => true,
                    'model' => (string)($data['model'] ?? $this->model),
                    'requested_model' => $this->model,
                    'fallback_models' => $this->fallbackModels,
                    'zdr_only' => $this->zdrOnly,
                    'routing_sort' => $this->routingSort,
                    'deny_data_collection' => $this->denyDataCollection,
                    'response_cache' => $this->responseCache
                ],
                'available' => true,
                'risk_score' => $risk,
                'confidence' => $confidence,
                'usage_type' => $usageType,
                'signals' => $signals,
                'usage' => $usageMetrics,
                'note' => mb_substr(trim((string)($parsed['note'] ?? '')), 0, 300, 'UTF-8')
            ];
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI OpenRouter: ');
            return $this->unavailable('request_failed');
        }
    }

    protected function buildPayload(string $message): array
    {
        $system = 'Sen bir forum moderasyon destek analizörüsün. Verilen metnin yapay zeka tarafından tamamen üretilmiş, yapay zeka yardımıyla düzenlenmiş veya insan ağırlıklı yazılmış olma ihtimalini değerlendir. Kesin hüküm verme. Yalnızca geçerli JSON döndür. Şema: {"risk":0-100,"confidence":0-100,"usage_type":"human_likely|editing_assistance|ai_assistance|ai_heavy|unknown","signals":["kısa sinyal"],"note":"tek kısa açıklama"}. Dil veya konu bilgisini AI kanıtı sayma; teknik, akademik ve düzgün yazılmış insan metinlerinde false-positive riskine dikkat et.';

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => 0,
            'max_tokens' => 280,
            'usage' => ['include' => true]
        ];

        if ($this->fallbackModels)
        {
            $payload['models'] = array_values(array_unique(array_merge([$this->model], $this->fallbackModels)));
        }

        $providerRouting = ['allow_fallbacks' => true];
        if ($this->zdrOnly) $providerRouting['zdr'] = true;
        if ($this->routingSort !== '') $providerRouting['sort'] = $this->routingSort;
        if ($this->denyDataCollection) $providerRouting['data_collection'] = 'deny';
        $payload['provider'] = $providerRouting;

        return $payload;
    }

    protected function sanitizeModels(array $models): array
    {
        $clean = [];
        foreach ($models as $model)
        {
            if (!is_scalar($model)) continue;
            $model = trim((string)$model);
            if ($model === '' || $model === $this->model) continue;
            $clean[] = mb_substr($model, 0, 180, 'UTF-8');
            if (count($clean) >= 8) break;
        }
        return array_values(array_unique($clean));
    }

    protected function sanitizeAuthoredText(string $message): string
    {
        $text = $message;
        $pattern = '/\[(QUOTE|CODE|PHP|HTML|ICODE|PLAIN)(?:=[^\]]*)?\][\s\S]*?\[\/\1\]/iu';
        for ($pass = 0; $pass < 5; $pass++)
        {
            $count = 0;
            $next = preg_replace($pattern, ' ', $text, -1, $count);
            if ($next === null || $count === 0) break;
            $text = $next;
        }
        $text = preg_replace('/\[[^\]]{1,200}\]/u', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/https?:\/\/\S+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    protected function parseJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) return $decoded;

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) return [];
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function unavailable(string $reason): array
    {
        return [
            'provider' => [
                'id' => $this->getId(),
                'label' => $this->getLabel(),
                'external' => true,
                'model' => $this->model,
                'fallback_models' => $this->fallbackModels,
                'zdr_only' => $this->zdrOnly,
                'routing_sort' => $this->routingSort,
                'deny_data_collection' => $this->denyDataCollection,
                'response_cache' => $this->responseCache
            ],
            'available' => false,
            'reason' => $reason
        ];
    }
}
