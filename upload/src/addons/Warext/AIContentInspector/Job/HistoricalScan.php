<?php

namespace Warext\AIContentInspector\Job;

use Warext\AIContentInspector\Service\HistoricalAnalyzer;
use XF\Job\AbstractJob;

class HistoricalScan extends AbstractJob
{
    protected $defaultData = [
        'last_post_id' => 0,
        'processed' => 0,
        'analyzed' => 0,
        'max_posts' => 1000,
        'min_date' => 0,
        'include_external' => false,
        'forum_ids' => [],
        'thread_ids' => []
    ];

    public function run($maxRunTime)
    {
        if (empty(\XF::options()->warextAiEnabled)) return $this->complete();

        $started = microtime(true);
        $batchSize = max(10, min(250, (int)(\XF::options()->warextAiHistoryBatchSize ?? 50)));
        $remaining = max(0, (int)$this->data['max_posts'] - (int)$this->data['processed']);
        if ($remaining <= 0) return $this->complete();
        $limit = min($batchSize, $remaining);

        $where = ["p.post_id > ?", "p.message_state = 'visible'"];
        $params = [(int)$this->data['last_post_id']];

        $minDate = max(0, (int)($this->data['min_date'] ?? 0));
        if ($minDate > 0)
        {
            $where[] = 'p.post_date >= ?';
            $params[] = $minDate;
        }

        $forumIds = array_values(array_unique(array_filter(array_map('intval', (array)($this->data['forum_ids'] ?? [])))));
        if ($forumIds)
        {
            $placeholders = implode(',', array_fill(0, count($forumIds), '?'));
            $where[] = "t.node_id IN ($placeholders)";
            foreach ($forumIds as $forumId) $params[] = $forumId;
        }

        $threadIds = array_values(array_unique(array_filter(array_map('intval', (array)($this->data['thread_ids'] ?? [])))));
        if ($threadIds)
        {
            $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
            $where[] = "p.thread_id IN ($placeholders)";
            foreach ($threadIds as $threadId) $params[] = $threadId;
        }

        $where[] = 'NOT EXISTS (SELECT 1 FROM xf_warext_ai_analysis a WHERE a.post_id = p.post_id)';
        $sql = 'SELECT p.post_id, p.thread_id, p.user_id, p.message, p.post_date, t.node_id AS forum_id
                FROM xf_post p
                INNER JOIN xf_thread t ON t.thread_id = p.thread_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.post_id ASC
                LIMIT ' . $limit;

        $rows = $this->app->db()->fetchAll($sql, $params);
        if (!$rows) return $this->complete();

        $analyzer = new HistoricalAnalyzer();
        foreach ($rows as $row)
        {
            $postId = (int)$row['post_id'];
            $this->data['last_post_id'] = $postId;
            $this->data['processed'] = (int)$this->data['processed'] + 1;

            try
            {
                if ($analyzer->analyzePost($row, !empty($this->data['include_external'])))
                {
                    $this->data['analyzed'] = (int)$this->data['analyzed'] + 1;
                }
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, 'Warext AI History Scan post #' . $postId . ': ');
            }

            if ((int)$this->data['processed'] >= (int)$this->data['max_posts'])
            {
                return $this->complete();
            }
            if ($maxRunTime > 0 && (microtime(true) - $started) >= max(1.0, $maxRunTime - 0.5))
            {
                return $this->resume();
            }
        }

        if (count($rows) < $limit) return $this->complete();
        return $this->resume();
    }

    public function getStatusMessage()
    {
        return sprintf(
            'Warext AI geçmiş içerik taraması: %d kontrol edildi, %d analiz edildi...',
            (int)($this->data['processed'] ?? 0),
            (int)($this->data['analyzed'] ?? 0)
        );
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
