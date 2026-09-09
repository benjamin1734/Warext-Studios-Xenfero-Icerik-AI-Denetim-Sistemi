<?php

namespace Warext\AIContentInspector\Pub\Controller;

use XF\Pub\Controller\AbstractController;

class ThreadAnalyze extends AbstractController
{
    protected function canRun(): bool
    {
        $visitor = \XF::visitor();
        return $visitor->hasPermission('general', 'warextAiReview')
            || $visitor->hasPermission('general', 'warextAiManage');
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
            return $this->notFound();
        }

        /** @var \XF\Entity\Thread|null $thread */
        $thread = $this->em()->find('XF:Thread', $threadId, ['Forum']);
        if (!$thread)
        {
            return $this->notFound();
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
            return $this->asJson(['queued' => false, 'reason' => 'no_posts']);
        }

        \XF::app()->jobManager()->enqueueUnique(
            'warextAiThreadScan' . $threadId,
            'Warext\\AIContentInspector:HistoricalScan',
            [
                'last_post_id' => 0,
                'processed' => 0,
                'analyzed' => 0,
                'max_posts' => min(5000, $postCount),
                'min_date' => 0,
                'include_external' => false,
                'forum_ids' => [(int)$thread->node_id],
                'thread_ids' => [$threadId]
            ],
            false
        );

        return $this->asJson([
            'queued' => true,
            'threadId' => $threadId,
            'posts' => min(5000, $postCount),
            'external' => false
        ]);
    }
}
