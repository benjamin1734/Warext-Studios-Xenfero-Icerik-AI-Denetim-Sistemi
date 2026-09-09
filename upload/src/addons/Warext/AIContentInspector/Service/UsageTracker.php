<?php

namespace Warext\AIContentInspector\Service;

class UsageTracker
{
    public function canRequest(string $providerId): array
    {
        $options = \XF::options();
        $dailyRequests = max(0, (int)($options->warextAiDailyRequestLimit ?? 0));
        $dailyBudget = max(0.0, (float)($options->warextAiDailyBudgetUsd ?? 0));
        $monthlyBudget = max(0.0, (float)($options->warextAiMonthlyBudgetUsd ?? 0));

        $now = time();
        $dayStart = strtotime('today', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $db = \XF::db();

        if ($dailyRequests > 0)
        {
            $count = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_warext_ai_usage WHERE created_date >= ?',
                $dayStart
            );
            if ($count >= $dailyRequests)
            {
                return ['allowed' => false, 'reason' => 'daily_request_limit', 'current' => $count, 'limit' => $dailyRequests];
            }
        }

        if ($dailyBudget > 0)
        {
            $micro = (int)$db->fetchOne(
                'SELECT COALESCE(SUM(cost_microusd), 0) FROM xf_warext_ai_usage WHERE created_date >= ?',
                $dayStart
            );
            if (($micro / 1000000) >= $dailyBudget)
            {
                return ['allowed' => false, 'reason' => 'daily_budget_limit', 'current' => $micro / 1000000, 'limit' => $dailyBudget];
            }
        }

        if ($monthlyBudget > 0)
        {
            $micro = (int)$db->fetchOne(
                'SELECT COALESCE(SUM(cost_microusd), 0) FROM xf_warext_ai_usage WHERE created_date >= ?',
                $monthStart
            );
            if (($micro / 1000000) >= $monthlyBudget)
            {
                return ['allowed' => false, 'reason' => 'monthly_budget_limit', 'current' => $micro / 1000000, 'limit' => $monthlyBudget];
            }
        }

        return ['allowed' => true, 'reason' => 'ok', 'provider' => $providerId];
    }

    public function record(string $providerId, string $model, array $assessment, int $postId = 0): void
    {
        $usage = is_array($assessment['usage'] ?? null) ? $assessment['usage'] : [];
        $cost = isset($usage['cost']) && is_numeric($usage['cost']) ? max(0.0, (float)$usage['cost']) : 0.0;
        $reason = empty($assessment['available']) ? (string)($assessment['reason'] ?? 'unavailable') : '';

        \XF::db()->insert('xf_warext_ai_usage', [
            'post_id' => max(0, $postId),
            'provider_id' => substr($providerId, 0, 32),
            'model' => substr($model, 0, 191),
            'prompt_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
            'total_tokens' => max(0, (int)($usage['total_tokens'] ?? 0)),
            'cost_microusd' => (int)round($cost * 1000000),
            'success' => !empty($assessment['available']) ? 1 : 0,
            'failure_reason' => substr($reason, 0, 80),
            'created_date' => time()
        ]);
    }

    public function summary(): array
    {
        $now = time();
        $dayStart = strtotime('today', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $db = \XF::db();

        $daily = $db->fetchRow(
            'SELECT COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost FROM xf_warext_ai_usage WHERE created_date >= ?',
            $dayStart
        ) ?: [];
        $monthly = $db->fetchRow(
            'SELECT COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost FROM xf_warext_ai_usage WHERE created_date >= ?',
            $monthStart
        ) ?: [];

        return [
            'daily' => [
                'requests' => (int)($daily['requests'] ?? 0),
                'tokens' => (int)($daily['tokens'] ?? 0),
                'cost_usd' => round(((int)($daily['cost'] ?? 0)) / 1000000, 6)
            ],
            'monthly' => [
                'requests' => (int)($monthly['requests'] ?? 0),
                'tokens' => (int)($monthly['tokens'] ?? 0),
                'cost_usd' => round(((int)($monthly['cost'] ?? 0)) / 1000000, 6)
            ]
        ];
    }
}
