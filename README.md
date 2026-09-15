# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, opsiyonel Writing Checker verisi, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonucu kesin AI tespiti olarak sunmaz. Risk ve güven puanları moderasyon kararını destekleyen sinyallerdir.

## Güncel sürüm

**1.0.8 Stable**

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

---

# English

Warext Studios XenForo AI Content Inspector is a XenForo 2.3+ add-on that estimates whether forum content may have been generated or heavily edited with AI by combining multiple signals instead of relying on a single detector. It evaluates text structure, editor behavior, optional Writing Checker metrics, the user's historical writing profile, content similarity, and an optional second opinion from an external AI provider.

> The system does not apply automatic penalties and does not present its result as definitive proof of AI use. Risk and confidence scores are moderation-support signals.

## Current version

**1.0.8 Stable**

Version 1.0.1 replaced an invalid `textarea` option format that could stop XenForo master-data import with XenForo-compatible `textbox + rows` configuration. ACP navigation and phrase exports were also moved to XenForo's canonical `_data` format, and retry safety was added for an analysis table that might remain after a failed 1.0.0 installation.

The stable release combines the provider, reporting, historical-scan, cost, and performance layers developed across Alpha 9–14. Final stabilization reduced daily/monthly external-API budget checks to one aggregate query, usage summaries to one period query, and moderation-center status counters to one query. Estimated-cost handling was also tightened so a missing token-side price is never silently treated as `0 USD`.

## Core operating model

Warext Local Engine is always the primary analysis layer. External AI providers are completely optional. Local analysis continues even when the external API is disabled, not configured, unavailable, or blocked by quota/budget limits.

External verification never runs synchronously while a post is being saved. The local result is stored first, and when required a safe verification task is added to XenForo's job queue. If the content changes before the job runs, a result belonging to the old content hash cannot overwrite the new message result.

## Local analysis layers

The system does not score content using a single “AI indicator.” Major combined layers include:

- sentence and paragraph length regularity,
- lexical diversity,
- connector and template-like wording density,
- structured formatting such as lists and headings,
- repeated sentence openings,
- manually typed / pasted / deleted characters and editing duration,
- optional Warext Writing Checker correction metrics,
- writing-profile deviation compared with the user's previously analyzed content,
- fingerprint similarity against recent content from the same forum.

Non-authored content inside QUOTE, CODE, PHP, HTML, ICODE, and PLAIN blocks is removed from local analysis and from text prepared for external providers.

## Result classes

Local and, when available, external signals produce a risk/confidence report. Main result classes are:

- `human_likely`
- `low_ai_signal`
- `ai_assistance_possible`
- `ai_heavy_possible`
- `high_risk`

These are not violation decisions. Moderators can separately review a result and mark it as `pending`, `cleared`, `suspicious`, or `confirmed`.

## Permission system

The add-on uses four separate XenForo permissions:

- `warextAiViewSimple` — view the simple in-thread report and moderation center.
- `warextAiViewDetailed` — view text, behavior, Writing Checker, profile, similarity, and external-verification details.
- `warextAiReview` — review results, change review status, and add notes.
- `warextAiManage` — access management features such as API usage, provider health, budgets, and historical scans.

## Reporting

Authorized users see a compact report directly inside analyzed threads. Risk, confidence, classification, and moderation-review state are summarized.

Thread-level reporting shows analyzed-message count, average/highest risk, number of 70+ risk messages, and review-status distribution. Detailed highest-risk message lists are queried only when the user has `warextAiViewDetailed` permission.

Detailed reports separate the following layers:

- local text metrics,
- editor-behavior metrics,
- Writing Checker metrics,
- user writing profile,
- content similarity and matching messages,
- external provider second opinion,
- token usage and cost source,
- localized signal explanations,
- moderation review history.

A permission-controlled moderation center is available at `/warext-ai/`, with minimum-risk, forum, user, and review-status filters.

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

OpenRouter supports a fallback-model chain, ZDR routing, data-collection denial, price/latency/throughput sorting, and optional response caching.

Direct providers share settings for minimum local risk, maximum weighting, maximum transmitted characters, and timeout. If a provider is not configured, unnecessary external jobs are not created.

Custom OpenAI-compatible base URLs accept only HTTP/HTTPS and reject URLs containing embedded usernames or passwords. API keys are not written into analysis records or user-facing reports.

## API usage, budget, and cost

The `xf_warext_ai_usage` table stores, when available:

- provider and model,
- related message,
- prompt/input tokens,
- completion/output tokens,
- total tokens,
- cost,
- cost source,
- success/failure status,
- failure reason,
- timestamp.

Cost source is classified as:

- `actual` — the provider returned the real cost.
- `estimated` — the provider did not return cost; the system calculated it from token usage and the current input/output prices configured in ACP.
- `unknown` — a reliable cost could not be calculated.

