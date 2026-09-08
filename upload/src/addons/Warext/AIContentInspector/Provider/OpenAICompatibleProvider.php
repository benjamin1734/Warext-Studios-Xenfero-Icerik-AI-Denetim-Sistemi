<?php

namespace Warext\AIContentInspector\Provider;

class OpenAICompatibleProvider extends AbstractJsonProvider
{
    protected string $id;
    protected string $label;
    protected string $baseUrl;
    protected bool $apiKeyRequired;

    public function __construct(
        string $id,
        string $label,
        string $baseUrl,
        string $apiKey = '',
        string $model = '',
        int $timeout = 8,
        bool $apiKeyRequired = true
    )
    {
        parent::__construct($apiKey, $model, $timeout);
        $this->id = trim($id);
        $this->label = trim($label);
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
        $this->apiKeyRequired = $apiKeyRequired;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isConfigured(): bool
    {
        if ($this->id === '' || $this->label === '' || $this->model === '' || !$this->isValidBaseUrl($this->baseUrl))
        {
            return false;
        }
        return !$this->apiKeyRequired || $this->apiKey !== '';
    }

    protected function request(string $message): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];
        if ($this->apiKey !== '')
        {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = \XF::app()->http()->client()->post(
            $this->chatCompletionsUrl(),
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
                'prompt_tokens' => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'completion_tokens' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'total_tokens' => (int)($usage['total_tokens'] ?? 0),
                'cost' => isset($usage['cost']) && is_numeric($usage['cost']) ? (float)$usage['cost'] : null
            ]
        ];
    }

    protected function buildPayload(string $message): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => 0,
            'max_tokens' => 280
        ];
    }

    protected function chatCompletionsUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        if (preg_match('#/chat/completions$#i', $base)) return $base;
        return $base . '/chat/completions';
    }

    protected function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    protected function isValidBaseUrl(string $url): bool
    {
        if ($url === '') return false;
        $parts = parse_url($url);
        if (!is_array($parts)) return false;
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        if (empty($parts['host'])) return false;
        if (isset($parts['user']) || isset($parts['pass'])) return false;
        return true;
    }
}
