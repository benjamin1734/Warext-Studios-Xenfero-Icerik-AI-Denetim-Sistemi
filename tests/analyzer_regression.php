<?php

require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Service/RiskClassifier.php';
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
if (((int)$withoutWriting['risk_score'] - (int)$messageWriting['risk_score']) > 3)
{
    failTest('Writing Checker AI riskini gereğinden fazla düşürüyor.');
}

$aiGenerated = <<<'TXT'
Yapay zekâ destekli içerik üretim sistemleri, dijital platformlarda içerik hazırlama süreçlerini önemli ölçüde dönüştürmektedir. Bu sistemler yalnızca metin üretimini hızlandırmakla kalmamakta, aynı zamanda içeriklerin daha tutarlı ve belirli bir yapıya uygun şekilde hazırlanmasına katkı sağlamaktadır.

Bununla birlikte, yapay zekâ kullanımının artması içerik güvenilirliği açısından bazı önemli soruları da beraberinde getirmektedir. Özellikle kullanıcı tarafından gerçekten yazılmış metinlerle otomatik olarak üretilmiş içeriklerin birbirinden ayrılması, moderasyon süreçlerinde kritik bir rol oynamaktadır. Bu bağlamda tek bir göstergeye güvenmek yerine birden fazla sinyalin birlikte değerlendirilmesi daha sağlıklı sonuçlar sunmaktadır.

Ayrıca cümle uzunluklarının birbirine yakın olması, paragrafların benzer biçimde yapılandırılması ve belirli geçiş ifadelerinin düzenli şekilde kullanılması dikkat çekmektedir. Bu durum tek başına yapay zekâ kullanımını kanıtlamasa da diğer göstergelerle birleştiğinde anlamlı bir örüntü oluşturabilmektedir. Bu nedenle analiz sürecinin yalnızca kelime seçimine dayandırılması yeterli değildir.

Öte yandan kullanıcı davranışlarının da göz önünde bulundurulması gerekmektedir. Metnin çok kısa sürede yapıştırılması, düzenleme süresinin düşük olması veya büyük metin bloklarının tek seferde eklenmesi ek bağlam sağlayabilir. Ancak bu tür davranışlar farklı kaynaklardan alıntı yapan kullanıcılar için de görülebileceğinden kesin kanıt olarak değerlendirilmemelidir.

Sonuç olarak etkili bir AI içerik denetim sistemi, dilsel özellikleri, editör davranışlarını ve kullanıcı geçmişini birlikte ele almalıdır. Böyle bir yaklaşım yanlış pozitifleri azaltırken tamamen yapay zekâ ile üretilmiş metinlerin tespit edilme olasılığını artırmaktadır. Aynı zamanda sonuçların kesin hüküm yerine risk ve güven puanı olarak sunulması, moderatörlerin daha dengeli karar vermesine yardımcı olmaktadır.
TXT;
$aiReport = $analyzer->analyze($aiGenerated);
if ((int)$aiReport['risk_score'] < 70)
{
    failTest('Belirgin tam-AI üslubu yeterince yüksek risk üretmedi: ' . (int)$aiReport['risk_score']);
}
if (in_array($aiReport['classification'], ['human_likely', 'low_ai_signal'], true))
{
    failTest('Belirgin tam-AI üslubu insan/düşük AI olarak sınıflandırıldı.');
}
if ((int)($aiReport['text_metrics']['combined_signal_count'] ?? 0) < 4)
{
    failTest('Çoklu AI sinyali birleşimi beklenen seviyede oluşmadı.');
}

$humanCasual = <<<'TXT'
Dün sunucuda yine aynı sorunu yaşadım. İlk başta plugin bozuk sandım ama meğer configte eski bir değer kalmış. 20 dakika log baktım, sonra dosyayı komple silip yeniden oluşturdum ve düzeldi :D

Şimdi tam neden olduğunu bilmiyorum. Java güncellemesinden sonra başladı olabilir ama emin değilim. Başka bir sunucuda aynı jar var, onda hiç sorun çıkmadı. Bence önce configi yedekleyip temiz kurulum deneyin. Olmazsa logu atarsınız, ona göre bakarız.

Bu arada restarttan hemen sonra değil de 5-10 dakika sonra patlıyor. O yüzden test ederken biraz bekleyin, ben ilk denemede düzeldi sanıp boşuna sevindim.
TXT;
$humanReport = $analyzer->analyze($humanCasual);
if ((int)$humanReport['risk_score'] >= 55)
{
    failTest('Doğal forum üslubu gereğinden yüksek AI riski aldı: ' . (int)$humanReport['risk_score']);
}
if (in_array($humanReport['classification'], ['ai_heavy_possible', 'high_risk'], true))
{
    failTest('Doğal forum üslubu AI-ağır/yüksek risk olarak sınıflandırıldı.');
}

$technicalHuman = <<<'TXT'
Sunucuda bağlantı sorununu incelerken önce proxy ve backend loglarını ayrı ayrı karşılaştırdım. Hata yalnızca yeniden başlatmadan sonra oluşuyordu. Velocity yapılandırmasındaki forwarding secret doğruydu; buna karşılık backend tarafında eski bir yapılandırma dosyasının yüklendiğini fark ettim. Dosyayı yeniledikten sonra bağlantı normale döndü.

Bu bulgu tek başına eklentinin hatalı olduğunu göstermiyor. Aynı jar farklı bir test sunucusunda çalıştığı için sorun büyük olasılıkla ortam yapılandırmasına bağlıydı. Benzer bir durumda önce aktif config dosyasının gerçekten beklenen dizinden okunduğunu doğrulamak, ardından proxy ile backend sürümlerini karşılaştırmak daha doğru olur.
TXT;
$technicalReport = $analyzer->analyze($technicalHuman);
if ((int)$technicalReport['risk_score'] >= 65)
{
    failTest('Düzgün teknik insan metni AI-ağır eşiğine taşındı: ' . (int)$technicalReport['risk_score']);
}

echo json_encode([
    'status' => 'ok',
    'quoteExcluded' => true,
    'codeExcluded' => true,
    'titleCorrectionsSeparated' => true,
    'messageCorrectionsBounded' => true,
    'aiFixtureRisk' => (int)$aiReport['risk_score'],
    'humanFixtureRisk' => (int)$humanReport['risk_score'],
    'technicalHumanRisk' => (int)$technicalReport['risk_score']
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
