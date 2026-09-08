<?php

namespace Warext\AIContentInspector\Provider;

abstract class AbstractJsonProvider implements ProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected int $timeout;

    public function __construct(string $apiKey = '', string $model = '', int $timeout = 8)
    {
        $this->apiKey = trim($apiKey);
        $this->model = trim($model);
        $this->timeout = max(3, min(30, $timeout));
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

        try
        {
            $raw = $this->request($message);
            return $this->normalizeResponse($raw);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI ' . $this->getLabel() . ': ');
            return $this->unavailable('request_failed');
        }
    }

    abstract protected function request(string $message): array;

    protected function normalizeResponse(array $raw): array
    {
        $content = $raw['content'] ?? null;
        if (!is_string($content) || trim($content) === '')
        {
            return $this->unavailable('missing_content');
        }

        $parsed = $this->parseJson($content);
        if (!$parsed)
        {
            return $this->unavailable('invalid_json');
        }

        $risk = max(0, min(100, (int)($parsed['risk'] ?? 0)));
        $confidence = max(0, min(100, (int)($parsed['confidence'] ?? 0)));
        $usageType = (string)($parsed['usage_type'] ?? 'unknown');
        if (!in_array($usageType, ['human_likely', 'editing_assistance', 'ai_assistance', 'ai_heavy', 'unknown'], true))
        {
            $usageType = 'unknown';
        }

        $signals = [];
        foreach ((array)($parsed['signals'] ?? []) as $signal)
        {
            if (!is_scalar($signal)) continue;
            $signal = trim((string)$signal);
            if ($signal !== '') $signals[] = mb_substr($signal, 0, 160, 'UTF-8');
            if (count($signals) >= 6) break;
        }

        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
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
                'model' => (string)($raw['model'] ?? $this->model),
                'requested_model' => $this->model
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

    protected function systemPrompt(): string
    {
        return 'Sen bir forum moderasyon destek analizörüsün. Verilen metnin yapay zeka tarafından tamamen üretilmiş, yapay zeka yardımıyla düzenlenmiş veya insan ağırlıklı yazılmış olma ihtimalini değerlendir. Kesin hüküm verme. Yalnızca geçerli JSON döndür. Şema: {"risk":0-100,"confidence":0-100,"usage_type":"human_likely|editing_assistance|ai_assistance|ai_heavy|unknown","signals":["kısa sinyal"],"note":"tek kısa açıklama"}. Dil veya konu bilgisini AI kanıtı sayma; teknik, akademik ve düzgün yazılmış insan metinlerinde false-positive riskine dikkat et.';
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
                'requested_model' => $this->model
            ],
            'available' => false,
            'reason' => $reason
        ];
    }
}
