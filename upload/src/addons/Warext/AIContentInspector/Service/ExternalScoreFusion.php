<?php

namespace Warext\AIContentInspector\Service;

final class ExternalScoreFusion
{
    public static function combine(
        int $localRisk,
        int $localConfidence,
        int $externalRisk,
        int $providerConfidence,
        int $baseWeight,
        bool $forceExternal = false
    ): array
    {
        $localRisk = max(0, min(100, $localRisk));
        $localConfidence = max(0, min(100, $localConfidence));
        $externalRisk = max(0, min(100, $externalRisk));
        $providerConfidence = max(0, min(100, $providerConfidence));
        $baseWeight = max(0, min(40, $baseWeight));

        $effectiveWeight = ($baseWeight / 100) * ($providerConfidence / 100);
        if ($forceExternal && $providerConfidence >= 60)
        {
            $manualFloor = min(0.48, 0.28 + (($providerConfidence - 60) / 250));
            $effectiveWeight = max($effectiveWeight, $manualFloor);
        }

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

        if ($forceExternal)
        {
            if ($disagreement)
            {
                $confidence = max($localConfidence, min(82, (int)round(($localConfidence + $providerConfidence) / 2)));
            }
            else
            {
                $confidence = min(96, max($localConfidence, (int)round(($localConfidence * 0.55) + ($providerConfidence * 0.45))));
            }
        }
        else
        {
            $confidence = min(98, $localConfidence + (int)round(8 * $effectiveWeight));
        }

        return [
            'risk' => max(0, min(100, $combined)),
            'confidence' => max(0, min(100, $confidence)),
            'effective_weight' => round($effectiveWeight * 100, 2),
            'disagreement' => $disagreement
        ];
    }
}
