<?php

namespace Warext\AIContentInspector\Service;

final class RiskClassifier
{
    public static function classify(int $risk): string
    {
        $risk = max(0, min(100, $risk));

        if ($risk < 25) return 'human_likely';
        if ($risk < 45) return 'low_ai_signal';
        if ($risk < 65) return 'ai_assistance_possible';
        if ($risk < 80) return 'ai_heavy_possible';
        return 'high_risk';
    }
}
