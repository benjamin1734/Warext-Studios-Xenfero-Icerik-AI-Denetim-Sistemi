<?php

namespace Warext\AIContentInspector\Provider;

abstract class AbstractJsonProvider implements ProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected int $timeout;
    protected array $requestContext = [];

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
        if (!$this->isConfigured()) return $this->unavailable('not_configured');

        $message = $this->sanitizeAuthoredText($message);
        if ($message === '') return $this->unavailable('empty_message');

        $maxChars = max(500, min(50000, (int)($context['max_chars'] ?? 12000)));
        if (mb_strlen($message, 'UTF-8') > $maxChars) $message = mb_substr($message, 0, $maxChars, 'UTF-8');

        $this->requestContext = $this->normalizeRequestContext($context);
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
        finally
        {
            $this->requestContext = [];
        }
    }

    abstract protected function request(string $message): array;

    protected function normalizeRequestContext(array $context): array
    {
        $tasks = array_values(array_unique(array_filter(array_map('strval', (array)($context['tasks'] ?? ['moderation'])))));
        $tasks = array_values(array_intersect($tasks, ['moderation', 'writing']));
        if (!$tasks) $tasks = ['moderation'];

        $localIssues = [];
        $writingContext = is_array($context['writing_context'] ?? null) ? $context['writing_context'] : [];
        foreach ((array)($writingContext['issues'] ?? []) as $issue)
        {
            if (!is_array($issue)) continue;
            $localIssues[] = [
                'start' => max(0, (int)($issue['start'] ?? 0)),
                'end' => max(0, (int)($issue['end'] ?? 0)),
                'original' => mb_substr((string)($issue['original'] ?? ''), 0, 120, 'UTF-8'),
                'suggestions' => array_slice(array_values(array_filter(array_map('strval', (array)($issue['suggestions'] ?? [])))), 0, 3),
                'rule' => mb_substr((string)($issue['rule'] ?? ''), 0, 80, 'UTF-8'),
                'confidence' => max(0, min(100, (int)($issue['confidence'] ?? 0)))
            ];
            if (count($localIssues) >= 16) break;
        }

        return [
            'tasks' => $tasks,
            'writing_mode' => in_array((string)($context['writing_mode'] ?? ''), ['ai', 'hybrid'], true)
                ? (string)$context['writing_mode']
                : 'ai',
            'writing_context' => ['issues' => $localIssues],
            'preserve_style' => !isset($context['preserve_style']) || !empty($context['preserve_style']),
            'interop_contract' => max(0, (int)($context['interop_contract'] ?? 0))
        ];
    }

    protected function outputTokenLimit(): int
    {
        $tasks = (array)($this->requestContext['tasks'] ?? ['moderation']);
        return in_array('writing', $tasks, true) ? 1200 : 280;
    }

    protected function normalizeResponse(array $raw): array
    {
        $content = $raw['content'] ?? null;
        if (!is_string($content) || trim($content) === '') return $this->unavailable('missing_content');

        $parsed = $this->parseJson($content);
        if (!$parsed) return $this->unavailable('invalid_json');

        $tasks = (array)($this->requestContext['tasks'] ?? ['moderation']);
        $moderation = in_array('moderation', $tasks, true);
        $writing = in_array('writing', $tasks, true);

        $risk = $moderation ? max(0, min(100, (int)($parsed['risk'] ?? 0))) : 0;
        $confidence = $moderation ? max(0, min(100, (int)($parsed['confidence'] ?? 0))) : 0;
        $usageType = $moderation ? (string)($parsed['usage_type'] ?? 'unknown') : 'unknown';
        if (!in_array($usageType, ['human_likely', 'editing_assistance', 'ai_assistance', 'ai_heavy', 'unknown'], true)) $usageType = 'unknown';

        $signals = [];
        if ($moderation)
        {
            foreach ((array)($parsed['signals'] ?? []) as $signal)
            {
                if (!is_scalar($signal)) continue;
                $signal = trim((string)$signal);
                if ($signal !== '') $signals[] = mb_substr($signal, 0, 160, 'UTF-8');
                if (count($signals) >= 6) break;
            }
        }

        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        $usageMetrics = [
            'prompt_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
            'total_tokens' => max(0, (int)($usage['total_tokens'] ?? 0))
        ];
        if (isset($usage['cost']) && is_numeric($usage['cost'])) $usageMetrics['cost'] = (float)$usage['cost'];

        return [
            'provider' => [
                'id' => $this->getId(),
                'label' => $this->getLabel(),
                'external' => true,
                'model' => (string)($raw['model'] ?? $this->model),
                'requested_model' => $this->model
            ],
            'available' => true,
            'tasks' => $tasks,
            'risk_score' => $risk,
            'confidence' => $confidence,
            'usage_type' => $usageType,
            'signals' => $signals,
            'usage' => $usageMetrics,
            'note' => $moderation ? mb_substr(trim((string)($parsed['note'] ?? '')), 0, 300, 'UTF-8') : '',
            'writing' => $writing ? $this->normalizeWriting((array)($parsed['writing'] ?? [])) : []
        ];
    }

    protected function normalizeWriting(array $writing): array
    {
        if (!$writing) return [];

        $issues = [];
        foreach ((array)($writing['issues'] ?? []) as $issue)
        {
            if (!is_array($issue)) continue;
            $start = max(0, (int)($issue['start'] ?? 0));
            $end = max($start, (int)($issue['end'] ?? $start));
            $original = mb_substr((string)($issue['original'] ?? ''), 0, 180, 'UTF-8');
            $suggestion = mb_substr(trim((string)($issue['suggestion'] ?? '')), 0, 240, 'UTF-8');
            if ($suggestion === '') continue;
            $issues[] = [
                'start' => $start,
                'end' => $end,
                'original' => $original,
                'suggestion' => $suggestion,
                'type' => mb_substr((string)($issue['type'] ?? 'writing'), 0, 60, 'UTF-8'),
                'reason' => mb_substr((string)($issue['reason'] ?? ''), 0, 220, 'UTF-8'),
                'confidence' => max(0, min(100, (int)($issue['confidence'] ?? 0))),
                'local_supported' => !empty($issue['local_supported'])
            ];
            if (count($issues) >= 20) break;
        }

        return [
            'issues' => $issues,
            'confidence' => max(0, min(100, (int)($writing['confidence'] ?? 0)))
        ];
    }

    protected function systemPrompt(): string
    {
        $tasks = (array)($this->requestContext['tasks'] ?? ['moderation']);
        $moderation = in_array('moderation', $tasks, true);
        $writing = in_array('writing', $tasks, true);
        $local = (array)($this->requestContext['writing_context']['issues'] ?? []);
        $mode = (string)($this->requestContext['writing_mode'] ?? 'ai');
        $parts = [];

        if ($moderation)
        {
            $parts[] = 'Sen bir forum moderasyon destek analizörüsün. Verilen kullanıcı metninde tamamen yapay zekâ üretimi veya belirgin yapay zekâ yazım desteği olasılığını değerlendir; kesin hüküm verme. Düzgün yazım, teknik dil veya akademik ton tek başına AI kanıtı değildir. Birden fazla bağımsız işaretin birlikte bulunmasına bak: aşırı düzenli cümle/paragraf ritmi, sistematik geçiş ifadeleri, şablonlaşmış yapı, mekanik açıklama akışı, jenerik soyutlama ve tekrar eden açıklayıcı kalıplar. Puan kalibrasyonu: 0-24 insan ağırlıklı, 25-44 düşük AI sinyali, 45-64 AI desteği mümkün, 65-79 AI ağırlıklı olabilir, 80-100 güçlü çoklu AI üretim sinyali.';
        }

        if ($writing)
        {
            $parts[] = 'Türkçe yazım denetimi yap. Anlamı, yazarın üslubunu, teknik terimleri, özel adları, BBCode/kod parçalarını ve bilinçli gündelik dili gereksiz yere değiştirme. Yalnız gerçek yazım, noktalama, ek/ayrı-bitişik yazım, büyük-küçük harf, boşluk ve açık dilbilgisi sorunlarını düzelt. Metni baştan yazma ve tam düzeltilmiş metin kopyası döndürme. issues içindeki start/end UTF-8 karakter konumları verilen metne göre olsun. Emin olmadığın öneriyi çıkar.';
            if ($mode === 'hybrid' && $local)
            {
                $localJson = json_encode($local, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($localJson) && $localJson !== '' && $localJson !== '[]')
                {
                    $parts[] = 'Warext yerel motorunun adayları: ' . mb_substr($localJson, 0, 4500, 'UTF-8') . '. Adayları körü körüne kabul etme; bağlama göre doğrula, yanlış pozitifleri ele ve açıkça kaçırılmış sorunları ekle. local_supported yalnız yerel adayla aynı düzeltmeyi doğruluyorsan true olsun.';
                }
            }
        }

        $schema = [];
        if ($moderation)
        {
            $schema[] = '"risk":0-100';
            $schema[] = '"confidence":0-100';
            $schema[] = '"usage_type":"human_likely|editing_assistance|ai_assistance|ai_heavy|unknown"';
            $schema[] = '"signals":["kısa sinyal"]';
            $schema[] = '"note":"tek kısa açıklama"';
        }
        if ($writing)
        {
            $schema[] = '"writing":{"issues":[{"start":0,"end":0,"original":"","suggestion":"","type":"spelling|punctuation|grammar|capitalization|spacing","reason":"","confidence":0-100,"local_supported":false}],"confidence":0-100}';
        }

        $parts[] = 'Yalnızca geçerli JSON döndür. Şema: {' . implode(',', $schema) . '}.';
        return implode(' ', $parts);
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
