# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, opsiyonel Writing Checker verisi, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 14 — yapım aşamasında**

Alpha 14, maliyet/bütçe katmanındaki son önemli boşluğu kapatır. Provider gerçek dolar maliyetini bildirmiyorsa ve token kullanımı mevcutsa, ACP'de yönetici tarafından girilen input/output fiyatlarından tahmini maliyet hesaplanabilir. Gerçek maliyet, tahmini maliyet ve maliyeti bilinmeyen kullanım kayıtları birbirinden açıkça ayrılır.

Alpha 13 ile gelen performans ve veri tutarlılığı geliştirmeleri de korunur:

- içerik benzerliği `similarity_metrics` alanında ayrıca saklanır,
- aynı forumdaki son analizler için `forum_id + analyzed_date` indeksi kullanılır,
- mesaj içeriğinin SHA-256 hash'i değişmemişse aynı içerik yeniden analiz edilmez,
- benzerlik sorgularında mevcut mesaj açıkça dışlanır,
- geçmiş taramadaki harici doğrulama job kimliği provider + mesaj + içerik hash'i ile ayrıştırılır,
- detay yetkisi olmayan kullanıcılarda konu özeti için gereksiz en-riskli-mesajlar sorgusu çalıştırılmaz,
- konu raporu mevcut batch sonucunu tekrar kullanır ve ikinci batch isteği atmaz,
- rapor JavaScript'i yalnız yetkili kullanıcıya yüklenir.

## Temel çalışma mantığı

Warext Local Engine her zaman temel analiz katmanıdır. Harici AI sağlayıcısı tamamen opsiyoneldir. Harici API kapalı, yapılandırılmamış, kota/bütçe sınırına ulaşmış veya erişilemez durumda olsa bile yerel analiz çalışmaya devam eder.

Harici doğrulama mesaj kaydı sırasında senkron çalıştırılmaz. Yerel sonuç önce kaydedilir; gerekli görülürse XenForo job kuyruğuna güvenli bir doğrulama işi eklenir. İçerik job çalışmadan önce değişirse eski içerik hash'ine ait sonuç yeni mesajın üzerine yazılamaz.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve AI denetim merkezini görüntüleme.
- `warextAiViewDetailed` — ayrıntılı metin, davranış, Writing Checker, profil, benzerlik ve harici doğrulama verilerini görüntüleme.
- `warextAiReview` — sonucu `pending`, `cleared`, `suspicious` veya `confirmed` durumlarından biriyle inceleme ve not ekleme.
- `warextAiManage` — API kullanımı/provider sağlığı, bütçe ve geçmiş tarama gibi yönetim özelliklerine erişme.

## Raporlama

Yetkili kullanıcılar analiz edilmiş mesajlarda doğrudan konu içinde kısa bir rapor görür. Risk puanı, güven puanı, sınıflandırma ve moderasyon durumu özetlenir.

Konu genelinde analiz edilen mesaj sayısı, ortalama/en yüksek risk, yüksek riskli mesaj sayısı ve moderasyon durum dağılımı gösterilir. En riskli mesajların ayrıntılı listesi yalnız `warextAiViewDetailed` yetkisi varsa sorgulanır.

Detaylı raporda şu katmanlar ayrı gösterilir:

- yerel metin ölçümleri,
- editör davranış ölçümleri,
- Writing Checker ölçümleri,
- kullanıcı yazım profili,
- içerik benzerliği ölçümleri ve eşleşen mesajlar,
- harici provider ikinci görüşü,
- token kullanımı ve maliyet kaynağı,
- sinyaller ve moderasyon inceleme geçmişi.

Harici provider maliyeti ayrıntılı raporda şu şekilde etiketlenir:

- **Gerçek maliyet** — provider doğrudan maliyet bilgisi bildirdi.
- **Tahmini maliyet** — provider maliyet vermedi; token kullanımı ve ACP fiyatlarıyla hesaplandı.
- **Maliyet bilgisi yok** — güvenilir gerçek veya tahmini maliyet oluşturulamadı.

`/warext-ai/` altında yetki kontrollü moderasyon merkezi bulunur. Minimum risk, forum, kullanıcı ve inceleme durumu filtreleri desteklenir.

## Harici provider sistemi

ACP'deki **Harici AI doğrulama sağlayıcısı** alanından şu seçenekler kullanılabilir:

