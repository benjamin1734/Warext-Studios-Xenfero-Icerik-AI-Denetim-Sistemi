<?php

namespace Warext\AIContentInspector\Provider;

class AnthropicProvider extends AbstractJsonProvider
{
    public function getId(): string
    {
        return 'anthropic';
    }

    public function getLabel(): string
    {
        return 'Anthropic Claude';
    }

    protected function request(string $message): array
    {
        $response = \XF::app()->http()->client()->post(
            'https://api.anthropic.com/v1/messages',
            [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
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

        $content = '';
        foreach ((array)($data['content'] ?? []) as $part)
        {
            if (($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text']))
            {
                $content .= $part['text'];
            }
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $inputTokens = (int)($usage['input_tokens'] ?? 0);
        $outputTokens = (int)($usage['output_tokens'] ?? 0);
        return [
            'content' => $content,
            'model' => (string)($data['model'] ?? $this->model),
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens
            ]
        ];
    }

    protected function buildPayload(string $message): array
    {
        return [
            'model' => $this->model,
            'system' => $this->systemPrompt(),
            'messages' => [[
                'role' => 'user',
                'content' => $message
            ]],
            'max_tokens' => 280
        ];
    }
}
