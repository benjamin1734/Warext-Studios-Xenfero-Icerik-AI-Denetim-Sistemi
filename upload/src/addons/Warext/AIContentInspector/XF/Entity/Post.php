<?php

namespace Warext\AIContentInspector\XF\Entity;

use Warext\AIContentInspector\Service\Analyzer;
use Warext\AIContentInspector\Service\UserProfile;
use Warext\AIContentInspector\Service\Similarity;

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
            $analyzer = new Analyzer();
            if ($analyzer->authoredTextLength($message) < $minChars) return;

            $thread = $this->Thread;
            if (!$thread) return;
            $forumId = (int)$thread->node_id;

            $configured = $options->warextAiForums ?? [];
            if (is_array($configured))
            {
                $forumIds = array_values(array_filter(array_map('intval', $configured)));
            }
            else
            {
                $legacy = trim((string)$configured);
                $forumIds = $legacy === '' ? [] : array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $legacy) ?: [])));
            }
            if ($forumIds && !in_array($forumId, $forumIds, true)) return;

            [$behavior, $writing] = $this->warextReadClientContext();
            $result = $analyzer->analyze($message, $behavior, $writing);
            $result = (new UserProfile())->enrich((int)$this->user_id, (int)$this->post_id, $result);
            $result = $this->warextEnrichSimilarity($forumId, $result, $message);
            $this->warextPersistAnalysis($forumId, $result, $message);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Content Inspector: ');
        }
    }

    protected function warextEnrichSimilarity(int $forumId, array $result, string $message): array
    {
        $similarity = new Similarity();
        $fingerprint = $similarity->fingerprint($message);
        if ($fingerprint === '')
        {
            $result['similarity_metrics'] = ['available' => false, 'fingerprint' => '', 'similarity' => 0, 'matches' => []];
            return $result;
        }

        $rows = \XF::db()->fetchAll(
            'SELECT post_id, content_fingerprint FROM xf_warext_ai_analysis WHERE forum_id = ? AND content_fingerprint <> ? ORDER BY analyzed_date DESC LIMIT 250',
            [$forumId, '']
        );
        $comparison = $similarity->compare($fingerprint, $rows, (int)$this->post_id);
        $result['similarity_metrics'] = ['available' => true, 'fingerprint' => $fingerprint] + $comparison;

        if (($comparison['similarity'] ?? 0) >= 88)
        {
            $result['signals'][] = ['key' => 'high_content_similarity', 'level' => 'context', 'value' => (int)$comparison['similarity']];
        }
        elseif (($comparison['similarity'] ?? 0) >= 78)
        {
            $result['signals'][] = ['key' => 'content_similarity', 'level' => 'context', 'value' => (int)$comparison['similarity']];
        }

        return $result;
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

        $rawFields = is_array($writing['fields'] ?? null) ? $writing['fields'] : [];
        $writing = [
            'available' => !empty($writing['available']),
            'bridgeVersion' => substr((string)($writing['bridgeVersion'] ?? ''), 0, 24),
            'addonVersion' => substr((string)($writing['addonVersion'] ?? ''), 0, 24),
            'correctionCount' => max(0, min(10000, (int)($writing['correctionCount'] ?? 0))),
            'changedChars' => max(0, min(200000, (int)($writing['changedChars'] ?? 0))),
            'insertedChars' => max(0, min(200000, (int)($writing['insertedChars'] ?? 0))),
            'removedChars' => max(0, min(200000, (int)($writing['removedChars'] ?? 0))),
            'fields' => [
                'title' => $this->warextSanitizeWritingField($rawFields['title'] ?? []),
                'message' => $this->warextSanitizeWritingField($rawFields['message'] ?? [])
            ],
            'source' => 'client_observed'
        ];

        return [$behavior, $writing];
    }

    protected function warextSanitizeWritingField($field): array
    {
        $field = is_array($field) ? $field : [];
        return [
            'correctionCount' => max(0, min(10000, (int)($field['correctionCount'] ?? 0))),
            'changedChars' => max(0, min(200000, (int)($field['changedChars'] ?? 0))),
            'insertedChars' => max(0, min(200000, (int)($field['insertedChars'] ?? 0))),
            'removedChars' => max(0, min(200000, (int)($field['removedChars'] ?? 0)))
        ];
    }

    protected function warextPersistAnalysis(int $forumId, array $result, string $message): void
    {
        $db = \XF::db();
        $now = time();
        $postId = (int)$this->post_id;
        $contentHash = hash('sha256', $message);
        $existing = $db->fetchRow(
            'SELECT analysis_id, content_hash, review_state FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1',
            $postId
        );

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
            'profile_metrics' => json_encode($result['profile_metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signal_summary' => json_encode(['signals' => $result['signals'], 'similarity' => $result['similarity_metrics'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_hash' => $contentHash,
            'content_fingerprint' => (string)($result['similarity_metrics']['fingerprint'] ?? ''),
            'updated_date' => $now
        ];

        if ($existing)
        {
            if (!hash_equals((string)($existing['content_hash'] ?? ''), $contentHash))
            {
                $data['review_state'] = 'pending';
                $data['reviewer_user_id'] = 0;
                $data['reviewed_date'] = 0;
            }
            $db->update('xf_warext_ai_analysis', $data, 'analysis_id = ?', (int)$existing['analysis_id']);
        }
        else
        {
            $data['analyzed_date'] = $now;
            $db->insert('xf_warext_ai_analysis', $data);
        }
    }
}
