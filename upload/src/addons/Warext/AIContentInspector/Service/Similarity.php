<?php

namespace Warext\AIContentInspector\Service;

class Similarity
{
    public function fingerprint(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($words) < 4) return '';

        $weights = array_fill(0, 64, 0);
        for ($i = 0; $i <= count($words) - 4; $i++)
        {
            $hash = hash('sha256', implode(' ', array_slice($words, $i, 4)), true);
            for ($bit = 0; $bit < 64; $bit++)
            {
                $set = (ord($hash[intdiv($bit, 8)]) >> ($bit % 8)) & 1;
                $weights[$bit] += $set ? 1 : -1;
            }
        }

        $bytes = array_fill(0, 8, 0);
        for ($bit = 0; $bit < 64; $bit++) if ($weights[$bit] >= 0) $bytes[intdiv($bit, 8)] |= 1 << ($bit % 8);
        return bin2hex(pack('C*', ...$bytes));
    }

    public function compare(string $fingerprint, array $rows, int $currentPostId): array
    {
        if ($fingerprint === '') return ['similarity' => 0, 'matches' => []];
        $best = 0; $matches = [];
        foreach ($rows as $row)
        {
            if ((int)($row['post_id'] ?? 0) === $currentPostId) continue;
            $other = (string)($row['content_fingerprint'] ?? '');
            if (strlen($other) !== 16) continue;
            $distance = $this->hammingDistance($fingerprint, $other);
            $similarity = (int)round((1 - ($distance / 64)) * 100);
            $best = max($best, $similarity);
            if ($similarity >= 78) $matches[] = ['post_id' => (int)$row['post_id'], 'similarity' => $similarity];
        }
        usort($matches, fn(array $a, array $b) => $b['similarity'] <=> $a['similarity']);
        return ['similarity' => $best, 'matches' => array_slice($matches, 0, 5)];
    }

    protected function hammingDistance(string $a, string $b): int
    {
        $a = hex2bin($a); $b = hex2bin($b);
        if ($a === false || $b === false) return 64;
        $distance = 0;
        for ($i = 0; $i < 8; $i++) { $value = ord($a[$i]) ^ ord($b[$i]); while ($value) { $distance++; $value &= $value - 1; } }
        return $distance;
    }
}
