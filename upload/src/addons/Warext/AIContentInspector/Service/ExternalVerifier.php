<?php

namespace Warext\AIContentInspector\Service;

use Warext\AIContentInspector\Provider\Registry;

class ExternalVerifier
{
    public function enrich(string $message, array $result, array $context = []): array
    {
        $registry = new Registry();
        $config = $registry->externalConfig();
        $providerId = (string)($config['provider'] ?? $registry->selectedExternalId());
        $minimumRisk = max(0, min(100, (int)($config['minimum_local_risk'] ?? 100)));
        $localRisk = max(0, min(100, (int)($result['risk_score'] ?? 0)));
        $postId = max(0, (int)($context['post_id'] ?? 0));

        $external = [
            'enabled' => $registry->isExternalEnabled(),
            'available' => false,
            'provider' => $providerId,
            'model' => (string)($config['model'] ?? ''),
            'weight' => 0,
            'minimum_local_risk' => $minimumRisk,
            'skipped' => false,
            'budget' => [],
            'result' => []
        ];

        if (!$external['enabled'])
        {
            $result['external_verification'] = $external;
            return $result;
        }

        if ($localRisk < $minimumRisk)
        {
            $external['skipped'] = true;
            $external['result'] = ['reason' => 'below_local_risk_threshold'];
            $result['external_verification'] = $external;
            return $result;
        }

        $provider = $registry->external();
        if (!$provider)
        {
            $external['result'] = ['reason' => 'provider_not_implemented'];
            $result['external_verification'] = $external;
            return $result;
        }
        if (!$provider->isConfigured())
        {
            $external['result'] = ['reason' => 'not_configured'];
            $result['external_verification'] = $external;
            return $result;
        }

        $usageTracker = new UsageTracker();
        $budget = $usageTracker->canRequest($providerId);
        $external['budget'] = $budget;
        if (empty($budget['allowed']))
        {
            $external['skipped'] = true;
            $external['result'] = ['reason' => (string)($budget['reason'] ?? 'budget_limit')];
            $result['external_verification'] = $external;
            $result['signals'][] = [
                'key' => 'external_budget_limit',
                'level' => 'context',
                'value' => (string)($budget['reason'] ?? 'budget_limit'),
                'provider' => $providerId
            ];
            return $result;
        }

        $assessment = $provider->analyze($message, [
            'max_chars' => (int)($config['max_chars'] ?? 12000)
        ]);
        $recordedUsage = $usageTracker->record(
            $providerId,
            (string)($assessment['provider']['model'] ?? $external['model']),
            $assessment,
            $postId
        );

        if (!isset($assessment['usage']) || !is_array($assessment['usage']))
        {
            $assessment['usage'] = [];
        }
        $assessment['usage']['prompt_tokens'] = (int)$recordedUsage['prompt_tokens'];
        $assessment['usage']['completion_tokens'] = (int)$recordedUsage['completion_tokens'];
        $assessment['usage']['total_tokens'] = (int)$recordedUsage['total_tokens'];
        $assessment['usage']['cost_source'] = (string)$recordedUsage['cost_source'];
        if ($recordedUsage['cost_source'] !== 'unknown')
        {
            $assessment['usage']['cost'] = (float)$recordedUsage['cost'];
        }

        $external['result'] = $assessment;
        $external['available'] = !empty($assessment['available']);
        $external['provider'] = (string)($assessment['provider']['id'] ?? $providerId);
        $external['model'] = (string)($assessment['provider']['model'] ?? $external['model']);
        $external['fallback_models'] = (array)($assessment['provider']['fallback_models'] ?? []);
        $external['zdr_only'] = !empty($assessment['provider']['zdr_only']);
        $external['usage_summary'] = $usageTracker->summary();

        if (!$external['available'])
        {
            $result['external_verification'] = $external;
            $result['signals'][] = ['key' => 'external_verifier_unavailable', 'level' => 'context', 'value' => (string)($assessment['reason'] ?? 'unknown')];
            return $result;
        }

        $baseWeight = max(0, min(40, (int)($config['weight'] ?? 0)));
        $providerConfidence = max(0, min(100, (int)($assessment['confidence'] ?? 0)));
        $effectiveWeight = ($baseWeight / 100) * ($providerConfidence / 100);
        $external['weight'] = round($effectiveWeight * 100, 2);

        $externalRisk = max(0, min(100, (int)($assessment['risk_score'] ?? 0)));
        $combined = (int)round(($localRisk * (1 - $effectiveWeight)) + ($externalRisk * $effectiveWeight));

        $result['risk_score'] = max(0, min(100, $combined));
        $result['confidence'] = min(98, (int)($result['confidence'] ?? 0) + (int)round(8 * $effectiveWeight));
        $result['classification'] = RiskClassifier::classify($result['risk_score']);
        $result['external_verification'] = $external;
        $result['signals'][] = [
            'key' => 'external_provider_verification',
            'level' => 'context',
            'value' => $externalRisk,
            'provider' => $external['provider']
        ];

        return $result;
    }
}
