# Değişiklik Günlüğü

## v1.0.0 Stable

- Eklenti `version_id: 1000300`, `version_string: 1.0.0` ile Stable sürüme geçirildi.
- Harici API günlük/aylık istek ve bütçe kontrolü dört ayrı sorgu yerine tek aggregate sorguda hesaplanıyor.
- Günlük ve aylık kullanım/token/maliyet özeti iki period sorgusu yerine tek aggregate sorguda hesaplanıyor.
- Moderasyon merkezindeki `pending`, `suspicious`, `confirmed` ve `cleared` sayaçları dört ayrı sorgu yerine tek aggregate sorguda alınıyor.
- Tahmini maliyet hesabı sıkılaştırıldı: prompt token varsa input fiyatı, completion token varsa output fiyatı tanımlı olmak zorunda. Eksik fiyatlı kısmi tahmin artık üretilmiyor ve maliyet `unknown` kalıyor.
- Stable cache anahtarı `1000300` olarak güncellendi.
- Paket üretim kontrolü HistoricalScan, UsageTracker, UsagePrune, Post extension, admin navigation, cron, option group, phrase ve template modification dosyalarını da zorunlu hale getirdi.
- README ve CHANGELOG final kurulum ZIP'ine dahil edildi.
- Stable CI çıkış kapısı install/upgrade/uninstall şemasını, provider mimarisini, içerik-hash korumasını, maliyet güvenliğini, geçmiş taramayı ve sorgu optimizasyonlarının geri dönmemesini doğruluyor.
- Final paket bütünlüğü `hashes.json`, ZIP CRC ve kaynak/paket sürüm eşleşmesiyle kontrol ediliyor.

## v1.0.0 Alpha 14

- Harici provider gerçek dolar maliyetini bildirmiyorsa token verisinden güvenli tahmini maliyet hesaplama fallback'i eklendi.
- Tahmini input ve output fiyatları ACP'den ayrı ayrı yönetiliyor; model fiyatları koda sabitlenmiyor.
- Maliyet kaynağı `actual`, `estimated` ve `unknown` olarak `xf_warext_ai_usage.cost_source` alanında saklanıyor.
- Alpha 13 → Alpha 14 yükseltmesi için `upgrade1000200Step1()` eklendi.
- Günlük ve aylık bütçe kontrolleri gerçek maliyetin yanında hesaplanabilen tahmini maliyeti de dikkate alıyor.
- Provider gerçek maliyet gönderdiğinde gerçek değer tahmini değerden her zaman öncelikli tutuluyor.
- Detaylı mesaj raporunda maliyet `Gerçek maliyet`, `Tahmini maliyet` veya `Maliyet bilgisi yok` olarak açıkça ayrıştırılıyor.
- Provider kullanım özetine gerçek/tahmini maliyet kayıt sayıları ve son maliyet kaynağı eklendi.
- API bütçe phrase'leri gerçek/tahmini maliyet mantığını açıklayacak şekilde güncellendi.
- Alpha 14 CI doğrulaması; yeni ACP fiyat seçeneklerini, phrase'leri, şema yükseltmesini, maliyet kaynağı kaydını ve detay rapor görünümünü zorunlu kontrol ediyor.

## v1.0.0 Alpha 13

- İçerik benzerliği sonuçları `similarity_metrics` alanında kalıcılaştırıldı ve detaylı rapora ayrı katman olarak eklendi.
- Benzer içerik eşleşmeleri mesaj kimliği ve benzerlik oranıyla detaylı raporda gösteriliyor.
- Detaylı rapor; yerel metin analizi, editör davranışı, Writing Checker, kullanıcı profili, içerik benzerliği, harici provider ve moderasyon geçmişi olarak ayrıştırıldı.
- Harici model raporu OpenRouter'a sabit metin olmaktan çıkarıldı; seçili provider adı/modeli gösteriliyor.
- Ham sinyal anahtarları yerine anlaşılır Türkçe moderasyon gerekçeleri gösteriliyor.
- Aynı içerik SHA-256 hash'i değişmediyse pahalı analiz zinciri tekrar çalıştırılmıyor.
- Benzerlik sorgusu için `forum_id + analyzed_date` indeksi eklendi ve mevcut mesaj karşılaştırmadan çıkarıldı.
- Detay yetkisi olmayan kullanıcı için konu özetinde en yüksek riskli mesaj sorgusu çalıştırılmıyor.
- Konu raporu ikinci bir batch isteği atmıyor; mesaj raporunun mevcut batch sonucunu yeniden kullanıyor.
- Rapor JavaScript dosyaları yalnız `warextAiViewSimple` yetkisi bulunan kullanıcıya yükleniyor.
- Editör davranış tracker'ı yalnız sayfada gerçek mesaj editörü varsa event listener kuruyor.
