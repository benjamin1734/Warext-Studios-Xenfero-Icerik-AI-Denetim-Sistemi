# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, Writing Checker kullanımı, kullanıcının önceki yazım profili, içerik benzerliği ve isteğe bağlı harici model ikinci görüşünü birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 9 — yapım aşamasında**

Alpha 9 ile provider-neutral harici doğrulama katmanı gerçek doğrudan provider adapterlarıyla genişletildi. OpenRouter önerilen varsayılan bulut katmanı olmaya devam ederken OpenAI/GPT, Google Gemini, DeepSeek ve Anthropic Claude artık ACP'den doğrudan seçilebilir. Tüm harici doğrulamalar mesaj kaydı dışında XenForo job kuyruğunda çalışır; API hatası, kota veya bağlantı sorunu yerel sonucu bozmaz.

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

Detaylı rapor yetkisi bulunan kullanıcılar aynı alan üzerinden ayrıntılı raporu açabilir. Ayrıntılı raporda metin ölçümleri, editörde yazılan/yapıştırılan karakterler, Writing Checker düzeltmeleri, kullanıcı geçmişine göre profil sapması, analiz sinyalleri, harici provider ikinci görüşü ve son moderasyon kararları gösterilir.

## Harici provider sistemi

Harici API kullanımı **zorunlu değildir**. Yerel motor her zaman temel analiz katmanıdır. ACP'deki `Harici AI doğrulama sağlayıcısı` alanından şu providerlar seçilebilir:

- OpenRouter — önerilen varsayılan bulut katmanı
- OpenAI / GPT — doğrudan Responses API adapterı
- Google Gemini — doğrudan Gemini adapterı
- DeepSeek — doğrudan OpenAI-compatible chat adapterı
- Anthropic Claude — doğrudan Messages API adapterı
- Harici sağlayıcı kullanma — yalnızca Warext Local Engine

OpenRouter tarafında model fallback zinciri, ZDR yönlendirmesi, veri toplama reddi, fiyat/gecikme/throughput sıralaması ve opsiyonel yanıt cache seçeneği bulunur. Doğrudan providerlarda API anahtarı ve model kimliği ayrı tutulur; minimum yerel risk, maksimum ağırlık, gönderilecek maksimum karakter ve timeout ayarları ortak kullanılır.

Varsayılan doğrudan model değerleri:

- OpenAI: `gpt-5.6-luna`
- Gemini: `gemini-3.8-flash`
- DeepSeek: `deepseek-v4-flash`
- Anthropic: `claude-sonnet-5`

Model kimlikleri ACP'den değiştirilebilir; eklenti kodu değiştirmek gerekmez.

Harici modele QUOTE, CODE, PHP, HTML, ICODE ve PLAIN bloklarındaki kullanıcıya ait olmayan içerik gönderilmez. URL ve BBCode kalıntıları temizlenir. API anahtarları analiz kayıtlarına veya rapor verisine yazılmaz.

## Asenkron doğrulama

Harici provider çağrısı mesaj kaydı sırasında yapılmaz. Yerel sonuç önce kaydedilir ve gerekli görülürse `Warext\AIContentInspector:ExternalVerify` XenForo jobı sıraya alınır. Job benzersiz post + içerik hash'i ile oluşturulur. Mesaj job çalışmadan önce değişirse eski job yeni içeriğin sonucunu güncelleyemez.

Seçilen provider yapılandırılmamışsa veya istek başarısız olursa yerel sonuç korunur. Harici sonuç tek başına moderasyon kararı oluşturmaz; provider güven puanına göre sınırlı ağırlıkla yerel risk skoruna eklenir.

## Çoklu provider mimarisi

`ProviderInterface` ve merkezi `Registry` kullanılır. Ortak doğrudan provider davranışı `AbstractJsonProvider` içinde güvenli metin temizleme, JSON ayrıştırma, sonuç normalizasyonu, token kullanım alanları ve hata fallback mantığını paylaşır.

Şu anda uygulanmış providerlar:

- Warext Local Engine
- OpenRouter
- OpenAI / GPT
- Google Gemini
- DeepSeek
- Anthropic Claude

Sonraki provider genişletme grubu için Registry kayıtları hazırdır:

- xAI / Grok
- Mistral
- Qwen
- Ollama
- özel OpenAI-compatible endpoint

Bu kayıtlar uygulanana kadar `implemented=false` tutulur; eklenti olmayan desteği varmış gibi göstermez.

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

GitHub Actions:

1. PHP ve JavaScript sözdizimini,
2. yerel analiz false-positive regresyonlarını,
3. OpenRouter temiz metin / JSON / rota regresyonlarını,
4. OpenAI, Gemini, DeepSeek ve Claude payload regresyonlarını,
5. harici providerların mesaj kaydı sırasında senkron çağrılmadığını,
6. arka plan jobının içerik hash korumasını,
7. XenForo XML ve provider mimarisini,
8. kurulum ZIP bütünlüğü ve `hashes.json` eşleşmesini

otomatik doğrular.

## Geliştirme durumu

9 ana adımın ilk 8'i tamamlandı. Son ana adım dört geliştirme grubuna ayrılmıştır.

**Tamamlanan son grup — Alpha 9:** OpenAI/GPT, Google Gemini, DeepSeek ve Anthropic Claude doğrudan adapterları, ortak provider JSON çekirdeği, ACP provider seçimi ve regresyon testleri.

**Kalan 3 grup:**

1. İkinci provider genişletmesi: xAI/Grok, Mistral, Qwen, Ollama ve özel OpenAI-compatible endpoint.
2. Maliyet/batch: geçmiş içerik toplu tarama, provider batch desteği, günlük/aylık API limitleri, token/maliyet takibi ve kota/hata fallback görünürlüğü.
3. Final ACP/rapor + 1.0.0 Stable: provider sağlık durumu, aktif model/son API hatası/token-maliyet görünümü, rapor ayrıştırması, performans/cache, büyük forum testleri, install/upgrade/uninstall kontrolleri, final ZIP/release ve dokümantasyon.
