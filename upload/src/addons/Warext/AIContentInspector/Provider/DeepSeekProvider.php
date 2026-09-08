<?php

namespace Warext\AIContentInspector\Provider;

class DeepSeekProvider extends AbstractJsonProvider
{
    public function getId(): string
    {
        return 'deepseek';
    }

    public function getLabel(): string
    {
        return 'DeepSeek';
    }

    protected function request(string $message): array
    {
        $response = \XF::app()->http()->client()->post(
            'https://api.deepseek.com/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
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
                'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int)($usage['total_tokens'] ?? 0)
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
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'disabled'],
            'max_tokens' => 280
        ];
    }
}
