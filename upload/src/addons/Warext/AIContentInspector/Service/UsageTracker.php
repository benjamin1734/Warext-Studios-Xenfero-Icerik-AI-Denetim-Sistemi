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
                return ['allowed' => false, 'reason' => 'daily_request_limit', 'current' => $count, 'limit' => $dailyRequests, 'provider' => $providerId];
            }
        }

        if ($monthlyRequests > 0)
        {
            $count = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_warext_ai_usage WHERE created_date >= ?',
                $monthStart
            );
            if ($count >= $monthlyRequests)
            {
                return ['allowed' => false, 'reason' => 'monthly_request_limit', 'current' => $count, 'limit' => $monthlyRequests, 'provider' => $providerId];
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
                return ['allowed' => false, 'reason' => 'daily_budget_limit', 'current' => $micro / 1000000, 'limit' => $dailyBudget, 'provider' => $providerId];
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
                return ['allowed' => false, 'reason' => 'monthly_budget_limit', 'current' => $micro / 1000000, 'limit' => $monthlyBudget, 'provider' => $providerId];
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
            'SELECT COUNT(*) AS requests, COALESCE(SUM(success),0) AS successes,
                    COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost
             FROM xf_warext_ai_usage WHERE created_date >= ?',
            $dayStart
        ) ?: [];
        $monthly = $db->fetchRow(
            'SELECT COUNT(*) AS requests, COALESCE(SUM(success),0) AS successes,
                    COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost
             FROM xf_warext_ai_usage WHERE created_date >= ?',
            $monthStart
        ) ?: [];

        $providerRows = $db->fetchAll(
            'SELECT provider_id, COUNT(*) AS requests, COALESCE(SUM(success),0) AS successes,
                    COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_microusd),0) AS cost,
                    MAX(created_date) AS last_activity
             FROM xf_warext_ai_usage
             WHERE created_date >= ?
             GROUP BY provider_id
             ORDER BY requests DESC',
            $dayStart
        );

        $latestRows = $db->fetchAll(
            'SELECT provider_id, model, success, failure_reason, created_date
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
                'monthly_budget_usd' => max(0.0, (float)($options->warextAiMonthlyBudgetUsd ?? 0))
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
            'cost_usd' => round(((int)($row['cost'] ?? 0)) / 1000000, 6)
        ];
    }
}
