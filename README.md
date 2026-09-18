# Warext Studios | XenForo AI Content Inspector

## Türkçe

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

## Destek

Sorularınız, hata bildirimleriniz, kurulum desteği ve Warext Studios XenForo eklentileriyle ilgili yardım için destek Discord sunucumuza katılabilirsiniz:

**Discord:** https://discord.gg/tgsV5XMcFS

---

## English

This add-on for XenForo 2.3+ evaluates whether forum content may have been generated or edited with AI by combining multiple signals instead of relying on a single indicator. It considers text structure, editor behavior, optional Writing Checker data, the user's previous writing profile, content similarity, and an optional external-model second opinion.

> The system does not apply automatic penalties and does not present its output as definitive AI detection. Risk and confidence scores are signals intended to support moderation decisions.

## Current version

**1.2.0**

V1.2.0 adds a shared AI provider layer with Warext Turkish Writing Checker V1.1.0+ without creating a hard dependency. When both add-ons are installed, the selected provider, model, budget, and usage tracking can be shared from one central configuration. The shared layer operates only through server-side PHP services; no separate AI Content Inspector interop/provider endpoint is exposed to the browser.

## Core operation

Warext Local Engine is always the base analysis layer. External AI providers are completely optional. Local analysis continues to work even when the external API is disabled, not configured, over quota/budget, or temporarily unavailable.

External verification does not run synchronously while a message is being saved. The local result is stored first, and XenForo may then queue a safe verification job when needed. If the content changes before that job runs, a result tied to the old content hash cannot overwrite the newer message.

## Shared AI layer with Turkish Writing Checker V1.1.0+

When both Warext add-ons are installed, Writing Checker detects the `InteropGateway` service capabilities on the server side. There is no required add-on dependency; either add-on continues working independently if the other one is removed.

The shared service supports two task types:

- `writing` — Turkish writing analysis only.
- `moderation + writing` — moderation and writing results in the same provider request.

The live editor's partial caret-centered text window requests **writing only**. Partial text is never cached or reused as though it were a moderation result for the full message. When the complete authored message is available, moderation + writing can be produced by the same provider call.

For combined full-message requests, moderation results may be stored briefly in `xf_warext_ai_interop_cache`, keyed by provider + model + normalized content hash. If `ExternalVerifier` finds the same result when the message is submitted, a second external API request can be skipped. High-frequency shared results are stored in this TTL table instead of XenForo global `SimpleCache`; expired records are removed through probabilistic cleanup and a daily cron task.

## Local analysis layers

The system does not score content from a single "AI sign". Main layers used together include:

- sentence and paragraph length regularity,
- vocabulary diversity,
- connector and templated-language density,
- structured formatting such as lists and headings,
- repeated sentence openings,
- manually typed, pasted, and deleted characters plus editing duration,
- optional Warext Writing Checker correction metrics,
- deviation from the user's previous analyzed writing profile,
- fingerprint similarity with recent content from the same forum.

User-unowned text inside QUOTE, CODE, PHP, HTML, ICODE, and PLAIN blocks is excluded from analysis and from content sent to an external model.

## Result classes

Local signals and, when available, external signals produce a risk/confidence report. Main result classes are:

- `human_likely`
- `low_ai_signal`
- `ai_assistance_possible`
- `ai_heavy_possible`
- `high_risk`

These are not violation decisions. A moderator can separately review the result and set it to `pending`, `cleared`, `suspicious`, or `confirmed`.

## Permission system

The add-on uses four separate XenForo permissions:

- `warextAiViewSimple` — view the simplified report above posts and access the inspection center.
- `warextAiViewDetailed` — view text, behavior, Writing Checker, profile, similarity, and external-verification details.
- `warextAiReview` — review results, change status, and add notes.
- `warextAiManage` — access management features such as API usage, provider health, budgets, and historical scanning.

## Reporting

Authorized users can see a compact report directly inside analyzed threads. Risk, confidence, classification, and moderation status are summarized.

At thread level, the add-on shows the number of analyzed messages, average/highest risk, the number of 70+ risk messages, and the distribution of review statuses. The detailed list of highest-risk messages is queried only for users with `warextAiViewDetailed`.

The detailed report separates:

- local text metrics,
- editor behavior metrics,
- Writing Checker metrics,
- user writing profile,
- content similarity and matched messages,
- external provider second opinion,
- token usage and cost source,
- localized signal explanations,
- moderation review history.

A permission-controlled moderation center is available under `/warext-ai/`, with filters for minimum risk, forum, user, and review status.

## External provider system

The **External AI verification provider** option in ACP supports:

- OpenRouter — recommended cloud layer
- OpenAI / GPT
- Google Gemini
- DeepSeek
- Anthropic Claude
- xAI / Grok
- Mistral AI
- Qwen / Alibaba Model Studio
- Ollama — local OpenAI-compatible service
- Custom OpenAI-Compatible API
- No external provider — Warext Local Engine only

OpenRouter supports a model fallback chain, ZDR routing, data-collection opt-out, price/latency/throughput ordering, and optional response caching.

All external provider paths, including OpenRouter, follow the V1.2.0 shared-task contract. Moderation-only calls retain a small output budget; when writing analysis is requested, the response budget expands enough to return writing issues. Writing responses do not request unnecessary copies of fully corrected text; only issue/correction ranges are returned.

For direct providers, minimum local risk, maximum provider weight, maximum characters sent, and timeout are shared options. No unnecessary external job is created when a provider is not configured.

