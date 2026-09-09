# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, Writing Checker kullanımı, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 12 — yapım aşamasında**

Alpha 12 ile maliyet/bütçe ve geçmiş tarama katmanı gerçek yönetim sistemine dönüştürüldü. Günlük istek limiti, günlük/aylık USD bütçesi, provider bazlı başarı/sağlık görünümü, token/maliyet özeti, kullanım geçmişi retention temizliği ve XenForo job kuyruğunda kontrollü geçmiş içerik taraması eklendi.

Ayrıca ACP'de **Warext AI İçerik Denetimi** kendi ayrı üst navigasyon kategorisine ve kendi XenForo option group'una sahiptir. Ayarlar genel Setup/Add-ons içerisine dağılmaz.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve AI denetim merkezini görüntüleme.
- `warextAiViewDetailed` — ayrıntılı metin, davranış, Writing Checker, kullanıcı profili ve harici doğrulama verilerini görüntüleme.
- `warextAiReview` — sonucu bekleyen / temizlendi / şüpheli / onaylandı durumlarından biriyle inceleme ve not ekleme.
- `warextAiManage` — API kullanımı, provider sağlığı ve geçmiş tarama gibi yönetim bölümlerine erişim.

## Konu üstü raporlama

Yetkili kullanıcılar analiz edilmiş mesajlarda doğrudan konu içinde kısa bir rapor görür. AI risk puanı, güven puanı, sınıflandırma ve moderasyon inceleme durumu özetlenir. Konu genelinde analiz edilen mesaj sayısı, ortalama/en yüksek risk, yüksek riskli mesaj sayısı ve moderasyon durum dağılımı gösterilir.

Detaylı rapor yetkisi bulunan kullanıcılar metin ölçümleri, editörde yazılan/yapıştırılan karakterler, Writing Checker düzeltmeleri, kullanıcı geçmişine göre profil sapması, analiz sinyalleri, harici provider ikinci görüşü ve son moderasyon kararlarını görebilir.

## Harici provider sistemi

Harici API kullanımı **zorunlu değildir**. Yerel motor her zaman temel analiz katmanıdır. ACP'deki `Harici AI doğrulama sağlayıcısı` alanından şu providerlar seçilebilir:

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

Doğrudan providerlarda minimum yerel risk, maksimum ağırlık, gönderilecek maksimum karakter ve timeout ortak ayarlardır. Provider yapılandırılmamışsa gereksiz harici job kuyruğa eklenmez.

Varsayılan doğrudan model değerleri ACP'den değiştirilebilir; model değişimi eklenti kodu değişikliği gerektirmez.

Harici modele QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan içerik gönderilmez. URL ve BBCode kalıntıları temizlenir. API anahtarları analiz kayıtlarına veya rapor verisine yazılmaz.

## API kullanım, bütçe ve provider sağlık sistemi

`xf_warext_ai_usage` tablosu her harici isteğin provider/model, post ID, token, gerçek maliyet bilgisi mevcutsa maliyet, başarı/hata ve tarih bilgisini tutar. API anahtarı hiçbir kullanım kaydına yazılmaz.

ACP'den yönetilebilen sınırlar:

- günlük maksimum harici AI isteği,
- günlük maksimum USD bütçesi,
- aylık maksimum USD bütçesi,
- kullanım kaydı saklama süresi,
- geçmiş tarama job paket boyutu.

`0` olan istek/bütçe limitleri sınırsız kabul edilir. Bir limit dolduğunda yalnızca harici doğrulama durur; Local Engine çalışmaya devam eder.

AI Denetim Merkezi'nde `warextAiManage` yetkisi bulunan kullanıcılar günlük ve aylık istek/token/maliyet özetini, provider başına başarı oranını, aktif/son modeli, son hata nedenini ve provider sağlık durumunu görebilir.

Kullanım kayıtları retention süresine göre günlük cron ile otomatik temizlenir.

## Geçmiş içerik taraması

Daha önce analiz edilmemiş eski mesajlar `Warext\AIContentInspector:HistoricalScan` XenForo jobı ile arka planda taranabilir.

Geçmiş mesajlarda tarayıcı editör oturumu bulunmadığı için typed/paste/Writing Checker davranışı uydurulmaz. Bu veriler `historical_unobserved` olarak işaretlenir ve detaylı raporda bağlam sinyali olarak görünür.

Tarama seçenekleri:

- maksimum işlenecek mesaj sayısı,
- son N gün veya tüm tarih,
- yalnızca Local Engine,
- risk eşiğini geçen içeriklerde opsiyonel harici provider doğrulaması.

