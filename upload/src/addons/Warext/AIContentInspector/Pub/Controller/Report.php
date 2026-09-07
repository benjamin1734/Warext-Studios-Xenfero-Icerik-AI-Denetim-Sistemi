<?php

namespace Warext\AIContentInspector\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Report extends AbstractController
{
    public function actionBatch()
    {
        if (!\XF::visitor()->hasPermission('general', 'warextAiViewSimple'))
        {
            return $this->noPermission();
        }

        $raw = (string)$this->filter('post_ids', 'str');
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []))));
        $ids = array_slice($ids, 0, 100);
        if (!$ids) return $this->asJson(['reports' => []]);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = \XF::db()->fetchAll("SELECT post_id, risk_score, confidence, classification, review_state, updated_date FROM xf_warext_ai_analysis WHERE post_id IN ($placeholders)", $ids);
        $reports = [];
        foreach ($rows as $row)
        {
            $reports[] = [
                'postId' => (int)$row['post_id'],
                'risk' => (int)$row['risk_score'],
                'confidence' => (int)$row['confidence'],
                'classification' => (string)$row['classification'],
                'reviewState' => (string)$row['review_state'],
                'updatedDate' => (int)$row['updated_date']
            ];
        }

        return $this->asJson([
            'reports' => $reports,
            'canDetailed' => \XF::visitor()->hasPermission('general', 'warextAiViewDetailed')
        ]);
    }

    public function actionDetail()
    {
        if (!\XF::visitor()->hasPermission('general', 'warextAiViewDetailed'))
        {
            return $this->noPermission();
        }

        $postId = (int)$this->filter('post_id', 'uint');
        $row = \XF::db()->fetchRow('SELECT * FROM xf_warext_ai_analysis WHERE post_id = ? ORDER BY analysis_id DESC LIMIT 1', $postId);
        if (!$row) return $this->notFound();

        return $this->asJson([
            'postId' => (int)$row['post_id'],
            'threadId' => (int)$row['thread_id'],
            'userId' => (int)$row['user_id'],
            'forumId' => (int)$row['forum_id'],
            'risk' => (int)$row['risk_score'],
            'confidence' => (int)$row['confidence'],
            'classification' => (string)$row['classification'],
            'reviewState' => (string)$row['review_state'],
            'textMetrics' => $this->decode($row['text_metrics']),
            'behaviorMetrics' => $this->decode($row['behavior_metrics']),
            'writingMetrics' => $this->decode($row['writing_metrics']),
            'signals' => $this->decode($row['signal_summary']),
            'analyzedDate' => (int)$row['analyzed_date'],
            'updatedDate' => (int)$row['updated_date'],
            'canReview' => \XF::visitor()->hasPermission('general', 'warextAiReview')
        ]);
    }

    public function actionReview()
    {
        if (!\XF::visitor()->hasPermission('general', 'warextAiReview'))
        {
            return $this->noPermission();
        }
        $this->assertPostOnly();

        $postId = (int)$this->filter('post_id', 'uint');
        $state = (string)$this->filter('state', 'str');
        $allowed = ['pending', 'cleared', 'suspicious', 'confirmed'];
        if (!in_array($state, $allowed, true)) $state = 'pending';

        $updated = \XF::db()->update('xf_warext_ai_analysis', [
            'review_state' => $state,
            'reviewer_user_id' => (int)\XF::visitor()->user_id,
            'reviewed_date' => time(),
            'updated_date' => time()
        ], 'post_id = ?', $postId);

        return $this->asJson(['success' => (bool)$updated, 'state' => $state]);
    }

    protected function decode($value): array
    {
        if (!$value) return [];
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
