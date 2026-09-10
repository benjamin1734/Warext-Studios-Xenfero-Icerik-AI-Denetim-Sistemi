(() => {
  'use strict';

  if (window.__warextAiManualAnalysis) return;
  window.__warextAiManualAnalysis = true;

  const config = document.getElementById('warext-ai-config')?.dataset || {};

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

  function csrfToken() {
    return document.querySelector('input[name="_xfToken"]')?.value || window.XF?.config?.csrf || '';
  }

  function resultMessage(result, fallback) {
    if (typeof result?.message === 'string' && result.message.trim()) return result.message.trim();
    if (Array.isArray(result?.errors) && result.errors.length) {
      return result.errors
        .map(error => typeof error === 'string' ? error : (error?.message || ''))
        .filter(Boolean)
        .join(' ');
    }
    if (typeof result?.error === 'string' && result.error.trim()) return result.error.trim();
    if (typeof result?.exception?.message === 'string' && result.exception.message.trim()) return result.exception.message.trim();
    return fallback;
  }

  function notify(message, error = false) {
    if (window.XF && typeof window.XF.flashMessage === 'function') {
      try {
        window.XF.flashMessage(message, error ? 5000 : 3500);
        return;
      } catch (_) {}
    }
    if (error) window.alert(message);
  }

  function xfPost(url, data) {
    if (window.XF && typeof window.XF.ajax === 'function') {
      return new Promise((resolve, reject) => {
        let settled = false;
        try {
          const request = window.XF.ajax('post', url, data, result => {
            settled = true;
            resolve(result || {});
          });
          if (request && typeof request.catch === 'function') {
            request.catch(error => {
              if (!settled) reject(error);
            });
          }
        } catch (error) {
          reject(error);
        }
      });
    }

    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(data || {})) body.set(key, String(value));
    const token = csrfToken();
    if (token) body.set('_xfToken', token);
    body.set('_xfResponseType', 'json');

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    }).then(async response => {
      let result = null;
      try { result = await response.json(); } catch (_) {}
      if (!response.ok) {
        throw new Error(resultMessage(result, `HTTP ${response.status}: AI analiz isteği başarısız oldu.`));
      }
      return result || {};
    });
  }

  function ensureFallbackReport(report) {
    const postId = Number(report?.postId || 0);
    const message = document.querySelector(`.message[data-content="post-${postId}"]`);
    if (!postId || !message) return;

    const body = message.querySelector('.message-body') || message.querySelector('.message-content');
    if (!body) return;

    let box = message.querySelector('.warextAiReport');
    if (!box) {
      box = document.createElement('div');
      box.className = 'warextAiReport';
      box.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;padding:8px 10px;border:1px solid rgba(127,127,127,.25);border-radius:8px;background:rgba(127,127,127,.06);font-size:12px';
      body.prepend(box);
    }

    box.innerHTML = '';
    const score = document.createElement('strong');
    score.textContent = `AI risk skoru: ${Number(report.risk || 0)}/100`;
    const classification = document.createElement('span');
    classification.textContent = labels[report.classification] || report.classification || 'Belirsiz';
    const confidence = document.createElement('span');
    confidence.textContent = `Güven: ${Number(report.confidence || 0)}/100`;
    const state = document.createElement('span');
    state.textContent = reviewLabels[report.reviewState] || report.reviewState || 'Bekleyen';
    box.append(score, classification, confidence, state);

    if (report.externalPending) {
      const stage = document.createElement('span');
      stage.textContent = 'Harici ikinci görüş bekleniyor…';
      stage.style.opacity = '.72';
      box.append(stage);
    }
  }

  function detailUrl(postId) {
    if (!config.detailEndpoint) return '';
    const url = new URL(config.detailEndpoint, location.href);
    url.searchParams.set('post_id', String(postId));
    return url.toString();
  }

  function sleep(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
  }

  async function pollFinalReport(postId, initialUpdatedDate) {
    const url = detailUrl(postId);
    if (!url) return;

    for (let attempt = 0; attempt < 10; attempt++) {
      await sleep(1500);

      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok) continue;
        const data = await response.json();
        const external = data.externalMetrics || {};
        if (external.pending) continue;

        const fusion = external.fusion || {};
        const externalResult = external.result || {};
        const text = data.textMetrics || {};
        const externalRisk = Number.isFinite(Number(fusion.external_risk))
          ? Number(fusion.external_risk)
          : (Number.isFinite(Number(externalResult.risk_score)) ? Number(externalResult.risk_score) : null);

        const report = {
          postId: Number(data.postId || postId),
          threadId: Number(data.threadId || 0),
          risk: Number(data.risk || 0),
          confidence: Number(data.confidence || 0),
          classification: data.classification || 'unknown',
          reviewState: data.reviewState || 'pending',
          updatedDate: Number(data.updatedDate || initialUpdatedDate || 0),
          externalPending: false,
          analysisStage: 'final',
          engineVersion: text.engine_version || '',
          rawLocalRisk: Number(text.raw_local_risk ?? text.local_text_risk ?? 0),
          calibratedLocalRisk: Number(text.calibrated_local_risk ?? data.risk ?? 0),
          externalRisk
        };

        ensureFallbackReport(report);
        window.dispatchEvent(new CustomEvent('warext-ai-manual-analysis-complete', {detail: {report}}));
        notify('Harici ikinci görüş tamamlandı; nihai AI raporu güncellendi.');
        return;
      } catch (_) {}
    }
  }

  async function analyzePost(link) {
    if (link.dataset.warextBusy === '1') return;

    const postId = Number(link.dataset.postId || 0);
    if (!postId || !link.href) return;

    if (!window.confirm(`Yalnızca mesaj #${postId} mevcut içeriğiyle yeniden AI analizine alınacak. Devam edilsin mi?`)) return;

    const original = link.textContent;
    link.dataset.warextBusy = '1';
    link.textContent = 'Mesaj AI analizinde…';

    try {
      const result = await xfPost(link.href, {post_id: postId});
      if (!result?.success) {
        notify(resultMessage(result, 'Bu mesajın manuel analizi tamamlanamadı.'), true);
        return;
      }

      const report = result.report || {};
      ensureFallbackReport(report);
      window.dispatchEvent(new CustomEvent('warext-ai-manual-analysis-complete', {detail: result}));
      notify(resultMessage(result, 'Mesajın AI analizi tamamlandı.'));

      if (report.externalPending) {
        pollFinalReport(postId, Number(report.updatedDate || 0));
      }
    } catch (error) {
      notify(error?.message || 'Mesaj analizi sırasında bağlantı hatası oluştu.', true);
    } finally {
      link.dataset.warextBusy = '0';
      link.textContent = original;
    }
  }

  document.addEventListener('click', event => {
    const link = event.target.closest('.js-warextAiManualAnalyze');
    if (!link) return;
    event.preventDefault();
    event.stopPropagation();
    analyzePost(link);
  });
})();
