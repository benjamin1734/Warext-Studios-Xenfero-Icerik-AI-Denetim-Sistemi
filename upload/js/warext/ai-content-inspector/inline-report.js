(() => {
  'use strict';

  if (window.__warextAiInlineReport) return;
  window.__warextAiInlineReport = true;

  const config = document.getElementById('warext-ai-config')?.dataset || {};
  if (!config.detailEndpoint) return;

  const labels = {
    human_likely: 'İnsan yazımı ağırlıklı',
    low_ai_signal: 'Düşük AI sinyali',
    ai_assistance_possible: 'AI desteği olabilir',
    ai_heavy_possible: 'AI ağırlıklı olabilir',
    high_risk: 'Yüksek AI riski',
    unknown: 'Belirsiz / güven yetersiz'
  };
  const reviewLabels = {
    pending: 'Bekleyen',
    cleared: 'Temizlendi',
    suspicious: 'Şüpheli',
    confirmed: 'Onaylandı'
  };
  const signalLabels = {
    sentence_uniformity: 'Cümle uzunlukları olağandışı düzenli',
    paragraph_uniformity: 'Paragraf uzunlukları düzenli',
    connector_density: 'Bağlaç/geçiş ifadesi yoğunluğu',
    template_language: 'Şablonlaşmış anlatım kalıpları',
    formulaic_language: 'Formülsel anlatım yoğunluğu',
    transition_openings: 'Geçiş ifadeli cümle başlangıçları',
    formal_cadence: 'Düzenli/formel cümle ritmi',
    multi_signal_consistency: 'Birden fazla AI-benzeri sinyal birlikte görüldü',
    local_ensemble_calibration: 'Yerel çoklu-sinyal kalibrasyonu',
    local_external_disagreement: 'Yerel ve harici sonuçlar belirgin biçimde çelişiyor',
    structured_format: 'Yoğun yapılandırılmış anlatım',
    repetitive_openings: 'Tekrarlayan cümle başlangıçları',
    editor_behavior: 'Editör oluşturma davranışı',
    writing_checker_used: 'Writing Checker kullanımı hesaba katıldı',
    excluded_non_authored_blocks: 'Alıntı/kod blokları analiz dışı bırakıldı',
    user_profile_deviation: 'Kullanıcının geçmiş yazım profilinden sapma',
    content_similarity: 'Başka forum içeriğiyle benzerlik',
    high_content_similarity: 'Yüksek içerik benzerliği',
    external_provider_verification: 'Harici AI ikinci görüşü',
    external_verifier_unavailable: 'Harici AI doğrulaması kullanılamadı',
    external_budget_limit: 'Harici API bütçe/istek limiti',
    historical_behavior_unavailable: 'Geçmiş içerikte editör davranışı bilinmiyor',
    manual_analysis: 'Moderatör tarafından manuel analiz edildi'
  };

  function installStyle() {
    if (document.getElementById('warext-ai-inline-report-style')) return;
    const style = document.createElement('style');
    style.id = 'warext-ai-inline-report-style';
    style.textContent = `
      .warextAiInlineReport{margin:0 0 14px;border:1px solid rgba(127,127,127,.28);border-radius:10px;overflow:hidden;background:rgba(127,127,127,.055);font-size:13px}
      .warextAiInlineHead{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-bottom:1px solid rgba(127,127,127,.18)}
      .warextAiInlineTitle{display:flex;align-items:center;gap:8px;font-weight:700}.warextAiInlineTitle:before{content:'AI';display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:22px;padding:0 5px;border-radius:5px;background:rgba(127,127,127,.15);font-size:11px}
      .warextAiInlineState{padding:3px 8px;border-radius:999px;background:rgba(127,127,127,.12);white-space:nowrap}
      .warextAiInlineGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:10px 12px}
      .warextAiInlineCell{padding:8px 9px;border:1px solid rgba(127,127,127,.16);border-radius:7px;min-width:0}.warextAiInlineCell small{display:block;opacity:.65;margin-bottom:3px}.warextAiInlineCell strong,.warextAiInlineCell span{overflow-wrap:anywhere}
      .warextAiInlineRisk{font-size:18px}.warextAiInlineSignals{padding:0 12px 10px}.warextAiInlineSignals b{display:block;margin-bottom:5px}.warextAiInlineSignalsList{display:flex;gap:6px;flex-wrap:wrap}.warextAiInlineSignal{padding:4px 7px;border-radius:6px;background:rgba(127,127,127,.09);font-size:12px}
      .warextAiInlineTechnical{border-top:1px solid rgba(127,127,127,.16);padding:8px 12px 10px}.warextAiInlineTechnical summary{cursor:pointer;font-weight:600}.warextAiInlineTechnicalGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:8px}.warextAiInlineLoading{opacity:.7;margin-top:7px;font-size:12px}
      .warextAiInlineNote{padding:0 12px 10px;font-size:11px;opacity:.68;line-height:1.45}
      @media(max-width:800px){.warextAiInlineGrid{grid-template-columns:repeat(2,minmax(0,1fr))}.warextAiInlineTechnicalGrid{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media(max-width:520px){.warextAiInlineGrid,.warextAiInlineTechnicalGrid{grid-template-columns:1fr}.warextAiInlineHead{align-items:flex-start;flex-direction:column}}
    `;
    document.head.appendChild(style);
  }

  function endpoint(postId) {
    const url = new URL(config.detailEndpoint, location.href);
    url.searchParams.set('post_id', String(postId));
    return url.toString();
  }

  function messageFor(postId) {
    return document.querySelector(`.message[data-content="post-${Number(postId)}"]`);
  }

  function cell(label, value, strong = false) {
    const item = document.createElement('div');
    item.className = 'warextAiInlineCell';
    const small = document.createElement('small');
    small.textContent = label;
    const content = document.createElement(strong ? 'strong' : 'span');
    content.textContent = value;
    item.append(small, content);
    return item;
  }

  function ratio(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return '-';
    return `${Math.round(number * 100)}%`;
  }

  function pasteRatio(behavior) {
    if (!behavior?.observed) return 'Gözlemlenmedi';
    const typed = Math.max(0, Number(behavior.typedChars || 0));
    const pasted = Math.max(0, Number(behavior.pastedChars || 0));
    return typed + pasted ? `${Math.round((pasted / (typed + pasted)) * 100)}%` : '0%';
  }

  function signalText(signal) {
    const label = signalLabels[signal?.key] || signal?.key || 'Sinyal';
    const raw = signal?.value;
    if (raw === undefined || raw === null || raw === '') return label;
    const value = typeof raw === 'object' ? JSON.stringify(raw) : String(raw);
    return `${label}: ${value}`;
  }

  function renderBase(report) {
    const postId = Number(report?.postId || 0);
    const message = messageFor(postId);
    if (!postId || !message) return null;

    const body = message.querySelector('.message-body') || message.querySelector('.message-content');
    if (!body) return null;

    message.querySelectorAll('.warextAiReport').forEach(node => node.remove());
    let panel = message.querySelector('.warextAiInlineReport');
    if (!panel) {
      panel = document.createElement('section');
      panel.className = 'warextAiInlineReport';
      panel.dataset.postId = String(postId);
      body.prepend(panel);
    }

    panel.innerHTML = '';
    panel.dataset.detailLoaded = '0';
    const head = document.createElement('div');
    head.className = 'warextAiInlineHead';
    const title = document.createElement('div');
    title.className = 'warextAiInlineTitle';
    title.textContent = `Mesaj #${postId} · AI Analiz Raporu`;
    const state = document.createElement('span');
    state.className = 'warextAiInlineState';
    state.textContent = report.externalPending
      ? 'Harici doğrulama bekleniyor'
      : (reviewLabels[report.reviewState] || report.reviewState || 'Bekleyen');
    head.append(title, state);

    const grid = document.createElement('div');
    grid.className = 'warextAiInlineGrid';
    const risk = cell('Nihai AI risk skoru', `${Number(report.risk || 0)}/100`, true);
    risk.querySelector('strong')?.classList.add('warextAiInlineRisk');
    grid.append(
      risk,
      cell('Güven', `${Number(report.confidence || 0)}/100`, true),
      cell('Sınıflandırma', labels[report.classification] || report.classification || 'Belirsiz'),
      cell('Durum', reviewLabels[report.reviewState] || report.reviewState || 'Bekleyen')
    );

    const technical = document.createElement('details');
    technical.className = 'warextAiInlineTechnical';
    technical.open = true;
    const summary = document.createElement('summary');
    summary.textContent = 'Analiz ayrıntıları ve skor zinciri';
    const loading = document.createElement('div');
    loading.className = 'warextAiInlineLoading';
    loading.textContent = 'Ayrıntılar görünür olduğunda yükleniyor…';
    technical.append(summary, loading);

    const note = document.createElement('div');
    note.className = 'warextAiInlineNote';
    note.textContent = 'Bu değer AI yazarlık olasılığının matematiksel yüzdesi değildir; çoklu sinyallerden üretilen moderasyon risk skorudur. Düşük güvenli düşük skor insan yazarlığının kanıtı sayılmaz.';
    panel.append(head, grid, technical, note);
    observePanel(panel, postId);
    return panel;
  }

  async function loadDetail(panel, postId) {
    if (!panel || panel.dataset.detailLoaded === '1' || panel.dataset.detailLoading === '1') return;
    panel.dataset.detailLoading = '1';
    const loading = panel.querySelector('.warextAiInlineLoading');
    if (loading) loading.textContent = 'Analiz ayrıntıları yükleniyor…';

    try {
      const response = await fetch(endpoint(postId), {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      const text = data.textMetrics || {};
      const behavior = data.behaviorMetrics || {};
      const writing = data.writingMetrics || {};
      const profile = data.profileMetrics || {};
      const similarity = data.similarityMetrics || {};
      const external = data.externalMetrics || {};
      const signals = Array.isArray(data.signals) ? data.signals : [];

      const technical = panel.querySelector('.warextAiInlineTechnical');
      if (!technical) return;
      loading?.remove();

      const grid = document.createElement('div');
      grid.className = 'warextAiInlineTechnicalGrid';
      const externalResult = external.result || {};
      const provider = externalResult.provider || {};
      const fusion = external.fusion || {};
      const rawLocal = Number(text.raw_local_risk ?? text.local_text_risk ?? 0);
      const calibratedLocal = Number(text.calibrated_local_risk ?? data.risk ?? 0);
      const externalRisk = Number.isFinite(Number(fusion.external_risk))
        ? Number(fusion.external_risk)
        : (Number.isFinite(Number(externalResult.risk_score)) ? Number(externalResult.risk_score) : null);

      let externalText = 'Kullanılmadı';
      if (external.pending) externalText = 'İkinci görüş kuyrukta';
      else if (external.available) externalText = `${provider.label || external.provider || 'Harici AI'} · ${externalRisk ?? 0}/100`;
      else if (external.enabled && external.skipped) externalText = 'Atlandı';

      grid.append(
        cell('Ham yerel skor', `${Math.round(rawLocal)}/100`),
        cell('Kalibre yerel skor', `${Math.round(calibratedLocal)}/100`),
        cell('Harici sağlayıcı skoru', externalRisk === null ? externalText : `${Math.round(externalRisk)}/100`),
        cell('Nihai skor', `${Number(data.risk || 0)}/100`, true),
        cell('Motor sürümü', text.engine_version || 'Eski kayıt'),
        cell('Birleşik güçlü sinyal', String(Number(text.combined_signal_count || 0))),
        cell('Metin', `${Number(text.chars || 0)} karakter · ${Number(text.words || 0)} kelime`),
        cell('Kelime çeşitliliği', ratio(text.lexical_diversity)),
        cell('Paste oranı', pasteRatio(behavior)),
        cell('Yazım profili sapması', profile.available ? `${Number(profile.deviation_score || 0)}/100` : 'Veri yok'),
        cell('İçerik benzerliği', similarity.available ? `${Number(similarity.similarity || 0)}%` : 'Veri yok'),
        cell('Writing Checker', writing.available ? 'Kullanıldı' : 'Kullanılmadı'),
        cell('Harici ikinci görüş', externalText),
        cell('Cümle / paragraf', `${Number(text.sentences || 0)} / ${Number(text.paragraphs || 0)}`),
        cell('Kalibrasyon nedeni', text.calibration_reason || 'Yok')
      );
      technical.appendChild(grid);

      if (signals.length) {
        const signalWrap = document.createElement('div');
        signalWrap.className = 'warextAiInlineSignals';
        const label = document.createElement('b');
        label.textContent = 'Öne çıkan sinyaller';
        const list = document.createElement('div');
        list.className = 'warextAiInlineSignalsList';
        for (const signal of signals.slice(0, 8)) {
          const chip = document.createElement('span');
          chip.className = 'warextAiInlineSignal';
          chip.textContent = signalText(signal);
          list.appendChild(chip);
        }
        signalWrap.append(label, list);
        technical.appendChild(signalWrap);
      }

      panel.dataset.detailLoaded = '1';
    } catch (error) {
      if (loading) loading.textContent = 'Analiz ayrıntıları yüklenemedi. Moderatör panelindeki rapor kullanılabilir.';
    } finally {
      panel.dataset.detailLoading = '0';
    }
  }

  let observer = null;
  function getObserver() {
    if (observer || !('IntersectionObserver' in window)) return observer;
    observer = new IntersectionObserver(entries => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const panel = entry.target;
        loadDetail(panel, Number(panel.dataset.postId || 0));
        observer.unobserve(panel);
      }
    }, {rootMargin: '250px 0px'});
    return observer;
  }

  function observePanel(panel, postId) {
    const io = getObserver();
    if (io) io.observe(panel);
    else loadDetail(panel, postId);
  }

  function hydrateReports(reports) {
    installStyle();
    window.setTimeout(() => {
      for (const report of reports || []) renderBase(report);
    }, 0);
  }

  window.addEventListener('warext-ai-batch-ready', event => {
    hydrateReports(event.detail?.reports || []);
  });

  window.addEventListener('warext-ai-manual-analysis-complete', event => {
    const report = event.detail?.report;
    if (!report?.postId) return;
    installStyle();
    window.setTimeout(() => {
      const panel = renderBase(report);
      if (panel) loadDetail(panel, Number(report.postId));
    }, 0);
  });

  installStyle();
  if (window.__warextAiBatchData?.reports) hydrateReports(window.__warextAiBatchData.reports);
})();