Estimated prices are not hard-coded. Administrators enter the current per-million input/output token prices for the model they use. A provider-reported real cost always takes priority. If prompt tokens exist but input pricing is missing, or completion tokens exist but output pricing is missing, the system does not create a partial estimate and leaves the cost as `unknown`.

ACP can also control:

- maximum daily external AI requests,
- maximum monthly external AI requests,
- maximum daily USD budget,
- maximum monthly USD budget,
- usage-record retention duration,
- historical-scan job batch size.

Request and budget limits set to `0` are unlimited. When a limit is reached, only external verification stops; local analysis continues.

## Provider health view

Users with `warextAiManage` permission can view daily/monthly request, token, and cost totals together with provider success rate, last-used model, health status, and latest failure reason.

Usage records are cleaned automatically by a daily cron according to the configured retention period.

## Historical content scanning

Older messages that have never been analyzed can be scanned in a controlled background process using the `Warext\AIContentInspector:HistoricalScan` XenForo job.

During a historical scan:

- a maximum message count can be specified,
- scanning can be limited to the most recent N days,
- ACP-selected forums are respected,
- messages handled per job iteration are limited by `warextAiHistoryBatchSize`,
- historical editor behavior is not invented; typed/paste/Writing Checker data is marked `historical_unobserved`,
- messages above the configured risk threshold can optionally receive an additional external-provider verification,
- daily/monthly request and budget limits also apply to historical scans,
- previously analyzed messages are not processed again by default.

## User writing profile

When a user has at least three previous analysis records, the system builds a local reference profile from up to 25 suitable samples. Profile deviation has only limited influence on final risk and cannot independently determine AI use or a rule violation.

## Content similarity

A local 64-bit fingerprint is generated and compared with recent analyses from the same forum. High similarity is not treated as proof of AI use; it is a separate moderation signal for copied or reposted content. Similarity results and matches are stored in `similarity_metrics`.

## Moderation review log

When a moderator changes review state, `xf_warext_ai_review_log` stores the old state, new state, moderator, timestamp, and optional note. If the message content actually changes, the previous review decision automatically returns to `pending`.

## Warext Turkish Writing Checker integration

Warext Turkish Writing Checker is **not a required dependency**. When both add-ons are installed, the browser integration bridge is detected automatically. The complete message is not copied; only correction metrics are transferred, and title/body remain separated.

AI Content Inspector continues to work independently when Writing Checker is not installed. No third integration package is required.

## Performance and security

The stable release includes the following protections:

- unchanged content with the same SHA-256 hash is not analyzed again,
- external verification never runs synchronously during post save,
- an external job cannot update new message content using an old content hash,
- similarity queries are limited to 250 nearby historical records and use the `forum_id + analyzed_date` index,
- user profiles are limited to the latest 25 suitable samples,
- the report batch endpoint handles at most 100 message IDs,
- thread summaries do not trigger a second batch request,
- highest-risk-message queries do not run for users without detailed-report permission,
- report JavaScript loads only for users who can view reports,
- the editor tracker does not register event listeners when no real message editor is present,
- daily/monthly budget checks use one aggregate SQL query,
- daily/monthly usage summaries use one period aggregate query,
- moderation-center status counters are read with one aggregate query.

## Installation / upgrade

Standard XenForo add-on installation is used. The package contains the `upload/` directory, `addon.json`, XenForo `_data` records, and an internal `hashes.json` integrity file.

When upgrading from existing Alpha builds, database migrations are applied through versioned upgrade steps in `Setup.php`. Stable packages are validated for install/upgrade/uninstall schema behavior and ZIP integrity.

## Automated validation

The GitHub Actions release gate checks:

- all PHP syntax,
- all JavaScript syntax,
- local false-positive regressions,
- OpenRouter and direct-provider regressions,
- OpenAI-compatible provider security/regressions,
- XenForo XML integrity,
- required option/phrase/permission/navigation/cron records,
- absence of a hard Writing Checker dependency,
- asynchronous provider architecture and content-hash protection,
- actual/estimated cost logic and missing-price safety,
- historical-scan job flow,
- install/upgrade/uninstall schema definitions,
- required Stable ZIP files,
- ZIP contents against `hashes.json`.

## Release history summary

- **Alpha 9:** direct adapters for OpenAI/GPT, Gemini, DeepSeek, and Claude.
- **Alpha 10:** Grok, Mistral, Qwen, Ollama, and custom OpenAI-compatible endpoints.
- **Alpha 11:** usage/token/cost tracking core and budget infrastructure.
- **Alpha 12:** dedicated ACP area, daily/monthly limits, provider health view, retention, and historical scanning.
- **Alpha 13:** persistent similarity data, duplicate-analysis prevention, and report/query optimizations.
- **Alpha 14:** actual/estimated/unknown cost separation and token-based cost fallback.
- **1.0.0 Stable:** final query optimizations, missing-price safety, stricter release validation, and stable installation package.
