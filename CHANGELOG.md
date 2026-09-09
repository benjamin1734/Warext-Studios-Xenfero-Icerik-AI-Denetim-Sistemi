# Değişiklik Günlüğü

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
- GitHub commit başlıkları sürüm numarası formatında tutuluyor.
