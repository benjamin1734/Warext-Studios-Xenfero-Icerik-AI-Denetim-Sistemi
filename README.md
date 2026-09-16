# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, opsiyonel Writing Checker verisi, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonucu kesin AI tespiti olarak sunmaz. Risk ve güven puanları moderasyon kararını destekleyen sinyallerdir.

## Güncel sürüm

**1.2.0**

V1.2.0, Warext Türkçe Yazım Denetimi V1.1.0+ ile zorunlu bağımlılık oluşturmayan ortak AI sağlayıcı katmanını ekler. İki eklenti birlikte kullanıldığında seçili provider, model, bütçe ve kullanım takibi tek merkezden paylaşılabilir. Ortak katman yalnız sunucu içindeki PHP servisleri üzerinden çalışır; tarayıcıya ayrı bir AI Content Inspector interop/provider endpoint'i açılmaz.

## Temel çalışma mantığı

Warext Local Engine her zaman temel analiz katmanıdır. Harici AI sağlayıcısı tamamen opsiyoneldir. Harici API kapalı, yapılandırılmamış, kota/bütçe sınırına ulaşmış veya erişilemez durumda olsa bile yerel analiz çalışmaya devam eder.

Harici doğrulama mesaj kaydı sırasında senkron çalıştırılmaz. Yerel sonuç önce kaydedilir; gerekli görülürse XenForo job kuyruğuna güvenli bir doğrulama işi eklenir. İçerik job çalışmadan önce değişirse eski içerik hash'ine ait sonuç yeni mesajın üzerine yazılamaz.

## Türkçe Yazım Denetimi V1.1.0+ ortak AI katmanı

İki Warext eklentisi birlikte kurulduğunda Yazım Denetimi, `InteropGateway` servisinin capability bilgisini sunucu tarafında algılar. Zorunlu add-on dependency yoktur; eklentilerden biri kaldırıldığında diğeri bağımsız çalışmaya devam eder.

Ortak servis iki görev türünü destekler:

- `writing` — yalnız Türkçe yazım değerlendirmesi;
- `moderation + writing` — aynı provider isteğinde hem moderasyon hem yazım sonucu.

Canlı editörün imleç çevresindeki kısmi metin penceresi **yalnız writing görevi** ister. Kısmi metin hiçbir zaman tam mesajın moderasyon sonucu gibi önbelleğe alınmaz. Tam yazılmış mesaj kapsamı uygunsa moderasyon + yazım sonucu aynı provider çağrısından üretilebilir.

Birleşik tam-mesaj çağrısında oluşan moderasyon sonucu `xf_warext_ai_interop_cache` tablosunda provider + model + normalize içerik hash'iyle kısa süre tutulabilir. Mesaj gönderildiğinde `ExternalVerifier` aynı sonucu bulursa ikinci harici API isteğini atlar. Bu tablo yüksek değişim frekanslı sonuçları XenForo global `SimpleCache` içine yazmaz; süresi dolan kayıtlar probabilistik temizlik ve günlük cron ile silinir.

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

OpenRouter dahil tüm dış provider yolları V1.2.0 ortak görev sözleşmesine uyar. Yalnız moderasyon çağrıları küçük çıktı bütçesini korur; yazım görevi istendiğinde cevap bütçesi yazım sorunlarını taşıyabilecek şekilde genişler. Yazım cevabında gereksiz tam düzeltilmiş metin kopyası istenmez; yalnız sorun/düzeltme aralıkları döndürülür.

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
- geçmiş tarama job paket boyutu,
- ortak Warext AI katmanının açık/kapalı durumu,
- ortak yazım maksimum karakteri,
- ortak moderasyon sonucu tekrar kullanım süresi.

`0` olan çağrı/bütçe limitleri sınırsızdır. Limit dolduğunda yalnız harici doğrulama durur; yerel analiz devam eder.

## Provider sağlık görünümü

`warextAiManage` yetkisine sahip kullanıcılar günlük/aylık istek, token ve maliyet toplamlarını; provider başarı oranını, son kullanılan modeli, sağlık durumunu ve son hata nedenini görebilir.

Kullanım kayıtları retention süresine göre günlük cron ile otomatik temizlenir. Aynı cron süresi dolan ortak interop cache kayıtlarını da temizler.

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

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa istemci tarafındaki düzeltme ölçümleri mevcut entegrasyon köprüsüyle analiz bağlamına eklenebilir; ayrıca V1.2.0 sunucu taraflı ortak provider servisini sunar.

Writing Checker kurulu değilse AI Content Inspector kendi başına çalışmaya devam eder. Üçüncü bir entegrasyon paketi gerekmez.

## Performans ve güvenlik

V1.2.0 dahil olmak üzere özellikle şu önlemler bulunur:

- değişmemiş içerik SHA-256 hash'i tekrar analiz edilmez,
- harici doğrulama post save sırasında senkron çalışmaz,
- harici job eski içerik hash'iyle yeni mesaj sonucunu güncelleyemez,
- yazım editöründeki kısmi metin pencereleri moderasyon sonucu olarak tekrar kullanılmaz,
- ortak provider katmanı browser'a ayrı bir public API endpoint'i açmaz,
- yüksek frekanslı ortak sonuçlar XenForo global SimpleCache yerine ayrı TTL tablosunda tutulur,
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

V1.2.0 yükseltmesinde `xf_warext_ai_interop_cache` tablosu `Setup.php` tarafından otomatik oluşturulur. **Manuel SQL gerekmez.** Mevcut analiz/kullanım tablolarındaki veriler korunur.

## Otomatik doğrulama

GitHub Actions doğrulama hattı şu alanları kontrol eder:

- bütün PHP sözdizimi,
- bütün JavaScript sözdizimi,
- yerel false-positive regresyonları,
- kalibrasyon ve skor füzyonu,
- OpenRouter ve doğrudan provider regresyonları,
- OpenAI-compatible provider güvenlik/regresyonları,
- V1.2 server-only ortak AI sözleşmesi,
- ortak cache tablo/upgrade/uninstall sözleşmesi,
- frontend cache anahtarının sürümle eşleşmesi,
- XenForo XML/master-data bütünlüğü,
- Writing Checker hard dependency bulunmaması,
- asenkron provider mimarisi ve içerik-hash koruması,
- gerçek/tahmini maliyet ve geçmiş tarama davranışı.

## Sürüm özeti

- AI Content Inspector: **V1.2.0**
- Uyumlu Warext Türkçe Yazım Denetimi: **V1.1.0+**
- XenForo: **2.3.0+**
- Manuel SQL: **gerekmez**
