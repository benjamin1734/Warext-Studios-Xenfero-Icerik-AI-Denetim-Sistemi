# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, opsiyonel Writing Checker verisi, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonucu kesin AI tespiti olarak sunmaz. Risk ve güven puanları moderasyon kararını destekleyen sinyallerdir.

## Güncel sürüm

**1.0.1 Stable**

1.0.1, XenForo kurulumunda master-data importunu durduran geçersiz `textarea` option formatını XenForo uyumlu `textbox + rows` yapısına çevirir. ACP navigation ve phrase exportları da XenForo’nun kanonik `_data` formatına geçirildi; başarısız 1.0.0 kurulumundan kalabilecek analiz tablosunda yeniden deneme güvenliği eklendi.

Stable sürüm Alpha 9–14 arasında geliştirilen provider, raporlama, geçmiş tarama, maliyet ve performans katmanlarını tek kararlı paket altında toplar. Final stabilizasyonda harici API bütçe kontrolündeki günlük/aylık sorgular tek aggregate sorguya, kullanım özeti tek period sorgusuna ve moderasyon merkezi durum sayaçları tek sorguya indirildi. Tahmini maliyet hesabı ayrıca eksik fiyat bulunan token tarafını sessizce `0 USD` kabul etmeyecek şekilde sıkılaştırıldı.

## Temel çalışma mantığı

Warext Local Engine her zaman temel analiz katmanıdır. Harici AI sağlayıcısı tamamen opsiyoneldir. Harici API kapalı, yapılandırılmamış, kota/bütçe sınırına ulaşmış veya erişilemez durumda olsa bile yerel analiz çalışmaya devam eder.

Harici doğrulama mesaj kaydı sırasında senkron çalıştırılmaz. Yerel sonuç önce kaydedilir; gerekli görülürse XenForo job kuyruğuna güvenli bir doğrulama işi eklenir. İçerik job çalışmadan önce değişirse eski içerik hash'ine ait sonuç yeni mesajın üzerine yazılamaz.

## Yerel analiz katmanları

Sistem bir içeriği tek bir “AI belirtisi” ile puanlamaz. Birlikte kullanılan başlıca katmanlar:

- cümle ve paragraf uzunluğu düzenliliği,
- kelime çeşitliliği,
- bağlaç ve şablon anlatım yoğunluğu,
- liste/başlık gibi yapılandırılmış biçim kullanımı,
- tekrar eden cümle başlangıçları,
- editörde manuel yazılan / yapıştırılan / silinen karakterler ve süre,
- opsiyonel Warext Writing Checker düzeltme ölçümleri,
- kullanıcının önceki analiz edilmiş içeriklerine göre yazım profili sapması,
- aynı forumdaki yakın geçmiş içeriklerle fingerprint benzerliği.

QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan metin analizden ve harici modele gönderilecek içerikten çıkarılır.

## Sonuç sınıfları

Yerel ve varsa harici sinyaller bir risk/güven raporu üretir. Kullanılan temel sınıflar:

- `human_likely`
- `low_ai_signal`
- `ai_assistance_possible`
- `ai_heavy_possible`
- `high_risk`

Bunlar ihlal kararı değildir. Moderatör sonucu ayrıca `pending`, `cleared`, `suspicious` veya `confirmed` durumlarından biriyle inceleyebilir.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve denetim merkezini görüntüleme.
- `warextAiViewDetailed` — metin, davranış, Writing Checker, profil, benzerlik ve harici doğrulama ayrıntılarını görüntüleme.
- `warextAiReview` — sonucu inceleme, durumlandırma ve not ekleme.
- `warextAiManage` — API kullanımı, provider sağlığı, bütçe ve geçmiş tarama gibi yönetim özelliklerine erişme.

## Raporlama

Yetkili kullanıcılar analiz edilmiş mesajlarda doğrudan konu içinde kısa rapor görür. Risk, güven, sınıflandırma ve moderasyon durumu özetlenir.

Konu genelinde analiz edilen mesaj sayısı, ortalama/en yüksek risk, 70+ riskli mesaj sayısı ve inceleme durum dağılımı gösterilir. En riskli mesajların ayrıntılı listesi yalnız `warextAiViewDetailed` yetkisi varsa sorgulanır.

Detaylı raporda şu katmanlar ayrı gösterilir:

- yerel metin ölçümleri,
- editör davranış ölçümleri,
- Writing Checker ölçümleri,
- kullanıcı yazım profili,
- içerik benzerliği ve eşleşen mesajlar,
- harici provider ikinci görüşü,
- token kullanımı ve maliyet kaynağı,
- Türkçeleştirilmiş sinyal gerekçeleri,
- moderasyon inceleme geçmişi.

