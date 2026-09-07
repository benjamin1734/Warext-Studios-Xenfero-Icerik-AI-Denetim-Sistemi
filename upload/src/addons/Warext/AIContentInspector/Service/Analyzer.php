<?php

namespace Warext\AIContentInspector\Service;

class Analyzer
{
    public function authoredTextLength(string $message): int
    {
        [$text] = $this->prepareText($message);
        return mb_strlen($text, 'UTF-8');
    }

    public function analyze(string $message, array $behavior = [], array $writing = []): array
    {
        [$text, $excludedBlocks] = $this->prepareText($message);
        $chars = mb_strlen($text, 'UTF-8');
        $sentences = $this->sentences($text);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $text) ?: [])));
        $words = $this->words($text);
        $wordCount = count($words);

        $sentenceLengths = array_map(fn($s) => count($this->words($s)), $sentences);
        $paragraphLengths = array_map(fn($p) => count($this->words($p)), $paragraphs);

        $sentenceUniformity = $this->uniformity($sentenceLengths);
        $paragraphUniformity = $this->uniformity($paragraphLengths);
        $lexicalDiversity = $wordCount ? count(array_unique(array_map(fn($w) => mb_strtolower($w, 'UTF-8'), $words))) / $wordCount : 0.0;
        $connectorDensity = $this->connectorDensity($text, max(1, $wordCount));
        $templateDensity = $this->templateDensity($text, max(1, count($sentences)));
        $structureDensity = $this->structureDensity($text, max(1, count($paragraphs)));
        $repetition = $this->repetitionScore($sentences);

        $textRisk = 10.0;
        $textRisk += $sentenceUniformity * 21.0;
        $textRisk += $paragraphUniformity * 11.0;
        $textRisk += min(1.0, $connectorDensity * 5.0) * 14.0;
        $textRisk += $templateDensity * 17.0;
        $textRisk += $structureDensity * 9.0;
        $textRisk += $repetition * 11.0;
        if ($wordCount >= 120 && $lexicalDiversity >= 0.48 && $lexicalDiversity <= 0.72) $textRisk += 5.0;
        $textRisk = min(88.0, max(0.0, $textRisk));

        $behaviorScore = $this->behaviorScore($behavior, $chars);
        $writingAdjustment = $this->writingAdjustment($writing, $chars);

        $risk = ($textRisk * 0.82) + ($behaviorScore * 0.18) - $writingAdjustment;
        $risk = (int) round(max(0, min(100, $risk)));

        $confidence = 28;
        if ($chars >= 500) $confidence += 12;
        if ($chars >= 1200) $confidence += 13;
        if ($chars >= 2500) $confidence += 10;
        if (count($sentences) >= 8) $confidence += 8;
        if (!empty($behavior['observed'])) $confidence += 7;
        if (!empty($writing['available'])) $confidence += 4;
        $confidence = max(20, min(92, $confidence));

        $classification = $this->classification($risk);
        $signals = $this->signals($sentenceUniformity, $paragraphUniformity, $connectorDensity, $templateDensity, $structureDensity, $repetition, $behaviorScore, $writingAdjustment);
        if ($excludedBlocks > 0)
        {
            $signals[] = ['key' => 'excluded_non_authored_blocks', 'level' => 'context', 'value' => $excludedBlocks];
        }

        return [
            'risk_score' => $risk,
            'confidence' => $confidence,
            'classification' => $classification,
            'text_metrics' => [
                'chars' => $chars,
                'words' => $wordCount,
                'sentences' => count($sentences),
                'paragraphs' => count($paragraphs),
                'excluded_blocks' => $excludedBlocks,
                'sentence_uniformity' => round($sentenceUniformity, 4),
                'paragraph_uniformity' => round($paragraphUniformity, 4),
                'lexical_diversity' => round($lexicalDiversity, 4),
                'connector_density' => round($connectorDensity, 4),
                'template_density' => round($templateDensity, 4),
                'structure_density' => round($structureDensity, 4),
                'repetition' => round($repetition, 4),
                'local_text_risk' => round($textRisk, 2)
            ],
            'behavior_metrics' => $behavior,
            'writing_metrics' => $writing,
            'signals' => $signals
        ];
    }

    protected function prepareText(string $message): array
    {
        $text = $message;
        $excludedBlocks = 0;
        $pattern = '/\[(QUOTE|CODE|PHP|HTML|ICODE|PLAIN)(?:=[^\]]*)?\][\s\S]*?\[\/\1\]/iu';

        for ($pass = 0; $pass < 5; $pass++)
        {
            $count = 0;
            $next = preg_replace($pattern, ' ', $text, -1, $count);
            if ($next === null || $count === 0) break;
            $text = $next;
            $excludedBlocks += $count;
        }

        $text = preg_replace('/\[[^\]]{1,200}\]/u', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/https?:\/\/\S+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return [trim($text), $excludedBlocks];
    }

    protected function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?…])\s+|\R+/u', $text) ?: []), fn($s) => mb_strlen($s, 'UTF-8') >= 8));
    }

    protected function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $matches);
        return $matches[0] ?? [];
    }

    protected function uniformity(array $values): float
    {
        $values = array_values(array_filter($values, fn($v) => $v > 0));
        if (count($values) < 3) return 0.0;
        $avg = array_sum($values) / count($values);
        if ($avg <= 0) return 0.0;
        $variance = array_sum(array_map(fn($v) => ($v - $avg) ** 2, $values)) / count($values);
        $cv = sqrt($variance) / $avg;
        return max(0.0, min(1.0, 1.0 - ($cv / 0.85)));
    }

    protected function connectorDensity(string $text, int $wordCount): float
    {
        $connectors = ['ayrıca','bununla birlikte','öte yandan','dolayısıyla','sonuç olarak','bu nedenle','özellikle','örneğin','ancak','buna ek olarak','kısacası','genel olarak'];
        $lower = mb_strtolower($text, 'UTF-8');
        $count = 0;
        foreach ($connectors as $connector) $count += substr_count($lower, $connector);
        return $count / $wordCount;
    }

    protected function templateDensity(string $text, int $sentenceCount): float
    {
        $patterns = [
            '/\bsonuç olarak\b/iu', '/\bözetle\b/iu', '/\bbununla birlikte\b/iu',
            '/\bdikkat edilmesi gereken\b/iu', '/\bşu şekilde\b/iu', '/\btemel olarak\b/iu',
            '/\bbu noktada\b/iu', '/\bönemli bir nokta\b/iu'
        ];
        $count = 0;
        foreach ($patterns as $pattern) $count += preg_match_all($pattern, $text) ?: 0;
        return max(0.0, min(1.0, $count / max(3, $sentenceCount * 0.35)));
    }

    protected function structureDensity(string $text, int $paragraphCount): float
    {
        $headings = preg_match_all('/(?:^|\R)\s*(?:#{1,4}\s+|\d+[.)]\s+|[-*•]\s+)/u', $text) ?: 0;
        return max(0.0, min(1.0, $headings / max(4, $paragraphCount * 1.5)));
    }

    protected function repetitionScore(array $sentences): float
    {
        if (count($sentences) < 5) return 0.0;
        $starts = [];
        foreach ($sentences as $sentence) {
            $words = $this->words(mb_strtolower($sentence, 'UTF-8'));
            if (count($words) >= 2) $starts[] = implode(' ', array_slice($words, 0, 2));
        }
        if (!$starts) return 0.0;
        return max(0.0, min(1.0, 1.0 - (count(array_unique($starts)) / count($starts))));
    }

    protected function behaviorScore(array $behavior, int $chars): float
    {
        if (empty($behavior['observed'])) return 35.0;
        $typed = max(0, (int)($behavior['typedChars'] ?? 0));
        $pasted = max(0, (int)($behavior['pastedChars'] ?? 0));
        $duration = max(0, (int)($behavior['durationSeconds'] ?? 0));
        $total = max(1, $typed + $pasted);
        $pasteRatio = $pasted / $total;
        $score = 20.0 + ($pasteRatio * 48.0);
        if ($chars >= 800 && $duration > 0 && $duration <= 25) $score += 16.0;
        if ($chars >= 1800 && $duration > 0 && $duration <= 60) $score += 10.0;
        return max(0.0, min(100.0, $score));
    }

    protected function writingAdjustment(array $writing, int $chars): float
    {
        if (empty($writing['available']) || $chars <= 0) return 0.0;
        $messageField = is_array($writing['fields']['message'] ?? null) ? $writing['fields']['message'] : [];
        $changed = array_key_exists('changedChars', $messageField)
            ? max(0, (int)$messageField['changedChars'])
            : max(0, (int)($writing['changedChars'] ?? 0));
        $ratio = min(1.0, $changed / max(1, $chars));
        return min(8.0, $ratio * 8.0);
    }

    protected function classification(int $risk): string
    {
        if ($risk < 30) return 'human_likely';
        if ($risk < 50) return 'low_ai_signal';
        if ($risk < 70) return 'ai_assistance_possible';
        if ($risk < 85) return 'ai_heavy_possible';
        return 'high_risk';
    }

    protected function signals(float $sentenceUniformity, float $paragraphUniformity, float $connectorDensity, float $templateDensity, float $structureDensity, float $repetition, float $behaviorScore, float $writingAdjustment): array
    {
        $signals = [];
        if ($sentenceUniformity >= .62) $signals[] = ['key' => 'sentence_uniformity', 'level' => 'medium', 'value' => round($sentenceUniformity * 100)];
        if ($paragraphUniformity >= .62) $signals[] = ['key' => 'paragraph_uniformity', 'level' => 'low', 'value' => round($paragraphUniformity * 100)];
        if ($connectorDensity >= .012) $signals[] = ['key' => 'connector_density', 'level' => 'medium', 'value' => round($connectorDensity * 1000) / 10];
        if ($templateDensity >= .40) $signals[] = ['key' => 'template_language', 'level' => 'medium', 'value' => round($templateDensity * 100)];
        if ($structureDensity >= .45) $signals[] = ['key' => 'structured_format', 'level' => 'low', 'value' => round($structureDensity * 100)];
        if ($repetition >= .25) $signals[] = ['key' => 'repetitive_openings', 'level' => 'low', 'value' => round($repetition * 100)];
        if ($behaviorScore >= 68) $signals[] = ['key' => 'editor_behavior', 'level' => 'context', 'value' => round($behaviorScore)];
        if ($writingAdjustment > 0) $signals[] = ['key' => 'writing_checker_used', 'level' => 'context', 'value' => round($writingAdjustment, 2)];
        return $signals;
    }
}
