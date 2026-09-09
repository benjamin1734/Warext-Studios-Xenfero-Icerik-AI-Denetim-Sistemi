<?php

namespace Warext\AIContentInspector\XF\Entity;

use Warext\AIContentInspector\Provider\Registry;
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

    public function warextRunAiAnalysis(bool $force = false, bool $manual = false): array
    {
        try
        {
            $options = \XF::options();
            if (empty($options->warextAiEnabled))
            {
                return ['success' => false, 'reason' => 'disabled'];
            }

            $message = (string)$this->message;
            $analyzer = new Analyzer();
            $authoredChars = $analyzer->authoredTextLength($message);
            $minChars = max(0, min(50000, (int)($options->warextAiMinChars ?? 350)));

            if ($authoredChars <= 0)
            {
                return ['success' => false, 'reason' => 'empty_text', 'chars' => 0];
            }
            if ($force)
            {
                // Manuel moderasyon otomatik eşiği aşabilir; yönetici otomatik eşiği
                // 80'in altına indirdiyse aynı düşük sınır manuel analizde de geçerlidir.
                // 0 ayarında yalnızca gerçekten boş içerik reddedilir.
                $manualMinChars = max(1, min(80, $minChars > 0 ? $minChars : 1));
                if ($authoredChars < $manualMinChars)
                {
                    return [
                        'success' => false,
                        'reason' => 'insufficient_text',
                        'chars' => $authoredChars,
                        'minimum_chars' => $manualMinChars
                    ];
                }
            }
            elseif ($authoredChars < $minChars)
            {
                return [
                    'success' => false,
                    'reason' => 'below_automatic_threshold',
                    'chars' => $authoredChars,
                    'minimum_chars' => $minChars
                ];
            }

            $thread = $this->Thread;
            if (!$thread)
            {
                return ['success' => false, 'reason' => 'thread_unavailable'];
            }
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
            // Forum selection governs automatic scanning. An authorised moderator may
            // explicitly inspect a post outside that list with a forced manual run.
            if (!$force && $forumIds && !in_array($forumId, $forumIds, true))
            {
                return ['success' => false, 'reason' => 'forum_not_selected'];
            }

            $contentHash = hash('sha256', $message);
            $existingHash = \XF::db()->fetchOne(
                'SELECT content_hash FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1',
                (int)$this->post_id
            );
            if (!$force && is_string($existingHash) && strlen($existingHash) === 64 && hash_equals($existingHash, $contentHash))
            {
                return ['success' => false, 'reason' => 'unchanged'];
            }

            [$behavior, $writing] = $manual
                ? [$this->warextEmptyObservedBehavior(), $this->warextEmptyWritingContext()]
                : $this->warextReadClientContext();

            $provider = (new Registry())->local();
            $result = $provider->analyze($message, ['behavior' => $behavior, 'writing' => $writing]);
            $result = (new UserProfile())->enrich((int)$this->user_id, (int)$this->post_id, $result);
            $result = $this->warextEnrichSimilarity($forumId, $result, $message);

            if ($manual)
            {
                $result['signals'] = is_array($result['signals'] ?? null) ? $result['signals'] : [];
                $result['signals'][] = [
                    'key' => 'manual_analysis',
                    'level' => 'context',
                    'value' => (int)\XF::visitor()->user_id
                ];
            }

            $result = $this->warextPrepareExternalState($result);

            $this->warextPersistAnalysis($forumId, $result, $contentHash);
            $this->warextQueueExternalVerification($result, $contentHash);

            $external = is_array($result['external_verification'] ?? null) ? $result['external_verification'] : [];
            return [
                'success' => true,
                'reason' => $manual ? 'manual_complete' : 'automatic_complete',
                'post_id' => (int)$this->post_id,
                'thread_id' => (int)$this->thread_id,
                'forum_id' => $forumId,
                'risk' => (int)($result['risk_score'] ?? 0),
                'confidence' => (int)($result['confidence'] ?? 0),
                'classification' => (string)($result['classification'] ?? 'unknown'),
                'external_pending' => !empty($external['pending'])
            ];
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext AI Content Inspector: ');
            return ['success' => false, 'reason' => 'analysis_error'];
        }
    }

    protected function warextPrepareExternalState(array $result): array
    {
        $registry = new Registry();
        $config = $registry->externalConfig();
        $providerId = (string)($config['provider'] ?? $registry->selectedExternalId());
        $minimumRisk = max(0, min(100, (int)($config['minimum_local_risk'] ?? 100)));
        $localRisk = max(0, min(100, (int)($result['risk_score'] ?? 0)));
        $enabled = $registry->isExternalEnabled();

        $external = [
            'enabled' => $enabled,
            'available' => false,
            'pending' => false,
            'skipped' => false,
            'provider' => $providerId,
            'model' => (string)($config['model'] ?? ''),
            'minimum_local_risk' => $minimumRisk,
            'weight' => 0,
            'result' => []
        ];

        if (!$enabled)
        {
            $result['external_verification'] = $external;
            return $result;
        }

        if ($localRisk < $minimumRisk)
        {
            $external['skipped'] = true;
            $external['result'] = ['reason' => 'below_local_risk_threshold'];
            $result['external_verification'] = $external;
            return $result;
        }

        $provider = $registry->external();
        if (!$provider)
        {
            $external['result'] = ['reason' => 'provider_not_implemented'];
            $result['external_verification'] = $external;
            return $result;
        }
        if (!$provider->isConfigured())
        {
            $external['result'] = ['reason' => 'not_configured'];
            $result['external_verification'] = $external;
            return $result;
        }

        $external['pending'] = true;
        $external['result'] = ['reason' => 'queued'];
        $result['external_verification'] = $external;
        return $result;
    }

    protected function warextQueueExternalVerification(array $result, string $contentHash): void
    {
        $external = is_array($result['external_verification'] ?? null) ? $result['external_verification'] : [];
        if (empty($external['pending'])) return;

        $postId = (int)$this->post_id;
        if ($postId <= 0 || $contentHash === '') return;

        $providerId = preg_replace('/[^a-z0-9_\-]/i', '', (string)($external['provider'] ?? 'external')) ?: 'external';
        $uniqueId = 'warextAiExternal_' . $providerId . '_' . $postId . '_' . substr($contentHash, 0, 16);
        \XF::app()->jobManager()->enqueueUnique(
            $uniqueId,
            'Warext\AIContentInspector:ExternalVerify',
            ['post_id' => $postId, 'content_hash' => $contentHash],
            false
        );
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
            'SELECT post_id, content_fingerprint
             FROM xf_warext_ai_analysis
             WHERE forum_id = ? AND post_id <> ? AND content_fingerprint <> ?
             ORDER BY analyzed_date DESC
             LIMIT 250',
            [$forumId, (int)$this->post_id, '']
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

    protected function warextEmptyObservedBehavior(): array
    {
        return [
            'observed' => false,
            'typedChars' => 0,
            'pastedChars' => 0,
            'deletedChars' => 0,
            'pasteEvents' => 0,
            'inputEvents' => 0,
            'durationSeconds' => 0,
            'source' => 'manual_reanalysis_unobserved'
        ];
    }

    protected function warextEmptyWritingContext(): array
    {
        return [
            'available' => false,
            'bridgeVersion' => '',
            'addonVersion' => '',
            'correctionCount' => 0,
            'changedChars' => 0,
            'insertedChars' => 0,
            'removedChars' => 0,
            'fields' => [
                'title' => $this->warextSanitizeWritingField([]),
                'message' => $this->warextSanitizeWritingField([])
            ],
            'source' => 'manual_reanalysis_unobserved'
        ];
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

    protected function warextPersistAnalysis(int $forumId, array $result, string $contentHash): void
    {
        $db = \XF::db();
        $now = time();
        $postId = (int)$this->post_id;
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
            'similarity_metrics' => json_encode($result['similarity_metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'external_metrics' => json_encode($result['external_verification'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            $data['analyzed_date'] = $now;
            $db->insert('xf_warext_ai_analysis', $data);
        }
    }
}
