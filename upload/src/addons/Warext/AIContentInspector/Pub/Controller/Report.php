<?php

namespace Warext\AIContentInspector\Pub\Controller;

use Warext\AIContentInspector\Service\UsageTracker;
use XF\Pub\Controller\AbstractController;

class Report extends AbstractController
{
    protected function canViewSimple(): bool
    {
        return \XF::visitor()->hasPermission('general', 'warextAiViewSimple');
    }

    protected function canViewDetailed(): bool
    {
        return \XF::visitor()->hasPermission('general', 'warextAiViewDetailed');
    }

    protected function canReview(): bool
    {
        return \XF::visitor()->hasPermission('general', 'warextAiReview');
    }

    protected function canManage(): bool
    {
        return \XF::visitor()->hasPermission('general', 'warextAiManage');
    }

    public function actionIndex()
    {
        if (!$this->canViewSimple()) return $this->noPermission();

        $page = $this->filterPage();
        $perPage = 30;
        $filters = [
            'state' => (string)$this->filter('state', 'str'),
            'risk_min' => min(100, max(0, (int)$this->filter('risk_min', 'uint'))),
            'forum_id' => (int)$this->filter('forum_id', 'uint'),
            'user_id' => (int)$this->filter('user_id', 'uint')
        ];
        if (!in_array($filters['state'], ['', 'pending', 'cleared', 'suspicious', 'confirmed'], true)) $filters['state'] = '';

        $where = [];
        $params = [];
        if ($filters['state'] !== '') { $where[] = 'a.review_state = ?'; $params[] = $filters['state']; }
        if ($filters['risk_min'] > 0) { $where[] = 'a.risk_score >= ?'; $params[] = $filters['risk_min']; }
        if ($filters['forum_id'] > 0) { $where[] = 'a.forum_id = ?'; $params[] = $filters['forum_id']; }
        if ($filters['user_id'] > 0) { $where[] = 'a.user_id = ?'; $params[] = $filters['user_id']; }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $db = \XF::db();
        $total = (int)$db->fetchOne('SELECT COUNT(*) FROM xf_warext_ai_analysis a' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $db->fetchAll(
            'SELECT a.analysis_id, a.post_id, a.thread_id, a.user_id, a.forum_id,
                    a.risk_score, a.confidence, a.classification, a.review_state,
                    a.profile_metrics, a.analyzed_date, a.updated_date,
                    u.username, t.title AS thread_title
             FROM xf_warext_ai_analysis a
             LEFT JOIN xf_user u ON u.user_id = a.user_id
             LEFT JOIN xf_thread t ON t.thread_id = a.thread_id' . $whereSql .
            ' ORDER BY CASE a.review_state WHEN \'pending\' THEN 0 WHEN \'suspicious\' THEN 1 WHEN \'confirmed\' THEN 2 ELSE 3 END,
                       a.risk_score DESC, a.updated_date DESC
              LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        foreach ($rows as &$row)
        {
            $profile = $this->decode($row['profile_metrics'] ?? null);
            $row['profile_deviation'] = (int)($profile['deviation_score'] ?? 0);
            $row['profile_samples'] = (int)($profile['sample_count'] ?? 0);
        }
        unset($row);

        $counts = [
            'pending' => (int)$db->fetchOne("SELECT COUNT(*) FROM xf_warext_ai_analysis WHERE review_state = 'pending'"),
            'suspicious' => (int)$db->fetchOne("SELECT COUNT(*) FROM xf_warext_ai_analysis WHERE review_state = 'suspicious'"),
            'confirmed' => (int)$db->fetchOne("SELECT COUNT(*) FROM xf_warext_ai_analysis WHERE review_state = 'confirmed'"),
            'cleared' => (int)$db->fetchOne("SELECT COUNT(*) FROM xf_warext_ai_analysis WHERE review_state = 'cleared'")
        ];

        $canManage = $this->canManage();
        $usage = $canManage ? (new UsageTracker())->summary() : [];
        $linkParams = array_filter($filters, fn($value) => $value !== '' && $value !== 0);
        return $this->view('Warext\AIContentInspector:Report\Index', 'warext_ai_center', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'filters' => $filters, 'counts' => $counts, 'linkParams' => $linkParams,
            'canDetailed' => $this->canViewDetailed(), 'canReview' => $this->canReview(),
            'canManage' => $canManage, 'usage' => $usage
        ]);
    }

    public function actionHistoryScan()
    {
        if (!$this->canManage()) return $this->noPermission();
        $this->assertPostOnly();

        $maxPosts = max(10, min(50000, (int)$this->filter('max_posts', 'uint')));
        $days = min(3650, max(0, (int)$this->filter('days', 'uint')));
        $includeExternal = (bool)$this->filter('include_external', 'bool');
        $minDate = $days > 0 ? time() - ($days * 86400) : 0;

        $configured = \XF::options()->warextAiForums ?? [];
        if (is_array($configured))
        {
            $forumIds = array_values(array_unique(array_filter(array_map('intval', $configured))));
        }
        else
        {
            $legacy = trim((string)$configured);
            $forumIds = $legacy === '' ? [] : array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $legacy) ?: []))));
        }

        \XF::app()->jobManager()->enqueueUnique(
            'warextAiHistoryScan',
            'Warext\\AIContentInspector:HistoricalScan',
            [
                'last_post_id' => 0,
                'processed' => 0,
                'analyzed' => 0,
                'max_posts' => $maxPosts,
                'min_date' => $minDate,
                'include_external' => $includeExternal,
                'forum_ids' => $forumIds
            ],
            false
        );

        return $this->redirect(
            $this->buildLink('warext-ai'),
            'Geçmiş içerik taraması arka plan kuyruğuna eklendi.'
        );
    }

    public function actionBatch()
    {
        if (!$this->canViewSimple()) return $this->noPermission();

        $raw = (string)$this->filter('post_ids', 'str');
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []))));
        $ids = array_slice($ids, 0, 100);
        if (!$ids) return $this->asJson(['reports' => []]);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = \XF::db()->fetchAll("SELECT post_id, thread_id, risk_score, confidence, classification, review_state, updated_date FROM xf_warext_ai_analysis WHERE post_id IN ($placeholders)", $ids);
        $reports = [];
        foreach ($rows as $row)
        {
            $reports[] = [
                'postId' => (int)$row['post_id'],
                'threadId' => (int)$row['thread_id'],
                'risk' => (int)$row['risk_score'],
                'confidence' => (int)$row['confidence'],
                'classification' => (string)$row['classification'],
                'reviewState' => (string)$row['review_state'],
                'updatedDate' => (int)$row['updated_date']
            ];
        }

        return $this->asJson(['reports' => $reports, 'canDetailed' => $this->canViewDetailed()]);
    }

    public function actionThread()
    {
        if (!$this->canViewSimple()) return $this->noPermission();

        $threadId = (int)$this->filter('thread_id', 'uint');
        if ($threadId <= 0) return $this->notFound();

        $db = \XF::db();
        $summary = $db->fetchRow(
            'SELECT COUNT(*) AS analyzed_count,
                    ROUND(AVG(risk_score), 0) AS average_risk,
                    MAX(risk_score) AS max_risk,
                    SUM(risk_score >= 70) AS high_risk_count,
                    SUM(review_state = \'pending\') AS pending_count,
                    SUM(review_state = \'suspicious\') AS suspicious_count,
                    SUM(review_state = \'confirmed\') AS confirmed_count,
                    SUM(review_state = \'cleared\') AS cleared_count
             FROM xf_warext_ai_analysis
             WHERE thread_id = ?',
            $threadId
        );
        if (!$summary || (int)$summary['analyzed_count'] === 0) return $this->asJson(['threadId' => $threadId, 'available' => false]);

        $top = $db->fetchAll(
            'SELECT post_id, user_id, risk_score, confidence, classification, review_state
             FROM xf_warext_ai_analysis
             WHERE thread_id = ?
             ORDER BY risk_score DESC, confidence DESC
             LIMIT 5',
            $threadId
        );

        foreach ($top as &$row)
        {
            $row['post_id'] = (int)$row['post_id'];
            $row['user_id'] = (int)$row['user_id'];
            $row['risk_score'] = (int)$row['risk_score'];
            $row['confidence'] = (int)$row['confidence'];
        }
        unset($row);

        return $this->asJson([
            'threadId' => $threadId,
            'available' => true,
            'analyzedCount' => (int)$summary['analyzed_count'],
            'averageRisk' => (int)$summary['average_risk'],
            'maxRisk' => (int)$summary['max_risk'],
            'highRiskCount' => (int)$summary['high_risk_count'],
            'states' => [
                'pending' => (int)$summary['pending_count'],
                'suspicious' => (int)$summary['suspicious_count'],
                'confirmed' => (int)$summary['confirmed_count'],
                'cleared' => (int)$summary['cleared_count']
            ],
            'top' => $this->canViewDetailed() ? $top : [],
            'canDetailed' => $this->canViewDetailed()
        ]);
    }

    public function actionDetail()
    {
        if (!$this->canViewDetailed()) return $this->noPermission();

        $postId = (int)$this->filter('post_id', 'uint');
        $row = \XF::db()->fetchRow('SELECT * FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1', $postId);
        if (!$row) return $this->notFound();

        $history = \XF::db()->fetchAll(
            'SELECT l.review_id, l.reviewer_user_id, l.from_state, l.to_state, l.note, l.created_date, u.username
             FROM xf_warext_ai_review_log l
             LEFT JOIN xf_user u ON u.user_id = l.reviewer_user_id
             WHERE l.post_id = ? ORDER BY l.review_id DESC LIMIT 20',
            $postId
        );
        foreach ($history as &$item)
        {
            $item['review_id'] = (int)$item['review_id'];
            $item['reviewer_user_id'] = (int)$item['reviewer_user_id'];
            $item['created_date'] = (int)$item['created_date'];
        }
        unset($item);

        return $this->asJson([
            'postId' => (int)$row['post_id'], 'threadId' => (int)$row['thread_id'],
            'userId' => (int)$row['user_id'], 'forumId' => (int)$row['forum_id'],
            'risk' => (int)$row['risk_score'], 'confidence' => (int)$row['confidence'],
            'classification' => (string)$row['classification'], 'reviewState' => (string)$row['review_state'],
            'textMetrics' => $this->decode($row['text_metrics']), 'behaviorMetrics' => $this->decode($row['behavior_metrics']),
            'writingMetrics' => $this->decode($row['writing_metrics']), 'profileMetrics' => $this->decode($row['profile_metrics'] ?? null),
            'externalMetrics' => $this->decode($row['external_metrics'] ?? null),
            'signals' => $this->decode($row['signal_summary']), 'reviewHistory' => $history,
            'analyzedDate' => (int)$row['analyzed_date'], 'updatedDate' => (int)$row['updated_date'],
            'canReview' => $this->canReview()
        ]);
    }

    public function actionReview()
    {
        if (!$this->canReview()) return $this->noPermission();
        $this->assertPostOnly();

        $postId = (int)$this->filter('post_id', 'uint');
        $state = (string)$this->filter('state', 'str');
        $note = trim((string)$this->filter('note', 'str'));
        if (mb_strlen($note, 'UTF-8') > 500) $note = mb_substr($note, 0, 500, 'UTF-8');

        $allowed = ['pending', 'cleared', 'suspicious', 'confirmed'];
        if (!in_array($state, $allowed, true)) $state = 'pending';

        $db = \XF::db();
        $row = $db->fetchRow('SELECT analysis_id, review_state FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1', $postId);
        if (!$row) return $this->notFound();

        $fromState = (string)$row['review_state'];
        $reviewerId = (int)\XF::visitor()->user_id;
        $now = time();

        $db->beginTransaction();
        try
        {
            $db->update('xf_warext_ai_analysis', [
                'review_state' => $state, 'reviewer_user_id' => $reviewerId,
                'reviewed_date' => $now, 'updated_date' => $now
            ], 'analysis_id = ?', (int)$row['analysis_id']);

            if ($fromState !== $state || $note !== '')
            {
                $db->insert('xf_warext_ai_review_log', [
                    'analysis_id' => (int)$row['analysis_id'], 'post_id' => $postId,
                    'reviewer_user_id' => $reviewerId, 'from_state' => $fromState,
                    'to_state' => $state, 'note' => $note, 'created_date' => $now
                ]);
            }
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }

        return $this->asJson(['success' => true, 'state' => $state, 'previousState' => $fromState, 'reviewedDate' => $now]);
    }

    protected function decode($value): array
    {
        if (!$value) return [];
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
