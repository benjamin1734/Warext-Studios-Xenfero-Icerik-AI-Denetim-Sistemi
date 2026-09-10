<?php

namespace Warext\AIContentInspector\Pub\Controller;

use Warext\AIContentInspector\Service\ExternalVerifier;
use Warext\AIContentInspector\Service\RiskClassifier;
use XF\Pub\Controller\AbstractController;

class ManualAnalyze extends AbstractController
{
    use JsonResponder;

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

        $row = $this->fetchAnalysisRow($postId);
        if (!$row)
        {
            return $this->asJson([
                'success' => false,
                'reason' => 'result_missing',
                'message' => 'Analiz tamamlandı ancak sonuç kaydı okunamadı.'
            ]);
        }

        $external = $this->decode($row['external_metrics'] ?? null);

        // Automatic analysis uses a local-risk threshold to save API cost. An
        // explicit moderator action is different: if the local score was below
        // that threshold and no job was queued, run the configured provider now.
        // If a job is already pending, do not create a duplicate API request.
        if (empty($external['pending']))
        {
            $stored = [
                'risk_score' => (int)$row['risk_score'],
                'confidence' => (int)$row['confidence'],
                'classification' => (string)$row['classification'],
                'text_metrics' => $this->decode($row['text_metrics'] ?? null),
                'behavior_metrics' => $this->decode($row['behavior_metrics'] ?? null),
                'writing_metrics' => $this->decode($row['writing_metrics'] ?? null),
                'profile_metrics' => $this->decode($row['profile_metrics'] ?? null),
                'similarity_metrics' => $this->decode($row['similarity_metrics'] ?? null),
                'signals' => $this->decode($row['signal_summary'] ?? null)
            ];

            $stored = (new ExternalVerifier())->enrich(
                (string)$post->message,
                $stored,
                [
                    'post_id' => $postId,
                    'force_external' => true,
                    'manual' => true
                ]
            );

            $stored['classification'] = RiskClassifier::classifyWithConfidence(
                (int)($stored['risk_score'] ?? 0),
                (int)($stored['confidence'] ?? 0)
            );

            \XF::db()->update('xf_warext_ai_analysis', [
                'risk_score' => (int)($stored['risk_score'] ?? 0),
                'confidence' => (int)($stored['confidence'] ?? 0),
                'classification' => (string)$stored['classification'],
                'external_metrics' => json_encode($stored['external_verification'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'signal_summary' => json_encode($stored['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_date' => time()
            ], 'analysis_id = ?', (int)$row['analysis_id']);

            $row = $this->fetchAnalysisRow($postId) ?: $row;
            $external = $this->decode($row['external_metrics'] ?? null);
        }
        else
        {
            // A low-confidence local score is not evidence that the author is
            // human. Mark it as uncertain until the queued external result arrives.
            $classification = RiskClassifier::classifyWithConfidence(
                (int)$row['risk_score'],
                (int)$row['confidence']
            );
            if ($classification !== (string)$row['classification'])
            {
                \XF::db()->update('xf_warext_ai_analysis', [
                    'classification' => $classification,
                    'updated_date' => time()
                ], 'analysis_id = ?', (int)$row['analysis_id']);
                $row['classification'] = $classification;
            }
        }

        $text = $this->decode($row['text_metrics'] ?? null);
        $fusion = is_array($external['fusion'] ?? null) ? $external['fusion'] : [];
        $externalResult = is_array($external['result'] ?? null) ? $external['result'] : [];
        $externalRisk = $fusion['external_risk'] ?? ($externalResult['risk_score'] ?? null);

        $pending = !empty($external['pending']);
        $externalAvailable = !empty($external['available']);
        $message = $pending
            ? 'Yerel analiz tamamlandı. Harici ikinci görüş arka plan kuyruğunda; nihai sonuç otomatik güncellenecek.'
            : ($externalAvailable
                ? 'Manuel analiz ve harici ikinci görüş tamamlandı.'
                : 'Manuel AI analizi tamamlandı.');

        return $this->asJson([
            'success' => true,
            'message' => $message,
            'report' => [
                'postId' => (int)$row['post_id'],
                'threadId' => (int)$row['thread_id'],
                'risk' => (int)$row['risk_score'],
                'confidence' => (int)$row['confidence'],
                'classification' => (string)$row['classification'],
                'reviewState' => (string)$row['review_state'],
                'updatedDate' => (int)$row['updated_date'],
                'externalPending' => $pending,
                'analysisStage' => $pending ? 'external_pending' : 'final',
                'engineVersion' => (string)($text['engine_version'] ?? ''),
                'rawLocalRisk' => isset($text['raw_local_risk']) ? (int)$text['raw_local_risk'] : (int)($text['local_text_risk'] ?? 0),
                'calibratedLocalRisk' => isset($text['calibrated_local_risk']) ? (int)$text['calibrated_local_risk'] : (int)$row['risk_score'],
                'externalRisk' => is_numeric($externalRisk) ? (int)$externalRisk : null
            ]
        ]);
    }

    protected function fetchAnalysisRow(int $postId): array
    {
        return \XF::db()->fetchRow(
            'SELECT analysis_id, post_id, thread_id, risk_score, confidence, classification, review_state,
                    text_metrics, behavior_metrics, writing_metrics, profile_metrics, similarity_metrics,
                    external_metrics, signal_summary, updated_date
             FROM xf_warext_ai_analysis
             WHERE post_id = ?
             ORDER BY analysis_id DESC
             LIMIT 1',
            $postId
        ) ?: [];
    }

    protected function decode($value): array
    {
        if (!$value) return [];
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
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
