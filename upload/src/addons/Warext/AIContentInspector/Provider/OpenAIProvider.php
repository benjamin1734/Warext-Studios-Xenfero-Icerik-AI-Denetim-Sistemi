<?php

namespace Warext\AIContentInspector\Provider;

class OpenAIProvider extends AbstractJsonProvider
{
    public function getId(): string
    {
        return 'openai';
    }

    public function getLabel(): string
    {
        return 'OpenAI / GPT';
    }

    protected function request(string $message): array
    {
        $response = \XF::app()->http()->client()->post(
            'https://api.openai.com/v1/responses',
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

        $content = '';
        if (isset($data['output_text']) && is_string($data['output_text']))
        {
            $content = $data['output_text'];
        }
        else
        {
            foreach ((array)($data['output'] ?? []) as $output)
            {
                foreach ((array)($output['content'] ?? []) as $part)
                {
                    if (($part['type'] ?? '') === 'output_text' && isset($part['text']) && is_string($part['text']))
                    {
                        $content .= $part['text'];
                    }
                }
            }
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'content' => $content,
            'model' => (string)($data['model'] ?? $this->model),
            'usage' => [
                'prompt_tokens' => (int)($usage['input_tokens'] ?? 0),
                'completion_tokens' => (int)($usage['output_tokens'] ?? 0),
                'total_tokens' => (int)($usage['total_tokens'] ?? 0)
            ]
        ];
    }

    protected function buildPayload(string $message): array
    {
        return [
            'model' => $this->model,
            'instructions' => $this->systemPrompt(),
            'input' => $message,
            'max_output_tokens' => 280
        ];
    }
}