Custom OpenAI-compatible base URLs accept only HTTP/HTTPS and reject embedded usernames/passwords. API keys are never stored in analysis records or exposed in user-facing reports.

## API usage, budget, and cost

The `xf_warext_ai_usage` table stores, whenever available:

- provider and model,
- related message,
- prompt/input tokens,
- completion/output tokens,
- total tokens,
- cost,
- cost source,
- success/failure state,
- failure reason,
- date.

Cost sources are separated into three types:

- `actual` — the provider reported an actual cost.
- `estimated` — the provider did not report cost; it was calculated from token counts and the current input/output prices configured in ACP.
- `unknown` — a reliable cost could not be determined.

Estimated prices are not hard-coded. Administrators enter the current per-million input/output token prices for the model they use. Actual provider-reported cost always takes priority. If prompt tokens exist without an input price, or completion tokens exist without an output price, the system does not produce a partial estimate and leaves the cost as `unknown`.

ACP also manages:

- maximum external AI requests per day,
- maximum external AI requests per month,
- maximum daily USD budget,
- maximum monthly USD budget,
- usage-log retention period,
- historical scan job batch size,
- enabled/disabled state of the shared Warext AI layer,
- shared-writing maximum characters,
- reuse lifetime for shared moderation results.

Call/budget limits set to `0` are unlimited. When a limit is reached, only external verification stops; local analysis continues.

## Provider health view

Users with `warextAiManage` can view daily/monthly request, token, and cost totals; provider success rate; last-used model; health state; and latest error reason.

Usage records are automatically cleaned by a daily cron according to the configured retention period. The same cron also removes expired shared interop-cache records.

## Historical content scanning

Older messages that have never been analyzed can be scanned in controlled background batches using the `Warext\\AIContentInspector:HistoricalScan` XenForo job.

During scanning:

- a maximum message count can be defined,
- a last-N-days date limit can be applied,
- only forums selected in ACP are considered,
- messages processed per job cycle are limited by `warextAiHistoryBatchSize`,
- historical editor behavior is not invented; typed/paste/Writing Checker data is marked `historical_unobserved`,
- messages above a selected risk threshold can optionally receive external verification,
- daily/monthly request and budget limits also apply to historical scanning,
- previously analyzed messages are not reprocessed by default.

## User writing profile

When a user has at least three previous analysis records, the system builds a local reference profile from up to the latest 25 suitable samples. Profile deviation affects final risk only in a limited way and never determines AI use or a violation by itself.

## Content similarity

A local 64-bit fingerprint is generated for text and compared with recent analyses from the same forum. High similarity is not treated as AI usage; it is a separate moderation signal for copy/repost context. Similarity results and matches are stored in the `similarity_metrics` field.

## Moderation review log

When a moderator changes a result status, the change is stored in `xf_warext_ai_review_log` with old status, new status, moderator, date, and an optional note. If the message itself changes, the previous decision automatically returns to `pending`.

## Warext Turkish Writing Checker integration

Warext Turkish Writing Checker is **not a required dependency**. When both add-ons are installed, client-side correction metrics can be added to analysis context through the existing integration bridge; V1.2.0 also provides the server-side shared provider service.

If Writing Checker is not installed, AI Content Inspector continues working independently. No third integration package is required.

## Performance and security

V1.2.0 includes these safeguards in particular:

- unchanged content with the same SHA-256 hash is not analyzed again,
- external verification does not run synchronously during post save,
- an external job tied to an old content hash cannot update the newer message,
- partial writing-editor windows are never reused as moderation results,
- the shared provider layer exposes no separate public browser API endpoint,
- high-frequency shared results use a dedicated TTL table instead of XenForo global SimpleCache,
- similarity queries are limited to 250 recent records and use the `forum_id + analyzed_date` index,
- user profiles are limited to the latest 25 samples,
- the report batch endpoint processes at most 100 message IDs,
- thread summary does not create a second batch request,
- highest-risk-message queries are skipped for users without detailed permission,
- report JavaScript is loaded only for users with reporting permission,
- the editor tracker installs no event listener when no real message editor exists,
- daily/monthly budget checks use a single aggregate SQL query,
- daily/monthly usage summaries use a single period aggregate query,
- moderation-center status counters are loaded with a single aggregate query.

## Installation / upgrade

Standard XenForo add-on installation is used. The package includes the `upload/` directory, `addon.json`, XenForo `_data` records, and the package-level `hashes.json` integrity file.

During the V1.2.0 upgrade, `xf_warext_ai_interop_cache` is created automatically by `Setup.php`. **No manual SQL is required.** Existing analysis and usage-table data is preserved.

## Automated validation

The GitHub Actions validation pipeline checks:

- all PHP syntax,
- all JavaScript syntax,
- local false-positive regressions,
- calibration and score fusion,
- OpenRouter and direct-provider regressions,
- OpenAI-compatible provider security/regressions,
- V1.2 server-only shared AI contract,
- shared cache table/upgrade/uninstall contract,
- frontend cache-key version matching,
- XenForo XML/master-data integrity,
- absence of a hard Writing Checker dependency,
- asynchronous provider architecture and content-hash protection,
- actual/estimated cost and historical-scan behavior.

## Version summary

- AI Content Inspector: **V1.2.0**
- Compatible Warext Turkish Writing Checker: **V1.1.0+**
- XenForo: **2.3.0+**
- Manual SQL: **not required**

## Support

For questions, bug reports, installation support, and help with Warext Studios XenForo add-ons, you can join our support Discord server:

**Discord:** https://discord.gg/tgsV5XMcFS
