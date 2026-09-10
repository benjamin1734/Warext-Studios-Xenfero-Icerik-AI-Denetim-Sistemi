<?php

namespace Warext\AIContentInspector\Service;

class UserProfile
{
    public function enrich(int $userId, int $postId, array $result): array
    {
        $profile = [
            'available' => false,
            'sample_count' => 0,
            'deviation_score' => 0,
            'risk_adjustment' => 0.0,
            'baseline' => [],
            'current' => [],
            'note' => 'Kullanıcı geçmişi için yeterli örnek bulunamadı.'
        ];

        if ($userId <= 0)
        {
            $result['profile_metrics'] = $profile;
            return $result;
        }

        $rows = \XF::db()->fetchAll(
            'SELECT post_id, risk_score, text_metrics, behavior_metrics, writing_metrics
             FROM xf_warext_ai_analysis
             WHERE user_id = ? AND post_id <> ?
             ORDER BY analyzed_date DESC
             LIMIT 25',
            [$userId, $postId]
        );

        $samples = [];
        foreach ($rows as $row)
        {
            $text = $this->decode($row['text_metrics'] ?? null);
            $behavior = $this->decode($row['behavior_metrics'] ?? null);
            $writing = $this->decode($row['writing_metrics'] ?? null);
            if (!$text) continue;

            $samples[] = [
                'risk' => (float)($row['risk_score'] ?? 0),
                'sentence_uniformity' => (float)($text['sentence_uniformity'] ?? 0),
                'lexical_diversity' => (float)($text['lexical_diversity'] ?? 0),
                'local_text_risk' => (float)($text['local_text_risk'] ?? 0),
                'paste_ratio' => $this->pasteRatio($behavior),
                'correction_ratio' => $this->correctionRatio($writing, (int)($text['chars'] ?? 0))
            ];
        }

        $count = count($samples);
        $profile['sample_count'] = $count;
        if ($count < 3)
        {
            $result['profile_metrics'] = $profile;
            return $result;
        }

        $text = is_array($result['text_metrics'] ?? null) ? $result['text_metrics'] : [];
        $behavior = is_array($result['behavior_metrics'] ?? null) ? $result['behavior_metrics'] : [];
        $writing = is_array($result['writing_metrics'] ?? null) ? $result['writing_metrics'] : [];

        $current = [
            'risk' => (float)($result['risk_score'] ?? 0),
            'sentence_uniformity' => (float)($text['sentence_uniformity'] ?? 0),
            'lexical_diversity' => (float)($text['lexical_diversity'] ?? 0),
            'local_text_risk' => (float)($text['local_text_risk'] ?? 0),
            'paste_ratio' => $this->pasteRatio($behavior),
            'correction_ratio' => $this->correctionRatio($writing, (int)($text['chars'] ?? 0))
        ];

        $baseline = [];
        $spread = [];
        foreach (array_keys($current) as $key)
        {
            $values = array_map(fn(array $sample) => (float)$sample[$key], $samples);
            $baseline[$key] = $this->mean($values);
            $spread[$key] = $this->standardDeviation($values, $baseline[$key]);
        }

        $zScores = [
            'local_text_risk' => $this->z($current['local_text_risk'], $baseline['local_text_risk'], max(8.0, $spread['local_text_risk'])),
            'sentence_uniformity' => $this->z($current['sentence_uniformity'], $baseline['sentence_uniformity'], max(0.10, $spread['sentence_uniformity'])),
            'lexical_diversity' => abs($this->z($current['lexical_diversity'], $baseline['lexical_diversity'], max(0.08, $spread['lexical_diversity']))),
            'paste_ratio' => abs($this->z($current['paste_ratio'], $baseline['paste_ratio'], max(0.18, $spread['paste_ratio'])))
        ];

        $deviation = (
            min(3.0, abs($zScores['local_text_risk'])) * 0.40 +
            min(3.0, abs($zScores['sentence_uniformity'])) * 0.25 +
            min(3.0, $zScores['lexical_diversity']) * 0.15 +
            min(3.0, $zScores['paste_ratio']) * 0.20
        ) / 3.0;
        $deviationScore = (int)round(max(0, min(100, $deviation * 100)));

        $riskAdjustment = 0.0;
        if ($current['local_text_risk'] > $baseline['local_text_risk'])
        {
            $riskAdjustment += min(6.0, max(0.0, $zScores['local_text_risk']) * 2.2);
        }
        if ($current['sentence_uniformity'] > $baseline['sentence_uniformity'])
        {
            $riskAdjustment += min(2.5, max(0.0, $zScores['sentence_uniformity']) * 0.9);
        }
        if ($deviationScore < 25 && $count >= 8)
        {
            $riskAdjustment -= 1.5;
        }
        $riskAdjustment = max(-2.0, min(8.0, $riskAdjustment));

        $newRisk = (int)round(max(0, min(100, (float)$result['risk_score'] + $riskAdjustment)));
        $confidenceBonus = $count >= 12 ? 8 : ($count >= 7 ? 6 : 3);
        $result['risk_score'] = $newRisk;
        $result['confidence'] = min(96, (int)$result['confidence'] + $confidenceBonus);
        $result['classification'] = RiskClassifier::classify($newRisk);

        $profile = [
            'available' => true,
            'sample_count' => $count,
            'deviation_score' => $deviationScore,
            'risk_adjustment' => round($riskAdjustment, 2),
            'baseline' => $this->rounded($baseline),
            'current' => $this->rounded($current),
            'spread' => $this->rounded($spread),
            'note' => $deviationScore >= 60
                ? 'Bu içerik kullanıcının önceki yazım profilinden belirgin biçimde sapıyor.'
                : ($deviationScore >= 35
                    ? 'Bu içerikte kullanıcının geçmiş yazım profiline göre orta düzey sapma var.'
                    : 'Bu içerik kullanıcının geçmiş yazım profiline genel olarak uyumlu.')
        ];

        $result['profile_metrics'] = $profile;
        if ($deviationScore >= 35)
        {
            $result['signals'][] = [
                'key' => 'user_profile_deviation',
                'level' => 'context',
                'value' => $deviationScore
            ];
        }

        return $result;
    }

    protected function decode($value): array
    {
        if (!$value) return [];
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function pasteRatio(array $behavior): float
    {
        if (empty($behavior['observed'])) return 0.0;
        $typed = max(0, (int)($behavior['typedChars'] ?? 0));
        $pasted = max(0, (int)($behavior['pastedChars'] ?? 0));
        return $pasted / max(1, $typed + $pasted);
    }

    protected function correctionRatio(array $writing, int $chars): float
    {
        if (empty($writing['available']) || $chars <= 0) return 0.0;
        $messageField = is_array($writing['fields']['message'] ?? null) ? $writing['fields']['message'] : [];
        $changed = array_key_exists('changedChars', $messageField)
            ? max(0, (int)$messageField['changedChars'])
            : max(0, (int)($writing['changedChars'] ?? 0));
        return min(1.0, $changed / max(1, $chars));
    }

    protected function mean(array $values): float
    {
        return $values ? array_sum($values) / count($values) : 0.0;
    }

    protected function standardDeviation(array $values, float $mean): float
    {
        if (count($values) < 2) return 0.0;
        $variance = array_sum(array_map(fn(float $value) => ($value - $mean) ** 2, $values)) / count($values);
        return sqrt($variance);
    }

    protected function z(float $current, float $mean, float $spread): float
    {
        return ($current - $mean) / max(0.0001, $spread);
    }

    protected function rounded(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value)
        {
            $result[$key] = round((float)$value, 4);
        }
        return $result;
    }
}
