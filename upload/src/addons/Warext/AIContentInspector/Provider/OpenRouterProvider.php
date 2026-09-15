<?php

namespace Warext\AIContentInspector\Provider;

class OpenRouterProvider extends AbstractJsonProvider
{
    protected array $fallbackModels;
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
        parent::__construct($apiKey, trim($model) ?: 'openrouter/auto', $timeout);
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

    protected function request(string $message): array
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
                'json' => $this->buildPayload($message),
                'timeout' => $this->timeout,
                'connect_timeout' => min(5, $this->timeout)
            ]
        );

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) return [];

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'content' => (string)($data['choices'][0]['message']['content'] ?? ''),
            'model' => (string)($data['model'] ?? $this->model),
            'usage' => [
                'prompt_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
                'total_tokens' => max(0, (int)($usage['total_tokens'] ?? 0)),
                'cost' => isset($usage['cost']) && is_numeric($usage['cost']) ? (float)$usage['cost'] : null
            ]
        ];
    }

    protected function normalizeResponse(array $raw): array
    {
        $result = parent::normalizeResponse($raw);
        if (!empty($result['provider']) && is_array($result['provider']))
        {
            $result['provider']['fallback_models'] = $this->fallbackModels;
            $result['provider']['zdr_only'] = $this->zdrOnly;
            $result['provider']['routing_sort'] = $this->routingSort;
            $result['provider']['deny_data_collection'] = $this->denyDataCollection;
            $result['provider']['response_cache'] = $this->responseCache;
        }
        return $result;
    }

    protected function buildPayload(string $message): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => 0,
            'max_tokens' => $this->outputTokenLimit(),
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

    protected function unavailable(string $reason): array
    {
        $result = parent::unavailable($reason);
        $result['provider']['fallback_models'] = $this->fallbackModels;
        $result['provider']['zdr_only'] = $this->zdrOnly;
        $result['provider']['routing_sort'] = $this->routingSort;
        $result['provider']['deny_data_collection'] = $this->denyDataCollection;
        $result['provider']['response_cache'] = $this->responseCache;
        return $result;
    }
}
