<?php

namespace Warext\AIContentInspector\Service;

use Warext\AIContentInspector\Provider\Registry;

/**
 * Shared provider gateway used by first-party Warext add-ons.
 *
 * The class deliberately has no dependency on TurkishSpellCheck. Consumers
 * discover it with class_exists(), so either add-on can be installed/removed
 * independently without creating a XenForo add-on dependency cycle.
 */
class InteropGateway
{
    public const CONTRACT_VERSION = 1;

    /** @var array<string,array{expires:int,value:array}> */
    protected static array $requestCache = [];

    public function capabilities(): array
    {
        $registry = new Registry();
        $provider = $registry->external();

        return [
            'contract' => self::CONTRACT_VERSION,
            'available' => !empty(\XF::options()->warextAiInteropEnabled)
                && $registry->isExternalEnabled()
                && $provider
                && $provider->isConfigured(),
            'provider' => $registry->selectedExternalId(),
            'shared_credentials' => true,
            'tasks' => ['moderation', 'writing'],
            'combined_request' => true,
            'result_reuse' => true
        ];
    }

    public function analyze(string $message, array $localWriting = [], array $context = []): array
    {
        $message = trim($message);
        if ($message === '')
        {
            return $this->unavailable('empty_message');
        }

        if (empty(\XF::options()->warextAiInteropEnabled))
        {
            return $this->unavailable('interop_disabled');
        }

        $registry = new Registry();
        if (!$registry->isExternalEnabled())
        {
            return $this->unavailable('external_provider_disabled');
        }

        $provider = $registry->external();
        if (!$provider || !$provider->isConfigured())
        {
            return $this->unavailable('provider_not_configured');
        }

        $config = $registry->externalConfig();
        $maxChars = max(500, min(
            50000,
            (int)(\XF::options()->warextAiInteropWritingMaxChars ?? ($config['max_chars'] ?? 8000))
        ));
        if (mb_strlen($message, 'UTF-8') > $maxChars)
        {
            $message = mb_substr($message, 0, $maxChars, 'UTF-8');
        }

        $localWriting = $this->normalizeLocalWriting($localWriting);
        $cacheSeconds = max(0, min(900, (int)(\XF::options()->warextAiInteropCacheSeconds ?? 120)));
        $cacheKey = hash('sha256', $registry->selectedExternalId() . "\n" . $message . "\n" . json_encode($localWriting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $now = time();
        if ($cacheSeconds > 0 && isset(self::$requestCache[$cacheKey]) && self::$requestCache[$cacheKey]['expires'] >= $now)
        {
            $cached = self::$requestCache[$cacheKey]['value'];
            $cached['cache_hit'] = true;
            return $cached;
        }

        $providerId = $registry->selectedExternalId();
        $usageTracker = new UsageTracker();
        $budget = $usageTracker->canRequest($providerId);
        if (empty($budget['allowed']))
        {
            $result = $this->unavailable((string)($budget['reason'] ?? 'budget_limit'));
            $result['budget'] = $budget;
            return $result;
        }

        $assessment = $provider->analyze($message, [
            'max_chars' => $maxChars,
            'tasks' => ['moderation', 'writing'],
            'writing_context' => $localWriting,
            'writing_mode' => (string)($context['writing_mode'] ?? 'hybrid'),
            'preserve_style' => true,
            'interop_contract' => self::CONTRACT_VERSION
        ]);

        $recorded = $usageTracker->record(
            $providerId,
            (string)($assessment['provider']['model'] ?? ($config['model'] ?? '')),
            $assessment,
            max(0, (int)($context['post_id'] ?? 0))
        );

        if (!isset($assessment['usage']) || !is_array($assessment['usage']))
        {
            $assessment['usage'] = [];
        }
        $assessment['usage']['prompt_tokens'] = (int)$recorded['prompt_tokens'];
        $assessment['usage']['completion_tokens'] = (int)$recorded['completion_tokens'];
        $assessment['usage']['total_tokens'] = (int)$recorded['total_tokens'];
        $assessment['usage']['cost_source'] = (string)$recorded['cost_source'];
        if (($recorded['cost_source'] ?? 'unknown') !== 'unknown')
        {
            $assessment['usage']['cost'] = (float)$recorded['cost'];
        }

        if ($cacheSeconds > 0 && !empty($assessment['available']))
        {
            (new InteropResultCache())->put(
                $message,
                $providerId,
                (string)($config['model'] ?? ''),
                $assessment,
                $cacheSeconds
            );
        }

        $result = [
            'contract' => self::CONTRACT_VERSION,
            'available' => !empty($assessment['available']),
            'source' => 'warext_ai_content_inspector',
            'shared_credentials' => true,
            'combined_request' => true,
            'result_reuse' => true,
            'provider' => $assessment['provider'] ?? ['id' => $providerId],
            'moderation' => [
                'risk_score' => (int)($assessment['risk_score'] ?? 0),
                'confidence' => (int)($assessment['confidence'] ?? 0),
                'usage_type' => (string)($assessment['usage_type'] ?? 'unknown'),
                'signals' => (array)($assessment['signals'] ?? []),
                'note' => (string)($assessment['note'] ?? '')
            ],
            'writing' => is_array($assessment['writing'] ?? null) ? $assessment['writing'] : [],
            'usage' => (array)($assessment['usage'] ?? []),
            'budget' => $budget,
            'cache_hit' => false
        ];

        if (empty($assessment['available']))
        {
            $result['reason'] = (string)($assessment['reason'] ?? 'provider_unavailable');
        }

        if ($cacheSeconds > 0 && !empty($result['available']))
        {
            self::$requestCache[$cacheKey] = ['expires' => $now + $cacheSeconds, 'value' => $result];
            if (count(self::$requestCache) > 64)
            {
                foreach (self::$requestCache as $key => $item)
                {
                    if ($item['expires'] < $now) unset(self::$requestCache[$key]);
                }
                while (count(self::$requestCache) > 64)
                {
                    array_shift(self::$requestCache);
                }
            }
        }

        return $result;
    }

    protected function normalizeLocalWriting(array $localWriting): array
    {
        $out = [];
        foreach ((array)($localWriting['issues'] ?? $localWriting) as $issue)
        {
            if (!is_array($issue)) continue;
            $original = mb_substr(trim((string)($issue['original'] ?? $issue['word'] ?? '')), 0, 120, 'UTF-8');
            $suggestions = [];
            foreach ((array)($issue['suggestions'] ?? []) as $suggestion)
            {
                if (!is_scalar($suggestion)) continue;
                $suggestion = mb_substr(trim((string)$suggestion), 0, 160, 'UTF-8');
                if ($suggestion !== '') $suggestions[] = $suggestion;
                if (count($suggestions) >= 3) break;
            }
            if ($original === '' && !$suggestions) continue;
            $out[] = [
                'start' => max(0, (int)($issue['start'] ?? 0)),
                'end' => max(0, (int)($issue['end'] ?? 0)),
                'original' => $original,
                'suggestions' => $suggestions,
                'rule' => mb_substr((string)($issue['rule'] ?? $issue['type'] ?? ''), 0, 80, 'UTF-8'),
                'confidence' => max(0, min(100, (int)($issue['confidence'] ?? 0)))
            ];
            if (count($out) >= 24) break;
        }

        return ['issues' => $out];
    }

    protected function unavailable(string $reason): array
    {
        return [
            'contract' => self::CONTRACT_VERSION,
            'available' => false,
            'source' => 'warext_ai_content_inspector',
            'shared_credentials' => true,
            'combined_request' => true,
            'result_reuse' => true,
            'reason' => $reason,
            'moderation' => [],
            'writing' => [],
            'usage' => []
        ];
    }
}
