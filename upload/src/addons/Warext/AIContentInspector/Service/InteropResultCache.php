<?php

namespace Warext\AIContentInspector\Service;

class InteropResultCache
{
    protected const TABLE = 'xf_warext_ai_interop_cache';

    public function get(string $message, string $provider, string $model): ?array
    {
        $key = $this->makeKey($message, $provider, $model);
        if ($key === '') return null;

        try
        {
            $row = \XF::db()->fetchRow(
                'SELECT payload, expires_date FROM ' . self::TABLE . ' WHERE cache_key = ?',
                $key
            );
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Interop Cache Read: ');
            return null;
        }

        if (!$row) return null;
        if ((int)($row['expires_date'] ?? 0) < time())
        {
            try { \XF::db()->delete(self::TABLE, 'cache_key = ?', $key); } catch (\Throwable) {}
            return null;
        }

        $assessment = json_decode((string)($row['payload'] ?? ''), true);
        if (!is_array($assessment) || empty($assessment['available'])) return null;
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

        $payload = json_encode($this->compactAssessment($assessment), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') return;

        try
        {
            \XF::db()->insert(self::TABLE, [
                'cache_key' => $key,
                'payload' => $payload,
                'expires_date' => time() + $ttl
            ], false, 'payload = VALUES(payload), expires_date = VALUES(expires_date)');

            // Avoid one global-cache write per keystroke. Expired DB rows are pruned
            // probabilistically here and deterministically by the daily cron.
            if ((hexdec(substr($key, 0, 2)) & 15) === 0)
            {
                $this->prune();
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Interop Cache Write: ');
        }
    }

    public function prune(): int
    {
        try
        {
            return \XF::db()->delete(self::TABLE, 'expires_date < ?', time());
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Interop Cache Prune: ');
            return 0;
        }
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
            'provider' => [
                'id' => (string)($provider['id'] ?? ''),
                'label' => (string)($provider['label'] ?? ''),
                'external' => !empty($provider['external']),
                'model' => (string)($provider['model'] ?? ''),
                'requested_model' => (string)($provider['requested_model'] ?? '')
            ],
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
