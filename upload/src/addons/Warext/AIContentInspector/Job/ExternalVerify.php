<?php

namespace Warext\AIContentInspector\Job;

use Warext\AIContentInspector\Provider\Registry;
use Warext\AIContentInspector\Service\ExternalVerifier;
use XF\Job\AbstractJob;

class ExternalVerify extends AbstractJob
{
    protected $defaultData = [
        'post_id' => 0,
        'content_hash' => ''
    ];

    public function run($maxRunTime)
    {
        $postId = (int)($this->data['post_id'] ?? 0);
        $expectedHash = (string)($this->data['content_hash'] ?? '');
        if ($postId <= 0 || $expectedHash === '')
        {
            return $this->complete();
        }

        $options = \XF::options();
        $registry = new Registry();
        if (empty($options->warextAiEnabled) || !$registry->isExternalEnabled())
        {
            return $this->complete();
        }

        $db = $this->app->db();
        $row = $db->fetchRow(
            'SELECT analysis_id, content_hash, risk_score, confidence, classification, signal_summary
             FROM xf_warext_ai_analysis
             WHERE post_id = ?
             ORDER BY analysis_id DESC
             LIMIT 1',
            $postId
        );
        if (!$row || !hash_equals((string)$row['content_hash'], $expectedHash))
        {
            return $this->complete();
        }

        $message = $db->fetchOne('SELECT message FROM xf_post WHERE post_id = ?', $postId);
        if (!is_string($message) || hash('sha256', $message) !== $expectedHash)
        {
            return $this->complete();
        }

        $signals = json_decode((string)($row['signal_summary'] ?? ''), true);
        if (!is_array($signals)) $signals = [];

        $result = [
            'risk_score' => (int)$row['risk_score'],
            'confidence' => (int)$row['confidence'],
            'classification' => (string)$row['classification'],
            'signals' => $signals
        ];
        $result = (new ExternalVerifier())->enrich($message, $result);

        $currentHash = $db->fetchOne('SELECT content_hash FROM xf_warext_ai_analysis WHERE analysis_id = ?', (int)$row['analysis_id']);
        if (!is_string($currentHash) || !hash_equals($currentHash, $expectedHash))
        {
            return $this->complete();
        }

        $db->update('xf_warext_ai_analysis', [
            'risk_score' => (int)$result['risk_score'],
            'confidence' => (int)$result['confidence'],
            'classification' => (string)$result['classification'],
            'external_metrics' => json_encode($result['external_verification'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signal_summary' => json_encode($result['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_date' => time()
        ], 'analysis_id = ?', (int)$row['analysis_id']);

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return 'Warext AI harici sağlayıcı doğrulaması işleniyor...';
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
