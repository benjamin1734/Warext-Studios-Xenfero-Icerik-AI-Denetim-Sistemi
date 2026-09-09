# Değişiklik Günlüğü

## v1.0.5 Stable

- XenForo option-group master-data şeması düzeltildi: `_data/option_groups.xml` artık hatalı `<option_group>` yerine kanonik `<group>` öğesini kullanıyor.
- v1.0.4 ve daha eski kurulumlarda `Add-ons > Options` ekranının `No items have been created yet.` göstermesine neden olan seçenek ilişkilendirme sorunu giderildi.
- `upgrade1000350Step1()` eklendi; yükseltmede `warextAi` option group tekrar doğrulanıyor ve düzeltilmiş master-data seçenekleri yeniden içeri alınabiliyor.
- CI artık legacy `<option_group>` etiketini doğrudan reddediyor, minimum seçenek sayısını ve tüm option → `warextAi` grup ilişkilerini doğruluyor.
- Zorunlu temel seçenek ID'leri release kapısına eklendi; boş/eksik Options paketinin Stable olarak yayınlanması engellendi.
- Frontend cache anahtarı `1000350` olarak yükseltildi.

## v1.0.1 Stable

- XenForo kurulumunu `Exception: Please enter a valid value.` hatasıyla durduran `edit_format="textarea"` kaldırıldı; OpenRouter fallback listesi `textbox` + `rows=4` olarak tanımlandı.
- Forum seçici callback’i XenForo’nun kanonik `XF\Option\Forum::renderSelectMultiple` biçimine geçirildi.
- ACP navigation master-data dosyası gerçek XenForo `admin_navigation_entry` export şemasına dönüştürüldü.
- Public navigation yetki koşulundaki PHP `->` sözdizimi XenForo template dot sözdizimine çevrildi.
- Permission, option, option-group, admin-navigation ve public-navigation phrase anahtarları XenForo’nun kanonik noktalı adlandırmasına geçirildi.
- Başarısız 1.0.0 kurulumundan kalabilecek `xf_warext_ai_analysis` tablosu için kurulum yeniden-deneme güvenliği eklendi.
- JS cache anahtarı `1000310` olarak yükseltildi.
- CI artık desteklenmeyen option edit formatını, hatalı ACP navigation şemasını ve legacy phrase anahtarlarını release engelleyici hata olarak denetler.

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
