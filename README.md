# Warext Studios | XenForo İçerik AI Denetim Sistemi

XenForo 2.3+ için geliştirilen bu eklenti, forum içeriklerinin yapay zekâ ile üretilmiş veya yapay zekâ yardımıyla düzenlenmiş olma ihtimalini tek bir işarete dayanarak değil; metin yapısı, editör davranışı, Writing Checker kullanımı ve kullanıcının önceki yazım profili gibi birden fazla yerel sinyali birlikte değerlendirerek raporlar.

> Sistem otomatik ceza vermez ve sonuçları kesin AI tespiti olarak kabul etmez. Üretilen risk/güven puanları moderasyon kararını desteklemek için kullanılır.

## Güncel sürüm

**1.0.0 Alpha 2 — yapım aşamasında**

Bu sürüm artık yalnızca analiz çekirdeği değil; konu üstü raporlama, yetki sistemi, kullanıcı profili, moderasyon merkezi ve denetim geçmişi içeren kullanılabilir geliştirme sürümüdür.

## Yetki sistemi

Eklenti dört ayrı XenForo yetkisi kullanır:

- `warextAiViewSimple` — konu üstündeki düz raporu ve AI denetim merkezini görüntüleme.
- `warextAiViewDetailed` — ayrıntılı metin, davranış, Writing Checker ve kullanıcı profili verilerini görüntüleme.
- `warextAiReview` — sonucu bekleyen / temizlendi / şüpheli / onaylandı durumlarından biriyle inceleme ve not ekleme.
- `warextAiManage` — sistem yönetimi için ayrılmış yönetim yetkisi.

## Konu üstü raporlama

Yetkili kullanıcılar analiz edilmiş mesajlarda doğrudan konu içinde kısa bir rapor görür:

- AI risk puanı
- güven puanı
- sınıflandırma
- moderasyon inceleme durumu

Detaylı rapor yetkisi bulunan kullanıcılar aynı alan üzerinden ayrıntılı raporu açabilir. Ayrıntılı raporda metin ölçümleri, editörde yazılan/yapıştırılan karakterler, Writing Checker düzeltmeleri, kullanıcı geçmişine göre profil sapması, analiz sinyalleri ve son moderasyon kararları gösterilir.

## Kullanıcı yazım profili

Bir kullanıcının en az üç önceki analiz kaydı varsa sistem son 25 uygun örnekten yerel bir referans profil oluşturur. Yeni içerik;

- cümle uzunluğu düzeni,
- kelime çeşitliliği,
- yerel metin risk skoru,
- yapıştırma oranı,
- mesaj gövdesindeki Writing Checker düzeltme oranı

bakımından geçmiş profil ile karşılaştırılır. Profil sapması nihai risk skorunu sınırlı biçimde etkiler; tek başına ihlal veya AI kullanımı kararı oluşturmaz.

## Moderasyon merkezi

`/warext-ai/` altında yetki kontrollü denetim merkezi bulunur. Merkezde:

- bekleyen, şüpheli, onaylanan ve temizlenen kayıt sayıları,
- minimum risk, forum, kullanıcı ve durum filtreleri,
- konu/mesaj bağlantıları,
- risk, güven ve profil sapması,
- sayfalama

bulunur.

Bir moderatör sonucu durumlandırdığında değişiklik `xf_warext_ai_review_log` tablosunda eski durum, yeni durum, moderatör, tarih ve isteğe bağlı not ile kaydedilir. Mesaj daha sonra düzenlenirse önceki inceleme kararı otomatik olarak `pending` durumuna döner ve yeniden değerlendirilmesi gerekir.

## Forum kapsamı

ACP seçeneklerinde analiz edilecek forumlar XenForo'nun yerleşik çoklu forum seçicisi ile seçilir. Hiçbir forum seçilmezse tüm forumlar analiz edilir. Alpha 1 dönemindeki virgülle ayrılmış eski forum ID değeri kod tarafından geriye dönük okunmaya devam eder.

## Warext Türkçe Yazım Denetimi entegrasyonu

Warext Türkçe Yazım Denetimi **zorunlu bağımlılık değildir**. Her iki eklenti kuruluysa tarayıcıdaki entegrasyon köprüsü otomatik algılanır.

Writing Checker köprüsü tam mesaj metnini ikinci eklentiye kopyalamaz. Yalnızca düzeltme ölçümleri aktarılır ve başlık ile mesaj gövdesi ayrı tutulur. AI sistemi profil ve risk hesabında mesaj gövdesine ait düzeltme ölçümlerini kullanır.

## Yerel çalışma ve veri yaklaşımı

- Harici AI API zorunlu değildir.
- Analiz sonucu risk ve güven skoru olarak saklanır.
- Editör davranışı yalnızca ilgili gönderim sırasında toplanan sınırlı ölçümlerden oluşur.
- Writing Checker entegrasyonunda tam içerik kopyası tutulmaz.
- Kullanıcı profili yalnızca bu XenForo kurulumunda oluşmuş geçmiş analiz kayıtlarından üretilir.

## Otomatik doğrulama ve paketleme

GitHub Actions iki ayrı kontrol yürütür:

1. PHP, JavaScript ve XenForo XML/veri yapısı doğrulaması.
2. XenForo arşiv kurulumu için `hashes.json` içeren doğrulanmış Alpha 2 ZIP paketi üretimi.

Paket iş akışı bozuk ZIP, eksik zorunlu dosya, sürüm uyuşmazlığı veya hash uyuşmazlığı tespit ederse başarısız olur.

## Geliştirme durumu

Alpha 2 ile tamamlanan ana parçalar: analiz çekirdeği, dört parçalı yetki sistemi, düz/detaylı konu raporu, Writing Checker entegrasyonu, kullanıcı yazım profili, moderasyon merkezi, inceleme geçmişi, XenForo forum seçicisi ve otomatik ZIP paketleme.

Henüz final olarak kabul edilmeyen başlıca alanlar: canlı XenForo kurulumunda geniş ölçekli uyumluluk testi, false-positive kalibrasyonu, performans/yük testleri, gelişmiş ACP yönetim ekranları ve nihai kararlı sürüm paketlemesi.
