<?php

namespace Warext\AIContentInspector\Service;

class InteropResultCache
{
    protected const CACHE_SET = 'Warext/AIContentInspector';
    protected const CACHE_KEY = 'interop_results_v1';
    protected const MAX_ENTRIES = 24;

    public function get(string $message, string $provider, string $model): ?array
    {
        $key = $this->makeKey($message, $provider, $model);
        if ($key === '') return null;

        $bucket = \XF::app()->simpleCache()->getValue(self::CACHE_SET, self::CACHE_KEY);
        if (!is_array($bucket) || !isset($bucket[$key]) || !is_array($bucket[$key])) return null;

        $entry = $bucket[$key];
        if ((int)($entry['expires'] ?? 0) < time())
        {
            unset($bucket[$key]);
            \XF::app()->simpleCache()->setValue(self::CACHE_SET, self::CACHE_KEY, $bucket);
            return null;
        }

        $assessment = is_array($entry['assessment'] ?? null) ? $entry['assessment'] : [];
        if (!$assessment || empty($assessment['available'])) return null;
        $assessment['shared_reuse'] = true;
        return $assessment;
    }

    public function put(string $message, string $provider, string $model, array $assessment, ?int $ttl = null): void
    {
        if (empty($assessment['available'])) return;
        $key = $this->makeKey($message, $provider, $model);
        if ($key === '') return;

        $ttl ??= max(30, min(900, (int)(\XF::options()->warextAiInteropCacheSeconds ?? 120)));
        if ($ttl <= 0) return;

        $now = time();
        $bucket = \XF::app()->simpleCache()->getValue(self::CACHE_SET, self::CACHE_KEY);
        if (!is_array($bucket)) $bucket = [];

        foreach ($bucket as $existingKey => $entry)
        {
            if (!is_array($entry) || (int)($entry['expires'] ?? 0) < $now)
            {
                unset($bucket[$existingKey]);
            }
        }

        $bucket[$key] = [
            'expires' => $now + $ttl,
            'assessment' => $this->compactAssessment($assessment)
        ];

        if (count($bucket) > self::MAX_ENTRIES)
        {
            uasort($bucket, static fn(array $a, array $b) => ((int)($a['expires'] ?? 0)) <=> ((int)($b['expires'] ?? 0)));
            while (count($bucket) > self::MAX_ENTRIES)
            {
                array_shift($bucket);
            }
        }

        \XF::app()->simpleCache()->setValue(self::CACHE_SET, self::CACHE_KEY, $bucket);
    }

    protected function compactAssessment(array $assessment): array
    {
        $provider = is_array($assessment['provider'] ?? null) ? $assessment['provider'] : [];
        $signals = [];
        foreach ((array)($assessment['signals'] ?? []) as $signal)
        {
            if (!is_scalar($signal)) continue;
            $signal = trim((string)$signal);
            if ($signal !== '') $signals[] = mb_substr($signal, 0, 160, 'UTF-8');
            if (count($signals) >= 6) break;
        }

        return [
            'provider' => $provider,
            'available' => true,
            'risk_score' => max(0, min(100, (int)($assessment['risk_score'] ?? 0))),
            'confidence' => max(0, min(100, (int)($assessment['confidence'] ?? 0))),
            'usage_type' => (string)($assessment['usage_type'] ?? 'unknown'),
            'signals' => $signals,
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'reused' => true
            ],
            'note' => mb_substr((string)($assessment['note'] ?? ''), 0, 300, 'UTF-8'),
            'shared_reuse' => true
        ];
    }

    protected function makeKey(string $message, string $provider, string $model): string
    {
        $text = $this->canonicalText($message);
        if ($text === '') return '';
        return hash('sha256', strtolower(trim($provider)) . "\n" . trim($model) . "\n" . $text);
    }

    protected function canonicalText(string $message): string
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
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
