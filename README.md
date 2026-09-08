# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, Writing Checker kullanımı, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 6 — yapım aşamasında**

Alpha 6 ile OpenRouter doğrulaması mesaj kaydetme isteğinden ayrılmış ve XenForo'nun otomatik job kuyruğuna taşınmıştır. Kullanıcı mesajını gönderirken harici API yanıtını beklemez; yerel analiz hemen kaydedilir, gerekiyorsa OpenRouter ikinci görüşü daha sonra arka planda sonucu günceller.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve AI denetim merkezini görüntüleme.
- `warextAiViewDetailed` — ayrıntılı metin, davranış, Writing Checker, kullanıcı profili ve harici doğrulama verilerini görüntüleme.
- `warextAiReview` — sonucu bekleyen / temizlendi / şüpheli / onaylandı durumlarından biriyle inceleme ve not ekleme.
- `warextAiManage` — sistem yönetimi için ayrılmış yönetim yetkisi.

## Konu üstü raporlama

Yetkili kullanıcılar analiz edilmiş mesajlarda doğrudan konu içinde kısa bir rapor görür:

- AI risk puanı
- güven puanı
- sınıflandırma
- moderasyon inceleme durumu

Konu genelinde ayrıca analiz edilen mesaj sayısı, ortalama/en yüksek risk, yüksek riskli mesaj sayısı ve moderasyon durum dağılımı gösterilir.

Detaylı rapor yetkisi bulunan kullanıcılar aynı alan üzerinden ayrıntılı raporu açabilir. Ayrıntılı raporda metin ölçümleri, editörde yazılan/yapıştırılan karakterler, Writing Checker düzeltmeleri, kullanıcı geçmişine göre profil sapması, analiz sinyalleri, OpenRouter ikinci görüşü ve son moderasyon kararları gösterilir.

## OpenRouter entegrasyonu

OpenRouter **zorunlu değildir** ve önerilen bulut sağlayıcı katmanıdır. ACP'den açılırsa:

- API anahtarı girilebilir,
- model kimliği kod değiştirmeden değiştirilebilir,
- varsayılan model olarak `openrouter/auto` kullanılabilir,
- harici modele gönderilecek maksimum karakter sınırlandırılabilir,
- zaman aşımı belirlenebilir,
- OpenRouter'ın nihai skora azami etkisi sınırlandırılabilir,
- yalnızca belirlenen yerel risk eşiğinin üstündeki içeriklerde API çağrısı yapılarak maliyet düşürülebilir.

OpenRouter çağrısı mesaj kaydı sırasında yapılmaz. Yerel sonuç önce kaydedilir ve gerekli görülürse `Warext\AIContentInspector:ExternalVerify` otomatik XenForo jobı sıraya alınır. Job benzersiz post + içerik hash'i ile oluşturulur. Mesaj job çalışmadan önce değişirse eski job yeni içeriğin sonucunu güncelleyemez.

OpenRouter başarısız olursa veya kota/bağlantı sorunu yaşanırsa yerel sonuç korunur. OpenRouter sonucu tek başına moderasyon kararı oluşturmaz.

Harici modele QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan içerik gönderilmez. API anahtarı rapor verisine yazılmaz.

## Çoklu provider mimarisi

`ProviderInterface` ve merkezi `Registry` kullanılır. Şu anda gerçek provider olarak:

- Warext Local Engine
- OpenRouter

çalışır.

Registry gelecekte şu adapter'lar eklenebilecek şekilde hazırlanmıştır:

- OpenAI / GPT
- Google Gemini
- DeepSeek
- Anthropic Claude
- xAI / Grok
- Mistral
- Qwen
- Ollama
- özel OpenAI-compatible endpoint

Bu kayıtların henüz uygulanmamış olanları sistemde `implemented=false` tutulur; eklenti olmayan desteği varmış gibi göstermez.

## Kullanıcı yazım profili

Bir kullanıcının en az üç önceki analiz kaydı varsa sistem son 25 uygun örnekten yerel bir referans profil oluşturur. Profil sapması nihai risk skorunu sınırlı biçimde etkiler; tek başına ihlal veya AI kullanımı kararı oluşturmaz.

## İçerik benzerliği

Metinler için yerel 64-bit fingerprint üretilir. Aynı forumdaki yakın geçmiş analizlerle karşılaştırma yapılabilir. Yüksek benzerlik AI kullanımı olarak değerlendirilmez; kopya/yeniden paylaşım bağlamı için ayrı moderasyon sinyalidir.

## Moderasyon merkezi

`/warext-ai/` altında yetki kontrollü denetim merkezi bulunur. Minimum risk, forum, kullanıcı ve durum filtreleri; konu/mesaj bağlantıları; risk, güven, profil sapması ve inceleme durumları bulunur.

Bir moderatör sonucu durumlandırdığında değişiklik `xf_warext_ai_review_log` tablosunda eski durum, yeni durum, moderatör, tarih ve isteğe bağlı not ile kaydedilir. Mesaj daha sonra düzenlenirse önceki inceleme kararı otomatik olarak `pending` durumuna döner.

## Warext Türkçe Yazım Denetimi entegrasyonu

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa tarayıcıdaki entegrasyon köprüsü otomatik algılanır. Tam mesaj kopyalanmaz; yalnızca düzeltme ölçümleri aktarılır ve başlık ile mesaj gövdesi ayrı tutulur.

## Otomatik doğrulama ve paketleme

GitHub Actions:

1. PHP ve JavaScript sözdizimini,
2. yerel analiz false-positive regresyonlarını,
3. OpenRouter temiz metin / JSON cevap regresyonlarını,
4. OpenRouter'ın mesaj kaydı sırasında senkron çağrılmadığını,
5. arka plan jobının içerik hash korumasını,
6. XenForo XML ve mimari yapısını,
7. kurulum ZIP bütünlüğü ve `hashes.json` eşleşmesini

otomatik doğrular.

## Geliştirme durumu

Alpha 6 ile tamamlanan ana parçalar: yerel analiz çekirdeği, dört parçalı yetki sistemi, düz/detaylı mesaj raporu, konu genel raporu, Writing Checker entegrasyonu, kullanıcı yazım profili, içerik fingerprint/benzerlik sistemi, moderasyon merkezi, inceleme geçmişi, XenForo forum seçicisi, provider mimarisi, gerçek OpenRouter ikinci görüşü, OpenRouter için arka plan job kuyruğu ve otomatik ZIP paketleme.

Kararlı 1.0.0 öncesi kalan başlıca alanlar: toplu/batch yeniden analiz sistemi, büyük forumlarda performans ve sorgu optimizasyonu, canlı XenForo kurulum/upgrade kombinasyon testleri, false-positive kalibrasyonu ve final release denetimi.
