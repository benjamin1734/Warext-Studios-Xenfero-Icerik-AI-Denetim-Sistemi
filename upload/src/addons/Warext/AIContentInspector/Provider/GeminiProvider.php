<?php

namespace Warext\AIContentInspector\Provider;

class GeminiProvider extends AbstractJsonProvider
{
    public function getId(): string
    {
        return 'gemini';
    }

    public function getLabel(): string
    {
        return 'Google Gemini';
    }

    protected function request(string $message): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent';
        $response = \XF::app()->http()->client()->post(
            $url,
            [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
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
        foreach ((array)($data['candidates'][0]['content']['parts'] ?? []) as $part)
        {
            if (isset($part['text']) && is_string($part['text'])) $content .= $part['text'];
        }

        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        return [
            'content' => $content,
            'model' => $this->model,
            'usage' => [
                'prompt_tokens' => (int)($usage['promptTokenCount'] ?? 0),
                'completion_tokens' => (int)($usage['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int)($usage['totalTokenCount'] ?? 0)
            ]
        ];
    }

    protected function buildPayload(string $message): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $this->systemPrompt()]]
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $message]]
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => 280
            ]
        ];
    }
}
