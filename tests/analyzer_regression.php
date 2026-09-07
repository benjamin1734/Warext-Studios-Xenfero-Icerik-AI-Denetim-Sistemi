<?php

require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Service/Analyzer.php';

use Warext\AIContentInspector\Service\Analyzer;

function failTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$analyzer = new Analyzer();

$quoted = '[QUOTE="Test"]' . str_repeat('Alıntılanan uzun içerik yapay zekâ analizine dahil edilmemelidir. ', 40) . '[/QUOTE]' . "\nKısa cevap.";
if ($analyzer->authoredTextLength($quoted) > 40)
{
    failTest('QUOTE içeriği kullanıcı metni uzunluğundan çıkarılmadı.');
}

$coded = '[CODE]' . str_repeat('function example(){ return true; } ', 80) . '[/CODE]' . "\nKendi notum.";
if ($analyzer->authoredTextLength($coded) > 40)
{
    failTest('CODE içeriği kullanıcı metni uzunluğundan çıkarılmadı.');
}

$authored = str_repeat('Bu mesajı kendi deneyimimi anlatmak için yazıyorum ve cümleleri bilinçli olarak farklı biçimlerde kuruyorum. ', 12);
$withQuote = '[QUOTE]' . str_repeat('Başka bir kullanıcının mesajı burada bulunuyor. ', 30) . '[/QUOTE]' . "\n" . $authored;
$report = $analyzer->analyze($withQuote);
if ((int)($report['text_metrics']['excluded_blocks'] ?? 0) < 1)
{
    failTest('Hariç tutulan blok sayısı rapora işlenmedi.');
}
if ((int)($report['text_metrics']['chars'] ?? 0) !== $analyzer->authoredTextLength($withQuote))
{
    failTest('Analiz karakter sayısı kullanıcıya ait metin uzunluğuyla eşleşmiyor.');
}
if (($report['risk_score'] ?? -1) < 0 || ($report['risk_score'] ?? 101) > 100)
{
    failTest('Risk skoru 0-100 aralığında değil.');
}
if (($report['confidence'] ?? -1) < 0 || ($report['confidence'] ?? 101) > 100)
{
    failTest('Güven skoru 0-100 aralığında değil.');
}

$withoutWriting = $analyzer->analyze($authored, [], ['available' => false]);
$titleOnlyWriting = $analyzer->analyze($authored, [], [
    'available' => true,
    'changedChars' => 1000,
    'fields' => [
        'title' => ['changedChars' => 1000],
        'message' => ['changedChars' => 0]
    ]
]);
if ((int)$withoutWriting['risk_score'] !== (int)$titleOnlyWriting['risk_score'])
{
    failTest('Başlık düzeltmeleri mesaj gövdesinin AI riskini değiştirdi.');
}

$messageWriting = $analyzer->analyze($authored, [], [
    'available' => true,
    'changedChars' => 300,
    'fields' => [
        'title' => ['changedChars' => 0],
        'message' => ['changedChars' => 300]
    ]
]);
if ((int)$messageWriting['risk_score'] > (int)$withoutWriting['risk_score'])
{
    failTest('Mesaj Writing Checker düzeltmesi riski ters yönde artırdı.');
}

echo json_encode([
    'status' => 'ok',
    'quoteExcluded' => true,
    'codeExcluded' => true,
    'titleCorrectionsSeparated' => true,
    'messageCorrectionsApplied' => true
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
