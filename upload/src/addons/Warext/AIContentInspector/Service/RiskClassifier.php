<?php

namespace Warext\AIContentInspector\Service;

final class RiskClassifier
{
    public static function classify(int $risk): string
    {
        return self::classifyWithConfidence($risk, 100);
    }

    public static function classifyWithConfidence(int $risk, int $confidence): string
    {
        $risk = max(0, min(100, $risk));
        $confidence = max(0, min(100, $confidence));

        // Düşük risk + düşük/orta güven, "insan" kanıtı değildir. Böyle bir
        // durumda moderatöre belirsiz sonuç göstermek yanlış güven vermekten iyidir.
        if ($risk < 45 && $confidence < 65)
        {
            return 'unknown';
        }

        if ($risk < 25) return 'human_likely';
        if ($risk < 45) return 'low_ai_signal';
        if ($risk < 65) return 'ai_assistance_possible';
        if ($risk < 80) return 'ai_heavy_possible';
        return 'high_risk';
    }
}
