<?php

namespace Warext\AIContentInspector\XF\Entity;

use Warext\AIContentInspector\Service\Analyzer;

class Post extends XFCP_Post
{
    protected function _postSave()
    {
        $shouldAnalyze = $this->isInsert() || $this->isChanged('message');
        parent::_postSave();

        if ($shouldAnalyze)
        {
            $this->warextRunAiAnalysis();
        }
    }

    protected function warextRunAiAnalysis(): void
    {
        try
        {
            $options = \XF::options();
            if (empty($options->warextAiEnabled)) return;

            $message = (string)$this->message;
            $minChars = max(100, (int)($options->warextAiMinChars ?? 350));
            if (mb_strlen(strip_tags($message), 'UTF-8') < $minChars) return;

            $thread = $this->Thread;
            if (!$thread) return;
            $forumId = (int)$thread->node_id;

            $configuredForums = trim((string)($options->warextAiForums ?? ''));
            if ($configuredForums !== '')
            {
                $forumIds = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $configuredForums) ?: [])));
                if ($forumIds && !in_array($forumId, $forumIds, true)) return;
            }

            [$behavior, $writing] = $this->warextReadClientContext();
            $result = (new Analyzer())->analyze($message, $behavior, $writing);
            $this->warextPersistAnalysis($forumId, $result, $message);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Content Inspector: ');
        }
    }

    protected function warextReadClientContext(): array
    {
        $behavior = [];
        $writing = [];
        try
        {
            $raw = (string)\XF::app()->request()->filter('warext_ai_behavior', 'str');
            if ($raw !== '' && strlen($raw) <= 16384)
            {
                $decoded = json_decode($raw, true);
                if (is_array($decoded))
                {
                    $behavior = is_array($decoded['behavior'] ?? null) ? $decoded['behavior'] : [];
                    $writing = is_array($decoded['writingChecker'] ?? null) ? $decoded['writingChecker'] : [];
                }
            }
        }
        catch (\Throwable $e) {}

        $behavior = [
            'observed' => !empty($behavior),
            'typedChars' => max(0, min(200000, (int)($behavior['typedChars'] ?? 0))),
            'pastedChars' => max(0, min(200000, (int)($behavior['pastedChars'] ?? 0))),
            'deletedChars' => max(0, min(200000, (int)($behavior['deletedChars'] ?? 0))),
            'pasteEvents' => max(0, min(1000, (int)($behavior['pasteEvents'] ?? 0))),
            'inputEvents' => max(0, min(200000, (int)($behavior['inputEvents'] ?? 0))),
            'durationSeconds' => max(0, min(86400, (int)($behavior['durationSeconds'] ?? 0))),
            'source' => 'client_observed'
        ];

        $writing = [
            'available' => !empty($writing['available']),
            'bridgeVersion' => substr((string)($writing['bridgeVersion'] ?? ''), 0, 24),
            'addonVersion' => substr((string)($writing['addonVersion'] ?? ''), 0, 24),
            'correctionCount' => max(0, min(10000, (int)($writing['correctionCount'] ?? 0))),
            'changedChars' => max(0, min(200000, (int)($writing['changedChars'] ?? 0))),
            'insertedChars' => max(0, min(200000, (int)($writing['insertedChars'] ?? 0))),
            'removedChars' => max(0, min(200000, (int)($writing['removedChars'] ?? 0))),
            'source' => 'client_observed'
        ];

        return [$behavior, $writing];
    }

    protected function warextPersistAnalysis(int $forumId, array $result, string $message): void
    {
        $db = \XF::db();
        $now = time();
        $postId = (int)$this->post_id;
        $existingId = (int)$db->fetchOne('SELECT analysis_id FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1', $postId);

        $data = [
            'post_id' => $postId,
            'thread_id' => (int)$this->thread_id,
            'user_id' => (int)$this->user_id,
            'forum_id' => $forumId,
            'risk_score' => (int)$result['risk_score'],
            'confidence' => (int)$result['confidence'],
            'classification' => (string)$result['classification'],
            'text_metrics' => json_encode($result['text_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'behavior_metrics' => json_encode($result['behavior_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'writing_metrics' => json_encode($result['writing_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signal_summary' => json_encode($result['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_hash' => hash('sha256', $message),
            'updated_date' => $now
        ];

        if ($existingId)
        {
            $db->update('xf_warext_ai_analysis', $data, 'analysis_id = ?', $existingId);
        }
        else
        {
            $data['analyzed_date'] = $now;
            $db->insert('xf_warext_ai_analysis', $data);
        }
    }
}
