<?php

namespace Warext\AIContentInspector\Pub\Controller;

use XF\Pub\Controller\AbstractController;

class ManualAnalyze extends AbstractController
{
    protected function canAnalyze(): bool
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
        if (!$this->canAnalyze())
        {
            return $this->noPermission();
        }

        $this->assertPostOnly();

        $postId = (int)$this->filter('post_id', 'uint');
        if ($postId <= 0)
        {
            return $this->notFound();
        }

        $post = \XF::em()->find('XF:Post', $postId, ['Thread']);
        if (!$post)
        {
            return $this->notFound();
        }

        if (!method_exists($post, 'warextRunAiAnalysis'))
        {
            return $this->asJson([
                'success' => false,
                'reason' => 'extension_unavailable',
                'message' => 'AI analiz motoru yüklenemedi. Eklenti dosyalarını ve class extension önbelleğini kontrol edin.'
            ]);
        }

        $result = $post->warextRunAiAnalysis(true, true);
        if (empty($result['success']))
        {
            return $this->asJson([
                'success' => false,
                'reason' => (string)($result['reason'] ?? 'analysis_error'),
                'message' => $this->failureMessage($result),
                'chars' => (int)($result['chars'] ?? 0),
                'minimumChars' => (int)($result['minimum_chars'] ?? 0)
            ]);
        }

        $row = \XF::db()->fetchRow(
            'SELECT post_id, thread_id, risk_score, confidence, classification, review_state, external_metrics, updated_date
             FROM xf_warext_ai_analysis
             WHERE post_id = ?
             ORDER BY analysis_id DESC
             LIMIT 1',
            $postId
        );

        if (!$row)
        {
            return $this->asJson([
                'success' => false,
                'reason' => 'result_missing',
                'message' => 'Analiz tamamlandı ancak sonuç kaydı okunamadı.'
            ]);
        }

        $external = json_decode((string)($row['external_metrics'] ?? ''), true);
        if (!is_array($external))
        {
            $external = [];
        }

        return $this->asJson([
            'success' => true,
            'message' => !empty($external['pending'])
                ? 'Yerel analiz tamamlandı. Harici ikinci görüş arka plan kuyruğuna eklendi.'
                : 'Manuel AI analizi tamamlandı.',
            'report' => [
                'postId' => (int)$row['post_id'],
                'threadId' => (int)$row['thread_id'],
                'risk' => (int)$row['risk_score'],
                'confidence' => (int)$row['confidence'],
                'classification' => (string)$row['classification'],
                'reviewState' => (string)$row['review_state'],
                'updatedDate' => (int)$row['updated_date'],
                'externalPending' => !empty($external['pending'])
            ]
        ]);
    }

    protected function failureMessage(array $result): string
    {
        switch ((string)($result['reason'] ?? ''))
        {
            case 'disabled':
                return 'AI içerik denetimi ACP ayarlarından kapalı.';

            case 'empty_text':
                return 'Bu mesajda analiz edilebilir kullanıcı metni bulunamadı.';

            case 'insufficient_text':
                $minimum = max(1, (int)($result['minimum_chars'] ?? 80));
                $chars = max(0, (int)($result['chars'] ?? 0));
                return "Güvenilir manuel analiz için en az {$minimum} karakter gerekir. Bu mesajda {$chars} karakter analiz edilebilir metin var.";

            case 'thread_unavailable':
                return 'Mesajın bağlı olduğu konu/forum bilgisi okunamadı.';

            case 'analysis_error':
                return 'Manuel analiz sırasında bir hata oluştu. Ayrıntı XenForo sunucu hata günlüğüne kaydedildi.';

            default:
                return 'Manuel analiz tamamlanamadı.';
        }
    }
}
