<?php

namespace Warext\AIContentInspector\Service;

use Warext\AIContentInspector\Provider\Registry;

class HistoricalAnalyzer
{
    public function analyzePost(array $post, bool $includeExternal = false, bool $manualScan = false): bool
    {
        $postId = max(0, (int)($post['post_id'] ?? 0));
        $threadId = max(0, (int)($post['thread_id'] ?? 0));
        $userId = max(0, (int)($post['user_id'] ?? 0));
        $forumId = max(0, (int)($post['forum_id'] ?? 0));
        $message = (string)($post['message'] ?? '');
        if ($postId <= 0 || $threadId <= 0 || $forumId <= 0 || $message === '') return false;

        $analyzer = new Analyzer();
        $configuredMinChars = max(0, min(50000, (int)(\XF::options()->warextAiMinChars ?? 350)));
        $minChars = $manualScan
            ? max(1, min(80, $configuredMinChars > 0 ? $configuredMinChars : 1))
            : $configuredMinChars;
        if ($analyzer->authoredTextLength($message) < $minChars) return false;

        $behavior = [
            'observed' => false,
            'typedChars' => 0,
            'pastedChars' => 0,
            'deletedChars' => 0,
            'pasteEvents' => 0,
            'inputEvents' => 0,
            'durationSeconds' => 0,
            'source' => $manualScan ? 'manual_thread_scan_unobserved' : 'historical_unobserved'
        ];
        $emptyField = ['correctionCount' => 0, 'changedChars' => 0, 'insertedChars' => 0, 'removedChars' => 0];
        $writing = [
            'available' => false,
            'bridgeVersion' => '',
            'addonVersion' => '',
            'correctionCount' => 0,
            'changedChars' => 0,
            'insertedChars' => 0,
            'removedChars' => 0,
            'fields' => ['title' => $emptyField, 'message' => $emptyField],
            'source' => $manualScan ? 'manual_thread_scan_unobserved' : 'historical_unobserved'
        ];

        $registry = new Registry();
        $result = $registry->local()->analyze($message, ['behavior' => $behavior, 'writing' => $writing]);
        $result['signals'][] = [
            'key' => $manualScan ? 'manual_thread_behavior_unavailable' : 'historical_behavior_unavailable',
            'level' => 'context',
            'value' => 1
        ];
        $result = (new UserProfile())->enrich($userId, $postId, $result);
        $result = $this->enrichSimilarity($forumId, $postId, $message, $result);

        $external = [
            'enabled' => false,
            'available' => false,
            'pending' => false,
            'skipped' => true,
            'provider' => $registry->selectedExternalId(),
            'model' => '',
            'weight' => 0,
            'result' => ['reason' => $manualScan ? 'manual_thread_local_only' : 'historical_local_only']
        ];

        if ($includeExternal && $registry->isExternalEnabled())
        {
            $config = $registry->externalConfig();
            $threshold = max(0, min(100, (int)($config['minimum_local_risk'] ?? 100)));
            if ((int)$result['risk_score'] >= $threshold)
            {
                $external = [
                    'enabled' => true,
                    'available' => false,
                    'pending' => true,
                    'skipped' => false,
                    'provider' => (string)($config['provider'] ?? $registry->selectedExternalId()),
                    'model' => (string)($config['model'] ?? ''),
                    'weight' => 0,
                    'minimum_local_risk' => $threshold,
                    'result' => ['reason' => 'queued_from_history_scan']
                ];
            }
            else
            {
                $external['enabled'] = true;
                $external['provider'] = (string)($config['provider'] ?? $registry->selectedExternalId());
                $external['model'] = (string)($config['model'] ?? '');
                $external['result'] = ['reason' => 'below_local_risk_threshold'];
            }
        }
        $result['external_verification'] = $external;

        $contentHash = hash('sha256', $message);
        $now = time();
        $db = \XF::db();
        $existing = $db->fetchRow(
            'SELECT analysis_id, content_hash, review_state FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1',
            $postId
        );

        $data = [
            'post_id' => $postId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'forum_id' => $forumId,
            'risk_score' => (int)$result['risk_score'],
            'confidence' => (int)$result['confidence'],
            'classification' => (string)$result['classification'],
            'text_metrics' => json_encode($result['text_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'behavior_metrics' => json_encode($result['behavior_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'writing_metrics' => json_encode($result['writing_metrics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_metrics' => json_encode($result['profile_metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'similarity_metrics' => json_encode($result['similarity_metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'external_metrics' => json_encode($result['external_verification'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signal_summary' => json_encode($result['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            $data['analyzed_date'] = max(0, (int)($post['post_date'] ?? $now)) ?: $now;
            $db->insert('xf_warext_ai_analysis', $data);
        }

        if (!empty($external['pending']))
        {
            $providerId = preg_replace('/[^a-z0-9_\-]/i', '', (string)($external['provider'] ?? 'external')) ?: 'external';
            $uniqueId = 'warextAiExternal_' . $providerId . '_' . $postId . '_' . substr($contentHash, 0, 16);
            \XF::app()->jobManager()->enqueueUnique(
                $uniqueId,
                'Warext\\AIContentInspector:ExternalVerify',
                ['post_id' => $postId, 'content_hash' => $contentHash],
                false
            );
        }

        return true;
    }

    protected function enrichSimilarity(int $forumId, int $postId, string $message, array $result): array
    {
        $similarity = new Similarity();
        $fingerprint = $similarity->fingerprint($message);
        if ($fingerprint === '')
        {
            $result['similarity_metrics'] = ['available' => false, 'fingerprint' => '', 'similarity' => 0, 'matches' => []];
            return $result;
        }

        $rows = \XF::db()->fetchAll(
            'SELECT post_id, content_fingerprint FROM xf_warext_ai_analysis
             WHERE forum_id = ? AND post_id <> ? AND content_fingerprint <> ?
             ORDER BY analyzed_date DESC LIMIT 250',
            [$forumId, $postId, '']
        );
        $comparison = $similarity->compare($fingerprint, $rows, $postId);
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
}
