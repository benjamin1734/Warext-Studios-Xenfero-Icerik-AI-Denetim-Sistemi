# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, Writing Checker kullanımı, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 10 — yapım aşamasında**

Alpha 10 ile planlanan provider genişletme katmanı tamamlandı. OpenRouter önerilen varsayılan bulut katmanı olmaya devam ederken OpenAI/GPT, Google Gemini, DeepSeek, Anthropic Claude, xAI/Grok, Mistral AI, Qwen/Alibaba Model Studio, yerel Ollama ve özel OpenAI-compatible endpoint doğrudan seçilebilir hale geldi.

Tüm harici doğrulamalar mesaj kaydı dışında XenForo job kuyruğunda çalışır. API hatası, kota veya bağlantı sorunu yerel sonucu bozmaz.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve AI denetim merkezini görüntüleme.
- `warextAiViewDetailed` — ayrıntılı metin, davranış, Writing Checker, kullanıcı profili ve harici doğrulama verilerini görüntüleme.
- `warextAiReview` — sonucu bekleyen / temizlendi / şüpheli / onaylandı durumlarından biriyle inceleme ve not ekleme.
- `warextAiManage` — sistem yönetimi için ayrılmış yönetim yetkisi.

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

Varsayılan doğrudan model değerleri:

- OpenAI: `gpt-5.6-luna`
- Gemini: `gemini-3.8-flash`
- DeepSeek: `deepseek-v4-flash`
- Anthropic: `claude-sonnet-5`
- xAI: `grok-4.6`
- Mistral: `mistral-small-latest`
- Qwen: `qwen3.8-flash`
- Ollama: `llama3.2` yalnızca örnek varsayılandır; sunucuda pull edilmiş model adıyla değiştirilmelidir.

Model kimlikleri ACP'den değiştirilebilir; eklenti kodu değiştirmek gerekmez. Qwen base URL alanı bölge/workspace adresine göre değiştirilebilir. Ollama ve özel OpenAI-compatible servislerde base URL ACP'den yönetilir.

Harici modele QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan içerik gönderilmez. URL ve BBCode kalıntıları temizlenir. API anahtarları analiz kayıtlarına veya rapor verisine yazılmaz.

Özel OpenAI-compatible base URL yalnızca HTTP/HTTPS kabul eder ve URL içine gömülü kullanıcı adı/şifre reddedilir.

## Asenkron doğrulama

Harici provider çağrısı mesaj kaydı sırasında yapılmaz. Yerel sonuç önce kaydedilir ve gerekli görülürse `Warext\AIContentInspector:ExternalVerify` XenForo jobı sıraya alınır. Job benzersiz post + içerik hash'i ile oluşturulur. Mesaj job çalışmadan önce değişirse eski job yeni içeriğin sonucunu güncelleyemez.

Harici sonuç tek başına moderasyon kararı oluşturmaz; provider güven puanına göre sınırlı ağırlıkla yerel risk skoruna eklenir.

## Provider mimarisi

`ProviderInterface` ve merkezi `Registry` kullanılır. `AbstractJsonProvider` güvenli metin temizleme, JSON ayrıştırma, ortak sonuç normalizasyonu, token kullanım alanları ve hata fallback mantığını paylaşır.

`OpenAICompatibleProvider` ise Grok, Mistral, Qwen, Ollama ve özel endpointler için ortak chat-completions istemcisidir. Böylece aynı kodun beş farklı adapterda kopyalanması engellenir.

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

GitHub Actions; PHP/JavaScript sözdizimini, yerel false-positive regresyonlarını, OpenRouter davranışını, native provider payloadlarını, OpenAI-compatible URL/payload güvenliğini, Writing Checker hard-dependency bulunmadığını, harici çağrıların asenkron olduğunu, içerik hash korumasını, XenForo XML/provider mimarisini ve kurulum ZIP bütünlüğünü doğrular.

## Geliştirme durumu

9 ana adımın ilk 8'i tamamlandı. Son ana adımın provider geliştirme bölümü de Alpha 9 ve Alpha 10 ile tamamlandı.

**Tamamlanan Alpha 9:** OpenAI/GPT, Google Gemini, DeepSeek ve Anthropic Claude doğrudan adapterları.

**Tamamlanan Alpha 10:** xAI/Grok, Mistral, Qwen, Ollama ve özel OpenAI-compatible endpoint; ortak OpenAI-compatible çekirdek ve güvenli base URL doğrulaması.

**Kalan 2 geliştirme grubu:**

1. **Maliyet / batch / geçmiş tarama:** geçmiş içerik toplu tarama, provider batch/queue optimizasyonu, günlük/aylık API limitleri, token ve tahmini/gerçek maliyet takibi, kota/hata fallback görünürlüğü.
2. **Final ACP + rapor + 1.0.0 Stable:** provider sağlık durumu, aktif model, son API hatası, token/maliyet görünümü; sade/detaylı rapor ayrıştırması; performans/cache ve büyük forum testleri; install/upgrade/uninstall kontrolleri; final ZIP/release ve dokümantasyon.
