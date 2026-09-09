(() => {
  'use strict';

  if (window.__warextAiManualAnalysis) return;
  window.__warextAiManualAnalysis = true;

  const labels = {
    human_likely: 'İnsan yazımı ağırlıklı',
    low_ai_signal: 'Düşük AI sinyali',
    ai_assistance_possible: 'AI desteği olabilir',
    ai_heavy_possible: 'AI ağırlıklı olabilir',
    high_risk: 'Yüksek AI riski',
    unknown: 'Belirsiz'
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

  function notify(message, error = false) {
    if (window.XF && typeof window.XF.flashMessage === 'function') {
      try {
        window.XF.flashMessage(message, error ? 5000 : 3500);
        return;
      } catch (_) {}
    }

    if (error) {
      window.alert(message);
    }
  }

  function ensureReportBox(report) {
    const message = document.querySelector(`.message[data-content="post-${Number(report.postId)}"]`);
    if (!message) return;

    let box = message.querySelector('.warextAiReport');
    if (!box) {
      const body = message.querySelector('.message-body') || message.querySelector('.message-content');
      if (!body) return;

      box = document.createElement('div');
      box.className = 'warextAiReport';
      box.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;padding:8px 10px;border:1px solid rgba(127,127,127,.25);border-radius:8px;background:rgba(127,127,127,.06);font-size:12px';

      const score = document.createElement('strong');
      const classification = document.createElement('span');
      const confidence = document.createElement('span');
      const state = document.createElement('span');
      classification.className = 'warextAiReportLabel';
      confidence.className = 'warextAiReportLabel';
      state.className = 'warextAiReportState';
      box.append(score, classification, confidence, state);
      body.prepend(box);
    }

    const score = box.querySelector('strong');
    const meta = box.querySelectorAll('.warextAiReportLabel');
    const state = box.querySelector('.warextAiReportState');

    if (score) score.textContent = `AI riski: ${Number(report.risk || 0)}/100`;
    if (meta[0]) meta[0].textContent = labels[report.classification] || report.classification || 'Belirsiz';
    if (meta[1]) meta[1].textContent = `Güven: ${Number(report.confidence || 0)}/100`;
    if (state) state.textContent = reviewLabels[report.reviewState] || report.reviewState || 'Bekleyen';

    box.dataset.warextManualUpdated = '1';
    if (report.externalPending) {
      box.title = 'Yerel analiz güncellendi; harici ikinci görüş arka plan kuyruğunda.';
    }
  }

  async function analyze(link) {
    if (link.dataset.warextBusy === '1') return;

    const postId = Number(link.dataset.postId || 0);
    if (!postId || !link.href) return;

    if (!window.confirm(`Mesaj #${postId} mevcut içeriğiyle yeniden AI analizine alınacak. Devam edilsin mi?`)) {
      return;
    }

    const original = link.textContent;
    link.dataset.warextBusy = '1';
    link.textContent = 'AI analizi çalışıyor…';

    const body = new URLSearchParams();
    body.set('post_id', String(postId));
    const token = csrfToken();
    if (token) body.set('_xfToken', token);

    try {
      const response = await fetch(link.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString()
      });

      let result = null;
      try {
        result = await response.json();
      } catch (_) {}

      if (!response.ok) {
        throw new Error(result?.message || 'Manuel analiz isteği başarısız oldu.');
      }

      if (!result?.success) {
        notify(result?.message || 'Manuel analiz tamamlanamadı.', true);
        return;
      }

      ensureReportBox(result.report || {});
      window.dispatchEvent(new CustomEvent('warext-ai-manual-analysis-complete', {detail: result}));
      notify(result.message || 'Manuel AI analizi tamamlandı.');
    } catch (error) {
      notify(error?.message || 'Manuel analiz sırasında bağlantı hatası oluştu.', true);
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
    analyze(link);
  });
})();
