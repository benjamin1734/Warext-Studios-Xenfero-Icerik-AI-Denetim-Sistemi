<?php

namespace Warext\AIContentInspector\Service;

/**
 * Local engine calibration layer.
 *
 * Analyzer metrics are intentionally heuristic. This layer prevents a long text
 * with several independent AI-style signals from being treated as low-risk just
 * because none of those signals is individually extreme.
 */
final class LocalCalibration
{
    public const ENGINE_VERSION = '1.1.4';

    public static function apply(array $result): array
    {
        $metrics = is_array($result['text_metrics'] ?? null) ? $result['text_metrics'] : [];
        $risk = max(0, min(100, (int)($result['risk_score'] ?? 0)));
        $confidence = max(0, min(100, (int)($result['confidence'] ?? 0)));

        $words = max(0, (int)($metrics['words'] ?? 0));
        $sentences = max(0, (int)($metrics['sentences'] ?? 0));
        $signalCount = max(0, (int)($metrics['combined_signal_count'] ?? 0));
        $sentenceUniformity = max(0.0, min(1.0, (float)($metrics['sentence_uniformity'] ?? 0)));
        $paragraphUniformity = max(0.0, min(1.0, (float)($metrics['paragraph_uniformity'] ?? 0)));
        $templateDensity = max(0.0, min(1.0, (float)($metrics['template_density'] ?? 0)));
        $formulaicDensity = max(0.0, min(1.0, (float)($metrics['formulaic_density'] ?? 0)));
        $transitionRatio = max(0.0, min(1.0, (float)($metrics['transition_opening_ratio'] ?? 0)));
        $formalCadence = max(0.0, min(1.0, (float)($metrics['formal_cadence'] ?? 0)));
        $structureDensity = max(0.0, min(1.0, (float)($metrics['structure_density'] ?? 0)));

        $rawRisk = $risk;
        $floor = 0;
        $reason = '';

        // Five or more independent signals in a reasonably sized text is not a
        // weak observation anymore. It is still not proof, therefore the floor
        // deliberately remains below "confirmed/high risk" territory.
        if ($words >= 80 && $sentences >= 6)
        {
            if ($signalCount >= 7)
            {
                $floor = 78;
                $reason = 'seven_plus_independent_signals';
            }
            elseif ($signalCount >= 6)
            {
                $floor = 73;
                $reason = 'six_independent_signals';
            }
            elseif ($signalCount >= 5)
            {
                $floor = 68;
                $reason = 'five_independent_signals';
            }
            elseif (
                $signalCount >= 4
                && $sentenceUniformity >= 0.58
                && ($templateDensity >= 0.18 || $formulaicDensity >= 0.18 || $transitionRatio >= 0.18)
            )
            {
                $floor = 57;
                $reason = 'four_signals_with_regular_cadence';
            }
        }

        // Phrase-light AI output often avoids obvious transition templates but
        // still keeps an unusually even rhythm and paragraph/structure balance.
        $statisticalVotes = 0;
        $statisticalVotes += $sentenceUniformity >= 0.66 ? 1 : 0;
        $statisticalVotes += $paragraphUniformity >= 0.60 ? 1 : 0;
        $statisticalVotes += $formalCadence >= 0.38 ? 1 : 0;
        $statisticalVotes += $structureDensity >= 0.18 ? 1 : 0;
        $statisticalVotes += ($templateDensity + $formulaicDensity + $transitionRatio) >= 0.42 ? 1 : 0;

        if ($words >= 140 && $sentences >= 8 && $statisticalVotes >= 4 && $floor < 62)
        {
            $floor = 62;
            $reason = 'phrase_light_statistical_ensemble';
        }

        if ($floor > $risk)
        {
            // Do not jump from a tiny raw score to the full floor in one opaque
            // step. Blend toward the calibrated floor while retaining the raw
            // score for moderator inspection.
            $blend = $signalCount >= 6 ? 0.92 : 0.82;
            $risk = (int)round($risk + (($floor - $risk) * $blend));
            $risk = max($rawRisk, min(100, $risk));
            $confidence = min(96, $confidence + min(10, max(3, $signalCount)));

            $result['signals'] = is_array($result['signals'] ?? null) ? $result['signals'] : [];
            $result['signals'][] = [
                'key' => 'local_ensemble_calibration',
                'level' => $risk >= 65 ? 'high' : 'medium',
                'value' => $risk,
                'raw' => $rawRisk,
                'signal_count' => $signalCount,
                'reason' => $reason
            ];
        }

        $metrics['engine_version'] = self::ENGINE_VERSION;
        $metrics['raw_local_risk'] = $rawRisk;
        $metrics['calibrated_local_risk'] = $risk;
        $metrics['calibration_floor'] = $floor;
        $metrics['calibration_reason'] = $reason;
        $metrics['statistical_vote_count'] = $statisticalVotes;

        $result['text_metrics'] = $metrics;
        $result['risk_score'] = $risk;
        $result['confidence'] = $confidence;
        $result['classification'] = RiskClassifier::classifyWithConfidence($risk, $confidence);

        return $result;
    }
}