`/warext-ai/` altında yetki kontrollü moderasyon merkezi bulunur. Minimum risk, forum, kullanıcı ve inceleme durumu filtreleri desteklenir.

## Harici provider sistemi

ACP'deki **Harici AI doğrulama sağlayıcısı** alanından şu seçenekler kullanılabilir:

- OpenRouter — önerilen bulut katmanı
- OpenAI / GPT
- Google Gemini
- DeepSeek
- Anthropic Claude
- xAI / Grok
- Mistral AI
- Qwen / Alibaba Model Studio
- Ollama — yerel OpenAI-compatible servis
- Özel OpenAI-Compatible API
- Harici sağlayıcı kullanma — yalnızca Warext Local Engine

OpenRouter tarafında model fallback zinciri, ZDR yönlendirmesi, veri toplama reddi, fiyat/gecikme/throughput sıralaması ve opsiyonel yanıt cache seçeneği bulunur.

Doğrudan providerlarda minimum yerel risk, maksimum ağırlık, gönderilecek maksimum karakter ve timeout ortak ayarlardır. Provider yapılandırılmamışsa gereksiz harici job oluşturulmaz.

Özel OpenAI-compatible base URL yalnız HTTP/HTTPS kabul eder ve URL içine gömülü kullanıcı adı/şifre reddedilir. API anahtarları analiz kayıtlarına veya kullanıcı tarafındaki raporlara yazılmaz.

## API kullanım, bütçe ve maliyet

`xf_warext_ai_usage` tablosu mümkün olduğunda şu verileri tutar:

- provider ve model,
- ilgili mesaj,
- prompt/input token,
- completion/output token,
- toplam token,
- maliyet,
- maliyet kaynağı,
- başarılı/başarısız durum,
- başarısızlık nedeni,
- tarih.

Maliyet kaynağı üç şekilde ayrılır:

- `actual` — provider gerçek maliyet bildirdi.
- `estimated` — provider maliyet vermedi; token miktarı ve ACP'de girilen güncel input/output fiyatlarıyla hesaplandı.
- `unknown` — güvenilir maliyet oluşturulamadı.

Tahmini fiyatlar koda sabitlenmez. Yönetici kullandığı modelin güncel 1 milyon input/output token fiyatlarını ACP'den girer. Provider gerçek maliyet verdiğinde gerçek değer her zaman önceliklidir. Prompt token mevcutken input fiyatı veya completion token mevcutken output fiyatı tanımlı değilse sistem kısmi/eksik tahmin üretmez ve maliyeti `unknown` bırakır.

ACP'den ayrıca şu sınırlar yönetilebilir:

- günlük maksimum harici AI isteği,
- aylık maksimum harici AI isteği,
- günlük maksimum USD bütçesi,
- aylık maksimum USD bütçesi,
- kullanım kaydı saklama süresi,
- geçmiş tarama job paket boyutu.

`0` olan çağrı/bütçe limitleri sınırsızdır. Limit dolduğunda yalnız harici doğrulama durur; yerel analiz devam eder.

## Provider sağlık görünümü

`warextAiManage` yetkisine sahip kullanıcılar günlük/aylık istek, token ve maliyet toplamlarını; provider başarı oranını, son kullanılan modeli, sağlık durumunu ve son hata nedenini görebilir.

Kullanım kayıtları retention süresine göre günlük cron ile otomatik temizlenir.

## Geçmiş içerik taraması

Daha önce analiz edilmemiş eski mesajlar `Warext\AIContentInspector:HistoricalScan` XenForo jobı ile kontrollü biçimde arka planda taranabilir.

Tarama sırasında:

- maksimum mesaj sayısı belirlenebilir,
- son N gün ile tarih sınırı verilebilir,
- ACP'de seçilmiş forumlar dikkate alınır,
- tek job turundaki mesaj sayısı `warextAiHistoryBatchSize` ile sınırlandırılır,
- geçmiş editör davranışı bilinmediği için typed/paste/Writing Checker verisi uydurulmaz ve `historical_unobserved` olarak işaretlenir,
- istenirse risk eşiğini geçen mesajlar seçili harici provider ile ayrıca doğrulanır,
- günlük/aylık çağrı ve bütçe limitleri geçmiş taramada da uygulanır,
- daha önce analiz edilmiş mesajlar varsayılan taramada tekrar işlenmez.

## Kullanıcı yazım profili

Bir kullanıcının en az üç önceki analiz kaydı varsa sistem son 25 uygun örnekten yerel referans profil oluşturur. Profil sapması nihai riski yalnız sınırlı biçimde etkiler; tek başına AI kullanımı veya ihlal kararı oluşturmaz.

## İçerik benzerliği

