<?php

require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Service/RiskClassifier.php';
require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Service/LocalCalibration.php';
require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Service/ExternalScoreFusion.php';

use Warext\AIContentInspector\Service\RiskClassifier;
use Warext\AIContentInspector\Service\LocalCalibration;
use Warext\AIContentInspector\Service\ExternalScoreFusion;

function fail114(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$lowConfidence = RiskClassifier::classifyWithConfidence(28, 56);
if ($lowConfidence !== 'unknown')
{
    fail114('28 risk / 56 confidence insan veya düşük AI diye kesinleştirilmemeli.');
}

$synthetic = [
    'risk_score' => 28,
    'confidence' => 56,
    'classification' => 'low_ai_signal',
    'signals' => [],
    'text_metrics' => [
        'words' => 190,
        'sentences' => 10,
        'combined_signal_count' => 5,
        'sentence_uniformity' => 0.63,
        'paragraph_uniformity' => 0.58,
        'template_density' => 0.24,
        'formulaic_density' => 0.22,
        'transition_opening_ratio' => 0.20,
        'formal_cadence' => 0.40,
        'structure_density' => 0.15,
        'local_text_risk' => 28
    ]
];
$calibrated = LocalCalibration::apply($synthetic);
if ((int)$calibrated['risk_score'] < 65)
{
    fail114('5 bağımsız güçlü sinyal taşıyan 28/56 örneği inceleme seviyesine yükselmedi.');
}
if (($calibrated['text_metrics']['engine_version'] ?? '') !== '1.1.4')
{
    fail114('Yerel motor sürüm izi eksik.');
}

$human = [
    'risk_score' => 18,
    'confidence' => 72,
    'classification' => 'human_likely',
    'signals' => [],
    'text_metrics' => [
        'words' => 170,
        'sentences' => 11,
        'combined_signal_count' => 2,
        'sentence_uniformity' => 0.39,
        'paragraph_uniformity' => 0.31,
        'template_density' => 0.05,
        'formulaic_density' => 0.04,
        'transition_opening_ratio' => 0.05,
        'formal_cadence' => 0.10,
        'structure_density' => 0.04,
        'local_text_risk' => 18
    ]
];
$humanCalibrated = LocalCalibration::apply($human);
if ((int)$humanCalibrated['risk_score'] > 25)
{
    fail114('Düşük-sinyalli insan örneği gereksiz yükseltildi.');
}

$fused = ExternalScoreFusion::combine(28, 56, 92, 90, 20, true);
if ((int)$fused['risk'] < 65 || empty($fused['disagreement']))
{
    fail114('Manuel 28 yerel / 92 harici çelişkisi inceleme seviyesine yükselmedi.');
}

$normalFusion = ExternalScoreFusion::combine(28, 56, 92, 90, 20, false);
if ((int)$normalFusion['risk'] >= 65)
{
    fail114('Otomatik harici ikinci görüş manuel zorlamayla aynı şekilde davranmamalı.');
}

echo json_encode([
    'status' => 'ok',
    'lowConfidenceClass' => $lowConfidence,
    'calibratedRisk' => (int)$calibrated['risk_score'],
    'humanRisk' => (int)$humanCalibrated['risk_score'],
    'forcedFusionRisk' => (int)$fused['risk'],
    'forcedFusionConfidence' => (int)$fused['confidence']
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
