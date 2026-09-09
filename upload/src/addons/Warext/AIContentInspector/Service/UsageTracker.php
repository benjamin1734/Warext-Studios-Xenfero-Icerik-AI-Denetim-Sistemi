<?php

namespace Warext\AIContentInspector\Service;

class UsageTracker
{
    public function canRequest(string $providerId): array
    {
        $options = \XF::options();
        $dailyRequests = max(0, (int)($options->warextAiDailyRequestLimit ?? 0));
        $monthlyRequests = max(0, (int)($options->warextAiMonthlyRequestLimit ?? 0));
        $dailyBudget = max(0.0, (float)($options->warextAiDailyBudgetUsd ?? 0));
        $monthlyBudget = max(0.0, (float)($options->warextAiMonthlyBudgetUsd ?? 0));

        if ($dailyRequests <= 0 && $monthlyRequests <= 0 && $dailyBudget <= 0 && $monthlyBudget <= 0)
        {
            return ['allowed' => true, 'reason' => 'ok', 'provider' => $providerId];
        }

        $now = time();
        $dayStart = strtotime('today', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));

        $stats = \XF::db()->fetchRow(
            'SELECT COUNT(*) AS monthly_requests,
                    COALESCE(SUM(created_date >= ?), 0) AS daily_requests,
                    COALESCE(SUM(IF(created_date >= ?, cost_microusd, 0)), 0) AS daily_cost,
                    COALESCE(SUM(cost_microusd), 0) AS monthly_cost
             FROM xf_warext_ai_usage
             WHERE created_date >= ?',
            [$dayStart, $dayStart, $monthStart]
        ) ?: [];

        $currentDailyRequests = (int)($stats['daily_requests'] ?? 0);
        $currentMonthlyRequests = (int)($stats['monthly_requests'] ?? 0);
        $currentDailyCost = ((int)($stats['daily_cost'] ?? 0)) / 1000000;
        $currentMonthlyCost = ((int)($stats['monthly_cost'] ?? 0)) / 1000000;

        if ($dailyRequests > 0 && $currentDailyRequests >= $dailyRequests)
        {
            return ['allowed' => false, 'reason' => 'daily_request_limit', 'current' => $currentDailyRequests, 'limit' => $dailyRequests, 'provider' => $providerId];
        }
        if ($monthlyRequests > 0 && $currentMonthlyRequests >= $monthlyRequests)
        {
            return ['allowed' => false, 'reason' => 'monthly_request_limit', 'current' => $currentMonthlyRequests, 'limit' => $monthlyRequests, 'provider' => $providerId];
        }
        if ($dailyBudget > 0 && $currentDailyCost >= $dailyBudget)
        {
            return ['allowed' => false, 'reason' => 'daily_budget_limit', 'current' => $currentDailyCost, 'limit' => $dailyBudget, 'provider' => $providerId];
        }
        if ($monthlyBudget > 0 && $currentMonthlyCost >= $monthlyBudget)
        {
            return ['allowed' => false, 'reason' => 'monthly_budget_limit', 'current' => $currentMonthlyCost, 'limit' => $monthlyBudget, 'provider' => $providerId];
        }

