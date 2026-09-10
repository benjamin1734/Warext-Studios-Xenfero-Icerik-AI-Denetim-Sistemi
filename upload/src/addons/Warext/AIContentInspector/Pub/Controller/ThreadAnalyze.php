<?php

namespace Warext\AIContentInspector\Pub\Controller;

use Warext\AIContentInspector\Job\HistoricalScan;
use XF\Pub\Controller\AbstractController;

class ThreadAnalyze extends AbstractController
{
    protected function canRun(): bool
    {
        $visitor = \XF::visitor();
        return $visitor->hasPermission('general', 'warextAiReview')
            || $visitor->hasPermission('general', 'warextAiManage');
    }

    public function actionIndex()
    {
        return $this->notFound();
    }

    public function actionRun()
    {
        if (!$this->canRun())
        {
            return $this->noPermission();
        }

        $this->assertPostOnly();

        $threadId = (int)$this->filter('thread_id', 'uint');
        if ($threadId <= 0)
        {
            return $this->asJson([
                'success' => false,
                'queued' => false,
                'reason' => 'invalid_thread',
                'message' => 'Analiz edilecek konu kimliği okunamadı.'
            ]);
        }

        /** @var \XF\Entity\Thread|null $thread */
        $thread = $this->em()->find('XF:Thread', $threadId, ['Forum']);
        if (!$thread)
        {
            return $this->asJson([
                'success' => false,
                'queued' => false,
                'reason' => 'thread_not_found',
                'message' => 'Analiz edilecek konu bulunamadı.'
            ]);
        }
        if (!$thread->canView())
        {
            return $this->noPermission();
        }

        $postCount = (int)\XF::db()->fetchOne(
            "SELECT COUNT(*) FROM xf_post WHERE thread_id = ? AND message_state = 'visible'",
            $threadId
        );
        if ($postCount <= 0)
        {
            return $this->asJson([
                'success' => false,
                'queued' => false,
                'reason' => 'no_posts',
                'message' => 'Bu konuda analiz edilebilecek görünür mesaj bulunamadı.'
            ]);
        }

        try
        {
            $jobId = \XF::app()->jobManager()->enqueueUnique(
                'warextAiThreadScan_' . $threadId,
                HistoricalScan::class,
                [
                    'last_post_id' => 0,
                    'processed' => 0,
                    'analyzed' => 0,
                    'max_posts' => min(5000, $postCount),
                    'min_date' => 0,
                    'include_external' => false,
                    'forum_ids' => [(int)$thread->node_id],
                    'thread_ids' => [$threadId],
                    'force_reanalyze' => true,
                    'manual_scan' => true
                ],
                false
            );
        }
        catch (\Throwable $e)
        {
            $reference = strtoupper(substr(hash('sha256', $e->getMessage() . '|' . microtime(true)), 0, 10));
            \XF::logException($e, false, 'Warext AI Thread Analyze [' . $reference . ']: ');

            return $this->asJson([
                'success' => false,
                'queued' => false,
                'reason' => 'queue_exception',
                'errorReference' => $reference,
                'message' => 'Konu AI analiz kuyruğu başlatılamadı. XenForo sunucu hata günlüğü referansı: ' . $reference
            ]);
        }

        return $this->asJson([
            'success' => true,
            'queued' => true,
            'jobId' => (int)($jobId ?? 0),
            'threadId' => $threadId,
            'posts' => min(5000, $postCount),
            'external' => false,
            'message' => min(5000, $postCount) . ' mesaja kadar konu AI analiz kuyruğuna eklendi.'
        ]);
    }
}