Harici doğrulama seçilse bile günlük/aylık API bütçe sınırları geçerlidir. Daha önce analiz edilmiş mesajlar varsayılan geçmiş taramada tekrar işlenmez.

## Asenkron doğrulama

Harici provider çağrısı mesaj kaydı sırasında yapılmaz. Yerel sonuç önce kaydedilir ve gerekli görülürse `Warext\AIContentInspector:ExternalVerify` XenForo jobı sıraya alınır. Job benzersiz post + içerik hash'i ile oluşturulur. Mesaj job çalışmadan önce değişirse eski job yeni içeriğin sonucunu güncelleyemez.

Harici sonuç tek başına moderasyon kararı oluşturmaz; provider güven puanına göre sınırlı ağırlıkla yerel risk skoruna eklenir.

## Provider mimarisi

`ProviderInterface` ve merkezi `Registry` kullanılır. `AbstractJsonProvider` güvenli metin temizleme, JSON ayrıştırma, ortak sonuç normalizasyonu, token kullanım alanları ve hata fallback mantığını paylaşır.

`OpenAICompatibleProvider` Grok, Mistral, Qwen, Ollama ve özel endpointler için ortak chat-completions istemcisidir. OpenRouter ise önerilen provider olarak ayrı gelişmiş routing/fallback özelliklerini korur.

## Kullanıcı yazım profili

Bir kullanıcının en az üç önceki analiz kaydı varsa sistem son 25 uygun örnekten yerel bir referans profil oluşturur. Profil sapması nihai risk skorunu sınırlı biçimde etkiler; tek başına ihlal veya AI kullanımı kararı oluşturmaz.

## İçerik benzerliği

Metinler için yerel 64-bit fingerprint üretilir. Aynı forumdaki yakın geçmiş analizlerle karşılaştırma yapılabilir. Yüksek benzerlik AI kullanımı olarak değerlendirilmez; kopya/yeniden paylaşım bağlamı için ayrı moderasyon sinyalidir.

## Moderasyon merkezi

`/warext-ai/` altında yetki kontrollü denetim merkezi bulunur. Minimum risk, forum, kullanıcı ve durum filtreleri; konu/mesaj bağlantıları; risk, güven, profil sapması ve inceleme durumları bulunur.

Bir moderatör sonucu durumlandırdığında değişiklik `xf_warext_ai_review_log` tablosunda eski durum, yeni durum, moderatör, tarih ve isteğe bağlı not ile kaydedilir. Mesaj daha sonra düzenlenirse önceki inceleme kararı otomatik olarak `pending` durumuna döner.

## Warext Türkçe Yazım Denetimi entegrasyonu

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa tarayıcıdaki entegrasyon köprüsü otomatik algılanır. Tam mesaj kopyalanmaz; yalnızca düzeltme ölçümleri aktarılır ve başlık ile mesaj gövdesi ayrı tutulur.

Writing Checker kurulu değilse AI Content Inspector eksiksiz biçimde kendi başına çalışmaya devam eder.

## Otomatik doğrulama ve paketleme

GitHub Actions; PHP/JavaScript sözdizimini, yerel false-positive regresyonlarını, provider adapterlarını, Writing Checker hard-dependency bulunmadığını, harici çağrıların asenkron olduğunu, içerik hash korumasını, ayrı ACP navigasyonunu, bütçe seçeneklerini, kullanım retention cronunu, geçmiş tarama jobını, XenForo XML/provider mimarisini ve kurulum ZIP bütünlüğünü doğrular.

## Geliştirme durumu

9 ana adımın ilk 8'i tamamlandı. Son ana adımın provider, maliyet ve geçmiş tarama bölümleri Alpha 9–12 ile tamamlandı.

**Tamamlanan Alpha 9:** OpenAI/GPT, Google Gemini, DeepSeek ve Anthropic Claude doğrudan adapterları.

**Tamamlanan Alpha 10:** xAI/Grok, Mistral, Qwen, Ollama ve özel OpenAI-compatible endpoint; ortak OpenAI-compatible çekirdek.

**Tamamlanan Alpha 11:** provider kullanım/token/maliyet kaydı ve bütçe kontrol çekirdeği.

**Tamamlanan Alpha 12:** ayrı ACP kategorisi, gerçek bütçe ayarları, provider sağlık/maliyet görünümü, retention cron ve kontrollü geçmiş içerik taraması.

**Kalan ana geliştirme grubu:**

1. **Final ACP + rapor + performans + 1.0.0 Stable:** düz/detaylı rapor son arayüz ayrıştırması; sorgu/cache optimizasyonu ve büyük forum testleri; install/upgrade/uninstall kontrolleri; final ZIP, GitHub Release ve dokümantasyon.