        return ['allowed' => true, 'reason' => 'ok', 'provider' => $providerId];
    }

    public function record(string $providerId, string $model, array $assessment, int $postId = 0): array
    {
        $usage = is_array($assessment['usage'] ?? null) ? $assessment['usage'] : [];
        $promptTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $totalTokens = max(0, (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens)));

        $cost = 0.0;
        $costSource = 'unknown';
        if (isset($usage['cost']) && is_numeric($usage['cost']))
        {
            $cost = max(0.0, (float)$usage['cost']);
            $costSource = 'actual';
        }
        elseif ($promptTokens > 0 || $completionTokens > 0)
        {
            $options = \XF::options();
            $inputRate = max(0.0, (float)($options->warextAiEstimatedInputUsdPerMillion ?? 0));
            $outputRate = max(0.0, (float)($options->warextAiEstimatedOutputUsdPerMillion ?? 0));
            if ($inputRate > 0 || $outputRate > 0)
            {
                $cost = (($promptTokens / 1000000) * $inputRate) + (($completionTokens / 1000000) * $outputRate);
                $costSource = 'estimated';
            }
        }

        $reason = empty($assessment['available']) ? (string)($assessment['reason'] ?? 'unavailable') : '';
        \XF::db()->insert('xf_warext_ai_usage', [
            'post_id' => max(0, $postId),
            'provider_id' => substr($providerId, 0, 32),
            'model' => substr($model, 0, 191),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_microusd' => (int)round($cost * 1000000),
            'cost_source' => $costSource,
            'success' => !empty($assessment['available']) ? 1 : 0,
            'failure_reason' => substr($reason, 0, 80),
            'created_date' => time()
        ]);

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost' => $cost,
            'cost_source' => $costSource
        ];
    }

    public function summary(): array
    {
        $now = time();
        $dayStart = strtotime('today', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $db = \XF::db();

        $period = $db->fetchRow(
            "SELECT COUNT(*) AS monthly_requests,
                    COALESCE(SUM(success),0) AS monthly_successes,
                    COALESCE(SUM(total_tokens),0) AS monthly_tokens,
                    COALESCE(SUM(cost_microusd),0) AS monthly_cost,
                    COALESCE(SUM(cost_source = 'actual'),0) AS monthly_actual_cost_records,
                    COALESCE(SUM(cost_source = 'estimated'),0) AS monthly_estimated_cost_records,
                    COALESCE(SUM(created_date >= ?),0) AS daily_requests,
                    COALESCE(SUM(IF(created_date >= ?, success, 0)),0) AS daily_successes,
                    COALESCE(SUM(IF(created_date >= ?, total_tokens, 0)),0) AS daily_tokens,
                    COALESCE(SUM(IF(created_date >= ?, cost_microusd, 0)),0) AS daily_cost,
                    COALESCE(SUM(IF(created_date >= ? AND cost_source = 'actual', 1, 0)),0) AS daily_actual_cost_records,
                    COALESCE(SUM(IF(created_date >= ? AND cost_source = 'estimated', 1, 0)),0) AS daily_estimated_cost_records
             FROM xf_warext_ai_usage
             WHERE created_date >= ?",
            [$dayStart, $dayStart, $dayStart, $dayStart, $dayStart, $dayStart, $monthStart]
        ) ?: [];

        $daily = [
            'requests' => (int)($period['daily_requests'] ?? 0),
            'successes' => (int)($period['daily_successes'] ?? 0),
            'tokens' => (int)($period['daily_tokens'] ?? 0),
            'cost' => (int)($period['daily_cost'] ?? 0),
            'actual_cost_records' => (int)($period['daily_actual_cost_records'] ?? 0),
            'estimated_cost_records' => (int)($period['daily_estimated_cost_records'] ?? 0)
        ];
        $monthly = [
            'requests' => (int)($period['monthly_requests'] ?? 0),
            'successes' => (int)($period['monthly_successes'] ?? 0),
            'tokens' => (int)($period['monthly_tokens'] ?? 0),
            'cost' => (int)($period['monthly_cost'] ?? 0),
            'actual_cost_records' => (int)($period['monthly_actual_cost_records'] ?? 0),
            'estimated_cost_records' => (int)($period['monthly_estimated_cost_records'] ?? 0)
        ];

        $providerRows = $db->fetchAll(
            "SELECT provider_id, COUNT(*) AS requests, COALESCE(SUM(success),0) AS successes,
                    COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost,
                    COALESCE(SUM(cost_source = 'actual'),0) AS actual_cost_records,
                    COALESCE(SUM(cost_source = 'estimated'),0) AS estimated_cost_records,
                    MAX(created_date) AS last_activity
             FROM xf_warext_ai_usage
             WHERE created_date >= ?
             GROUP BY provider_id
             ORDER BY requests DESC",
            $dayStart
        );

        $latestRows = $db->fetchAll(
            'SELECT provider_id, model, success, failure_reason, cost_source, created_date
             FROM xf_warext_ai_usage
             ORDER BY usage_id DESC
             LIMIT 100'
        );
        $latest = [];
        $latestFailure = [];
        foreach ($latestRows as $row)
        {
            $providerId = (string)$row['provider_id'];
            if (!isset($latest[$providerId])) $latest[$providerId] = $row;
            if (empty($row['success']) && !isset($latestFailure[$providerId])) $latestFailure[$providerId] = $row;
        }

        $providers = [];
        foreach ($providerRows as $row)
        {
            $providerId = (string)$row['provider_id'];
            $requests = (int)$row['requests'];
            $successes = (int)$row['successes'];
            $rate = $requests > 0 ? (int)round(($successes / $requests) * 100) : 0;
            $last = $latest[$providerId] ?? [];
            $failure = $latestFailure[$providerId] ?? [];

            $status = 'healthy';
            if ($requests >= 3 && $rate < 50) $status = 'error';
            elseif ($requests >= 3 && $rate < 85) $status = 'degraded';
            elseif (!empty($last) && empty($last['success'])) $status = 'degraded';

            $providers[] = [
                'provider' => $providerId,
                'model' => (string)($last['model'] ?? ''),
                'status' => $status,
                'requests' => $requests,
                'successes' => $successes,
                'success_rate' => $rate,
                'tokens' => (int)$row['tokens'],
                'cost_usd' => round(((int)$row['cost']) / 1000000, 6),
                'actual_cost_records' => (int)$row['actual_cost_records'],
                'estimated_cost_records' => (int)$row['estimated_cost_records'],
                'last_cost_source' => (string)($last['cost_source'] ?? 'unknown'),
                'last_activity' => (int)($row['last_activity'] ?? 0),
                'last_failure_reason' => (string)($failure['failure_reason'] ?? ''),
                'last_failure_date' => (int)($failure['created_date'] ?? 0)
            ];
        }

        $options = \XF::options();
        return [
            'daily' => $this->periodSummary($daily),
            'monthly' => $this->periodSummary($monthly),
            'limits' => [
                'daily_requests' => max(0, (int)($options->warextAiDailyRequestLimit ?? 0)),
                'monthly_requests' => max(0, (int)($options->warextAiMonthlyRequestLimit ?? 0)),
                'daily_budget_usd' => max(0.0, (float)($options->warextAiDailyBudgetUsd ?? 0)),
                'monthly_budget_usd' => max(0.0, (float)($options->warextAiMonthlyBudgetUsd ?? 0)),
                'estimated_input_usd_per_million' => max(0.0, (float)($options->warextAiEstimatedInputUsdPerMillion ?? 0)),
                'estimated_output_usd_per_million' => max(0.0, (float)($options->warextAiEstimatedOutputUsdPerMillion ?? 0))
            ],
            'providers' => $providers
        ];
    }

    public function prune(): int
    {
        $days = max(7, min(3650, (int)(\XF::options()->warextAiUsageRetentionDays ?? 90)));
        $cutoff = time() - ($days * 86400);
        return \XF::db()->delete('xf_warext_ai_usage', 'created_date < ?', $cutoff);
    }

    protected function periodSummary(array $row): array
    {
        $requests = (int)($row['requests'] ?? 0);
        $successes = (int)($row['successes'] ?? 0);
        return [
            'requests' => $requests,
            'successes' => $successes,
            'failed' => max(0, $requests - $successes),
            'success_rate' => $requests > 0 ? (int)round(($successes / $requests) * 100) : 0,
            'tokens' => (int)($row['tokens'] ?? 0),
            'cost_usd' => round(((int)($row['cost'] ?? 0)) / 1000000, 6),
            'actual_cost_records' => (int)($row['actual_cost_records'] ?? 0),
            'estimated_cost_records' => (int)($row['estimated_cost_records'] ?? 0)
        ];
    }
}