Metinler için yerel 64-bit fingerprint üretilir ve aynı forumdaki yakın geçmiş analizlerle karşılaştırılır. Yüksek benzerlik AI kullanımı olarak değerlendirilmez; kopya/yeniden paylaşım bağlamı için ayrı moderasyon sinyalidir. Benzerlik sonucu ve eşleşmeler `similarity_metrics` alanında saklanır.

## Moderasyon inceleme kaydı

Bir moderatör sonucu durumlandırdığında değişiklik `xf_warext_ai_review_log` tablosunda eski durum, yeni durum, moderatör, tarih ve isteğe bağlı not ile kaydedilir. Mesaj gerçekten değişirse önceki karar otomatik olarak `pending` durumuna döner.

## Warext Türkçe Yazım Denetimi entegrasyonu

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa tarayıcıdaki entegrasyon köprüsü otomatik algılanır. Tam mesaj kopyalanmaz; yalnız düzeltme ölçümleri aktarılır ve başlık ile mesaj gövdesi ayrı tutulur.

Writing Checker kurulu değilse AI Content Inspector kendi başına çalışmaya devam eder. Üçüncü bir entegrasyon paketi gerekmez.

## Performans ve güvenlik

Stable sürümde özellikle şu önlemler bulunur:

- değişmemiş içerik SHA-256 hash'i tekrar analiz edilmez,
- harici doğrulama post save sırasında senkron çalışmaz,
- harici job eski içerik hash'iyle yeni mesaj sonucunu güncelleyemez,
- benzerlik sorgusu 250 yakın geçmiş kayıtla sınırlıdır ve `forum_id + analyzed_date` indeksini kullanır,
- kullanıcı profili son 25 örnekle sınırlıdır,
- rapor batch endpoint'i en fazla 100 mesaj kimliği işler,
- konu özeti ikinci bir batch isteği oluşturmaz,
- detay yetkisi olmayan kullanıcı için en-riskli-mesaj sorgusu çalışmaz,
- rapor JavaScript'i yalnız rapor yetkisi bulunan kullanıcıya yüklenir,
- editör tracker'ı sayfada gerçek mesaj editörü yoksa event listener kurmaz,
- günlük/aylık bütçe kontrolü tek aggregate SQL sorgusuyla yapılır,
- günlük/aylık kullanım özeti tek period aggregate sorgusuyla hesaplanır,
- moderasyon merkezi durum sayaçları tek aggregate sorguyla alınır.

## Kurulum / yükseltme

Standart XenForo eklenti kurulumu kullanılır. Paket `upload/` dizinini, `addon.json`, XenForo `_data` kayıtlarını ve paket içi `hashes.json` bütünlük dosyasını içerir.

Mevcut Alpha sürümünden yükseltmede veritabanı geçişleri `Setup.php` içindeki versioned upgrade adımlarıyla uygulanır. Stable paket install/upgrade/uninstall şema kontrollerinden ve ZIP bütünlük kontrolünden geçirilir.

## Otomatik doğrulama

GitHub Actions release kapısı şu alanları kontrol eder:

- tüm PHP sözdizimi,
- tüm JavaScript sözdizimi,
- yerel false-positive regresyonları,
- OpenRouter ve doğrudan provider regresyonları,
- OpenAI-compatible provider güvenlik/regresyonları,
- XenForo XML bütünlüğü,
- zorunlu option/phrase/permission/navigation/cron kayıtları,
- Writing Checker hard dependency bulunmaması,
- asenkron provider mimarisi ve içerik-hash koruması,
- gerçek/tahmini maliyet ve eksik fiyat güvenliği,
- geçmiş tarama job akışı,
- install/upgrade/uninstall şema tanımları,
- Stable ZIP zorunlu dosyaları,
- `hashes.json` ile ZIP içeriği eşleşmesi.

## Sürüm geçmişi özeti

- **Alpha 9:** OpenAI/GPT, Gemini, DeepSeek ve Claude doğrudan adapterları.
- **Alpha 10:** Grok, Mistral, Qwen, Ollama ve özel OpenAI-compatible endpoint.
- **Alpha 11:** kullanım/token/maliyet kayıt çekirdeği ve bütçe altyapısı.
- **Alpha 12:** ayrı ACP alanı, günlük/aylık limitler, provider sağlık görünümü, retention ve geçmiş tarama.
- **Alpha 13:** benzerlik verisi kalıcılaştırma, duplicate-analysis engeli ve rapor/sorgu optimizasyonları.
- **Alpha 14:** gerçek/tahmini/bilinmeyen maliyet ayrımı ve token bazlı maliyet fallback'i.
- **1.0.0 Stable:** final sorgu optimizasyonları, eksik fiyat güvenliği, sıkı release doğrulamaları ve kararlı kurulum paketi.