- OpenRouter — önerilen varsayılan bulut katmanı
- OpenAI / GPT
- Google Gemini
- DeepSeek
- Anthropic Claude
- xAI / Grok
- Mistral AI
- Qwen / Alibaba Model Studio
- Ollama — XenForo sunucusunun erişebildiği yerel OpenAI-compatible servis
- Özel OpenAI-Compatible API
- Harici sağlayıcı kullanma — yalnızca Warext Local Engine

OpenRouter tarafında model fallback zinciri, ZDR yönlendirmesi, veri toplama reddi, fiyat/gecikme/throughput sıralaması ve opsiyonel yanıt cache seçeneği bulunur.

Doğrudan providerlarda minimum yerel risk, maksimum ağırlık, gönderilecek maksimum karakter ve timeout ortak ayarlardır. Provider yapılandırılmamışsa gereksiz dış istek yapılmaz.

Varsayılan model kimlikleri ACP'den değiştirilebilir. Qwen base URL alanı bölge/workspace adresine göre değiştirilebilir. Ollama ve özel OpenAI-compatible servislerde base URL ACP'den yönetilir.

Özel OpenAI-compatible base URL yalnızca HTTP/HTTPS kabul eder ve URL içine gömülü kullanıcı adı/şifre reddedilir.

## Güvenli metin hazırlama

Harici modele QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan içerik gönderilmez. URL ve BBCode kalıntıları temizlenir. API anahtarları analiz kayıtlarına veya moderasyon raporlarına yazılmaz.

## API kullanım, maliyet ve provider sağlığı

`xf_warext_ai_usage` tablosu harici doğrulamalarda mümkün olduğunda şu verileri tutar:

- provider ve model,
- ilgili mesaj,
- prompt/input token,
- completion/output token,
- toplam token,
- maliyet,
- maliyet kaynağı (`actual`, `estimated`, `unknown`),
- başarılı/başarısız durum,
- başarısızlık nedeni,
- tarih.

ACP'den yönetilebilen sınırlar ve maliyet ayarları:

- günlük maksimum harici AI isteği,
- aylık maksimum harici AI isteği,
- günlük maksimum USD bütçesi,
- aylık maksimum USD bütçesi,
- tahmini input fiyatı / 1 milyon token,
- tahmini output fiyatı / 1 milyon token,
- kullanım kaydı saklama süresi,
- geçmiş tarama job paket boyutu.

Tahmini fiyatlar özellikle koda sabitlenmez. Model/provider fiyatları değişebildiği için yönetici kullandığı modelin güncel fiyatını ACP'den girer. Provider gerçek maliyet bildirdiğinde gerçek değer her zaman tahmini değerden önceliklidir.

`0` olan çağrı/bütçe limitleri sınırsız kabul edilir. Tahmini token fiyatları `0` ise ilgili tarafta tahmin yapılmaz. Limit dolduğunda yalnızca harici doğrulama durur; Warext Local Engine çalışmaya devam eder.

`warextAiManage` yetkisine sahip kullanıcılar günlük/aylık istek, token ve maliyet toplamlarını; provider başarı oranını, son kullanılan modeli, provider sağlık durumunu ve son hata nedenini görebilir.

Kullanım kayıtları retention süresine göre günlük cron ile otomatik temizlenir.

## Geçmiş içerik taraması

Daha önce analiz edilmemiş eski mesajlar `Warext\AIContentInspector:HistoricalScan` XenForo jobı ile kontrollü biçimde arka planda taranabilir.

Tarama sırasında:

- maksimum taranacak mesaj sayısı belirlenebilir,
- son N gün ile tarih sınırı verilebilir,
- ACP'de seçilmiş forumlar dikkate alınır,
- tek job turundaki mesaj sayısı `warextAiHistoryBatchSize` ile sınırlandırılır,
- geçmiş editör davranışı bilinmediği için typed/paste/Writing Checker verisi uydurulmaz ve `historical_unobserved` olarak işaretlenir,
- istenirse yalnız risk eşiğini geçen mesajlar seçili harici provider ile ayrıca doğrulanır,
- günlük/aylık çağrı ve bütçe limitleri geçmiş taramada da uygulanır,
- daha önce analiz edilmiş mesajlar varsayılan taramada tekrar işlenmez.

## Kullanıcı yazım profili

