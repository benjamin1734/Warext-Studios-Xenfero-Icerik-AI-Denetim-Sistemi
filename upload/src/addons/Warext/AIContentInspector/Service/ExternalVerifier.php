<?php

namespace Warext\AIContentInspector\Service;

use Warext\AIContentInspector\Provider\Registry;

class ExternalVerifier
{
    public function enrich(string $message, array $result): array
    {
        $options = \XF::options();
        $localRisk = max(0, min(100, (int)($result['risk_score'] ?? 0)));
        $minimumRisk = max(0, min(100, (int)($options->warextAiOpenRouterMinRisk ?? 45)));

        $external = [
            'enabled' => !empty($options->warextAiOpenRouterEnabled),
            'available' => false,
            'provider' => 'openrouter',
            'model' => (string)($options->warextAiOpenRouterModel ?? 'openrouter/auto'),
            'weight' => 0,
            'minimum_local_risk' => $minimumRisk,
            'skipped' => false,
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

        $provider = (new Registry())->openRouter();
        if (!$provider->isConfigured())
        {
            $external['result'] = ['reason' => 'not_configured'];
            $result['external_verification'] = $external;
            return $result;
        }

        $assessment = $provider->analyze($message, [
            'max_chars' => (int)($options->warextAiOpenRouterMaxChars ?? 12000)
        ]);
        $external['result'] = $assessment;
        $external['available'] = !empty($assessment['available']);
        $external['model'] = (string)($assessment['provider']['model'] ?? $external['model']);
        $external['fallback_models'] = (array)($assessment['provider']['fallback_models'] ?? []);
        $external['zdr_only'] = !empty($assessment['provider']['zdr_only']);

        if (!$external['available'])
        {
            $result['external_verification'] = $external;
            $result['signals'][] = ['key' => 'external_verifier_unavailable', 'level' => 'context', 'value' => (string)($assessment['reason'] ?? 'unknown')];
            return $result;
        }

        $baseWeight = max(5, min(40, (int)($options->warextAiOpenRouterWeight ?? 20)));
        $providerConfidence = max(0, min(100, (int)($assessment['confidence'] ?? 0)));
        $effectiveWeight = ($baseWeight / 100) * ($providerConfidence / 100);
        $external['weight'] = round($effectiveWeight * 100, 2);

        $externalRisk = max(0, min(100, (int)($assessment['risk_score'] ?? 0)));
        $combined = (int)round(($localRisk * (1 - $effectiveWeight)) + ($externalRisk * $effectiveWeight));

        $result['risk_score'] = max(0, min(100, $combined));
        $result['confidence'] = min(98, (int)($result['confidence'] ?? 0) + (int)round(8 * $effectiveWeight));
        $result['classification'] = $this->classification($result['risk_score']);
        $result['external_verification'] = $external;
        $result['signals'][] = [
            'key' => 'openrouter_verification',
            'level' => 'context',
            'value' => $externalRisk
        ];

        return $result;
    }

    protected function classification(int $risk): string
    {
        if ($risk < 30) return 'human_likely';
        if ($risk < 50) return 'low_ai_signal';
        if ($risk < 70) return 'ai_assistance_possible';
        if ($risk < 85) return 'ai_heavy_possible';
        return 'high_risk';
    }
}
