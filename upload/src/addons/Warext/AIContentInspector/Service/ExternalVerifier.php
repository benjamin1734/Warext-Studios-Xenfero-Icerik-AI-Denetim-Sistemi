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
        $forceExternal = !empty($context['force_external']);
        $manual = !empty($context['manual']);

        $external = [
            'enabled' => $registry->isExternalEnabled(),
            'available' => false,
            'provider' => $providerId,
            'model' => (string)($config['model'] ?? ''),
            'weight' => 0,
            'minimum_local_risk' => $minimumRisk,
            'forced' => $forceExternal,
            'manual' => $manual,
            'skipped' => false,
            'budget' => [],
            'result' => [],
            'fusion' => [
                'local_risk' => $localRisk,
                'external_risk' => null,
                'provider_confidence' => null,
                'effective_weight' => 0,
                'combined_risk' => $localRisk,
                'disagreement' => false
            ]
        ];

        if (!$external['enabled'])
        {
            $result['external_verification'] = $external;
            return $result;
        }

        if (!$forceExternal && $localRisk < $minimumRisk)
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
            $result['signals'][] = [
                'key' => 'external_verifier_unavailable',
                'level' => 'context',
                'value' => (string)($assessment['reason'] ?? 'unknown')
            ];
            return $result;
        }

        $baseWeight = max(0, min(40, (int)($config['weight'] ?? 0)));
        $providerConfidence = max(0, min(100, (int)($assessment['confidence'] ?? 0)));
        $effectiveWeight = ($baseWeight / 100) * ($providerConfidence / 100);

        if ($forceExternal && $providerConfidence >= 60)
        {
            $manualFloor = min(0.48, 0.28 + (($providerConfidence - 60) / 250));
            $effectiveWeight = max($effectiveWeight, $manualFloor);
        }

        $externalRisk = max(0, min(100, (int)($assessment['risk_score'] ?? 0)));
        $combined = (int)round(($localRisk * (1 - $effectiveWeight)) + ($externalRisk * $effectiveWeight));
        $disagreement = abs($externalRisk - $localRisk) >= 35;

        if ($forceExternal && $providerConfidence >= 80 && $externalRisk >= 85 && $localRisk < 45)
        {
            $combined = max($combined, 65);
        }
        elseif ($forceExternal && $providerConfidence >= 75 && $externalRisk >= 75 && $localRisk < 45)
        {
            $combined = max($combined, 58);
        }

        if ($forceExternal && $providerConfidence >= 80 && $externalRisk <= 20 && $localRisk >= 80)
        {
            $combined = max($combined, 62);
        }

        $confidence = (int)($result['confidence'] ?? 0);
        if ($forceExternal)
        {
            if ($disagreement)
            {
                $confidence = max($confidence, min(82, (int)round(($confidence + $providerConfidence) / 2)));
            }
            else
            {
                $confidence = min(96, max($confidence, (int)round(($confidence * 0.55) + ($providerConfidence * 0.45))));
            }
        }
        else
        {
            $confidence = min(98, $confidence + (int)round(8 * $effectiveWeight));
        }

        $combined = max(0, min(100, $combined));
        $external['weight'] = round($effectiveWeight * 100, 2);
        $external['fusion'] = [
            'local_risk' => $localRisk,
            'external_risk' => $externalRisk,
            'provider_confidence' => $providerConfidence,
            'effective_weight' => round($effectiveWeight * 100, 2),
            'combined_risk' => $combined,
            'disagreement' => $disagreement
        ];

        $result['risk_score'] = $combined;
        $result['confidence'] = $confidence;
        $result['classification'] = RiskClassifier::classifyWithConfidence($combined, $confidence);
        $result['external_verification'] = $external;
        $result['signals'][] = [
            'key' => 'external_provider_verification',
            'level' => 'context',
            'value' => $externalRisk,
            'provider' => $external['provider']
        ];

        if ($disagreement)
        {
            $result['signals'][] = [
                'key' => 'local_external_disagreement',
                'level' => 'context',
                'value' => abs($externalRisk - $localRisk),
                'local' => $localRisk,
                'external' => $externalRisk,
                'provider_confidence' => $providerConfidence
            ];
        }

        return $result;
    }
}