Bir kullanıcının en az üç önceki analiz kaydı varsa sistem son 25 uygun örnekten yerel bir referans profil oluşturur. Profil sapması nihai risk skorunu sınırlı biçimde etkiler; tek başına ihlal veya AI kullanımı kararı oluşturmaz.

## İçerik benzerliği

Metinler için yerel 64-bit fingerprint üretilir. Aynı forumdaki yakın geçmiş analizlerle karşılaştırma yapılır. Yüksek benzerlik AI kullanımı olarak değerlendirilmez; kopya/yeniden paylaşım bağlamı için ayrı moderasyon sinyalidir.

Fingerprint yanında hesaplanan benzerlik sonucu ve eşleşme bağlamı `similarity_metrics` içinde ayrıca saklanır; böylece detay raporu sinyal özetini yeniden yorumlamak zorunda kalmaz.

## Moderasyon inceleme kaydı

Bir moderatör sonucu durumlandırdığında değişiklik `xf_warext_ai_review_log` tablosunda eski durum, yeni durum, moderatör, tarih ve isteğe bağlı not ile kaydedilir. Mesaj daha sonra gerçekten değişirse önceki inceleme kararı otomatik olarak `pending` durumuna döner.

## Warext Türkçe Yazım Denetimi entegrasyonu

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa tarayıcıdaki entegrasyon köprüsü otomatik algılanır. Tam mesaj kopyalanmaz; yalnızca gerekli düzeltme ölçümleri aktarılır ve başlık ile mesaj gövdesi ayrı tutulur.

Writing Checker kurulu değilse AI Content Inspector kendi başına eksiksiz çalışmaya devam eder. İki eklenti için üçüncü bir entegrasyon paketi gerekmez.

## Provider mimarisi

`ProviderInterface` ve merkezi `Registry` kullanılır. `AbstractJsonProvider` güvenli metin temizleme, JSON ayrıştırma, ortak sonuç normalizasyonu, token kullanım alanları ve hata fallback mantığını paylaşır.

`OpenAICompatibleProvider` Grok, Mistral, Qwen, Ollama ve özel endpointler için ortak chat-completions istemcisidir. OpenRouter gelişmiş routing/fallback özelliklerini ayrı adapterda korur.

## Otomatik doğrulama ve paketleme

GitHub Actions şu alanları otomatik kontrol eder:

- PHP ve JavaScript sözdizimi,
- yerel false-positive regresyonları,
- provider adapterları,
- Writing Checker hard dependency bulunmaması,
- harici doğrulamanın asenkron çalışması,
- içerik hash koruması,
- günlük/aylık çağrı ve bütçe ayarları,
- gerçek/tahmini maliyet ayrımı,
- provider sağlık/kullanım katmanı,
- geçmiş tarama job mimarisi,
- kullanım geçmişi prune cron'u,
- benzerlik metriği şeması ve forum+tarih indeksi,
- XenForo XML ve kurulum ZIP bütünlüğü.

## Geliştirme durumu

Başlangıçtaki **9 ana adımın ilk 8'i tamamlandı**. Son ana adımın temel geliştirme bölümleri Alpha 9–14 arasında tamamlandı:

- **Alpha 9:** OpenAI/GPT, Google Gemini, DeepSeek ve Anthropic Claude doğrudan adapterları.
- **Alpha 10:** xAI/Grok, Mistral, Qwen, Ollama ve özel OpenAI-compatible endpoint.
- **Alpha 11:** provider kullanım/token/maliyet kayıt çekirdeği ve bütçe kontrol altyapısı.
- **Alpha 12:** ayrı ACP navigasyonu, günlük/aylık çağrı ve bütçe kontrolleri, provider sağlık görünümü, retention temizliği ve kontrollü geçmiş içerik taraması.
- **Alpha 13:** benzerlik verisinin kalıcılaştırılması, duplicate-analysis engeli ve rapor/benzerlik sorgu optimizasyonları.
- **Alpha 14:** provider maliyet vermediğinde token bazlı ACP fiyatlarıyla tahmini maliyet fallback'i ve gerçek/tahmini/bilinmeyen maliyet kaynağı ayrımı.

### Kalan son aşama

**1.0.0 Stable stabilizasyonu:** final install/upgrade/uninstall şema kontrolleri, büyük forum sorgu/yük güvenlik kontrolleri, son ACP/rapor metinleri, stable sürüm kimliği, final ZIP ve GitHub Release.
