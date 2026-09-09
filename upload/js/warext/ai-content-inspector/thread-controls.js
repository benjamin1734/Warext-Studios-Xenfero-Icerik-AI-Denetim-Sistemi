(() => {
  'use strict';
  if (window.__warextAiThreadControls) return;
  window.__warextAiThreadControls = true;

  const config = document.getElementById('warext-ai-config')?.dataset || {};
  if (!config.threadEndpoint || !config.threadAnalyzeEndpoint) return;

  function resolveThreadId() {
    const source = `${location.pathname}${location.search}`;
    const match = source.match(/threads\/(?:[^/?#]*\.)?(\d+)/i);
    return match ? Number(match[1]) : 0;
  }

  function csrfToken() {
    return document.querySelector('input[name="_xfToken"]')?.value || window.XF?.config?.csrf || '';
  }

  function makeUrl(base, key, value) {
    const url = new URL(base, location.href);
    url.searchParams.set(key, String(value));
    return url.toString();
  }

  async function fetchSummary(threadId) {
    const response = await fetch(makeUrl(config.threadEndpoint, 'thread_id', threadId), {
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
    if (!response.ok) return null;
    return response.json();
  }

  function ensurePanel() {
    let panel = document.querySelector('.warextAiThreadSummary');
    if (panel) return panel;

    const first = document.querySelector('.message[data-content^="post-"]');
    if (!first?.parentNode) return null;

    panel = document.createElement('section');
    panel.className = 'warextAiThreadSummary block';
    panel.style.cssText = 'margin-bottom:12px;padding:12px 14px;border:1px solid rgba(127,127,127,.25);border-radius:10px;background:rgba(127,127,127,.06)';
    first.parentNode.insertBefore(panel, first);
    return panel;
  }

  function renderSummary(data) {
    const panel = ensurePanel();
    if (!panel) return;
    panel.innerHTML = '';

    const title = document.createElement('strong');
    title.textContent = 'Konu AI Denetim Özeti';
    panel.appendChild(title);

    if (!data?.available) {
      const empty = document.createElement('div');
      empty.style.cssText = 'margin-top:8px;font-size:12px;opacity:.82';
      empty.textContent = 'Bu konuda henüz AI analiz kaydı bulunmuyor.';
      panel.appendChild(empty);

      if (document.getElementById('warext-ai-review-capability')) {
        const run = document.createElement('button');
        run.type = 'button';
        run.className = 'button button--primary';
        run.style.marginTop = '10px';
        run.textContent = 'Konuyu analiz et';
        run.addEventListener('click', () => queueThread(run));
        panel.appendChild(run);
      }
      return;
    }

    const meta = document.createElement('div');
    meta.style.cssText = 'margin-top:6px;display:flex;flex-wrap:wrap;gap:10px;font-size:12px';
    const parts = [
      `Analiz edilen: ${data.analyzedCount}`,
      `Ortalama risk: ${data.averageRisk}/100`,
      `En yüksek risk: ${data.maxRisk}/100`,
      `70+ riskli mesaj: ${data.highRiskCount}`,
      `Bekleyen: ${data.states?.pending ?? 0}`,
      `Şüpheli: ${data.states?.suspicious ?? 0}`,
      `Onaylı: ${data.states?.confirmed ?? 0}`,
      `Temizlenen: ${data.states?.cleared ?? 0}`
    ];
    for (const part of parts) {
      const span = document.createElement('span');
      span.textContent = part;
      meta.appendChild(span);
    }
    panel.appendChild(meta);

    if (data.canDetailed && Array.isArray(data.top) && data.top.length) {
      const top = document.createElement('div');
      top.style.cssText = 'margin-top:8px;font-size:12px;opacity:.85';
      top.textContent = 'En yüksek riskli mesajlar: ' + data.top.map(item => `#${item.post_id} (${item.risk_score}/100)`).join(' · ');
      panel.appendChild(top);
    }

    const note = document.createElement('div');
    note.style.cssText = 'margin-top:8px;font-size:11px;opacity:.7';
    note.textContent = 'Bu özet moderasyon desteğidir; tek başına AI kullanımı kanıtı değildir.';
    panel.appendChild(note);

    if (document.getElementById('warext-ai-review-capability')) {
      const refresh = document.createElement('button');
      refresh.type = 'button';
      refresh.className = 'button';
      refresh.style.marginTop = '10px';
      refresh.textContent = 'Eksik mesajları tara';
      refresh.addEventListener('click', () => queueThread(refresh));
      panel.appendChild(refresh);
    }
  }

  async function queueThread(button) {
    const threadId = resolveThreadId();
    if (!threadId) return;

    const body = new URLSearchParams();
    body.set('thread_id', String(threadId));
    const token = csrfToken();
    if (token) body.set('_xfToken', token);

    button.disabled = true;
    const previous = button.textContent;
    button.textContent = 'Kuyruğa ekleniyor…';
    try {
      const response = await fetch(config.threadAnalyzeEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString()
      });
      if (!response.ok) throw new Error('queue_failed');
      const result = await response.json();
      button.textContent = result.queued ? 'Analiz kuyruğa eklendi' : 'Analiz başlatılamadı';
      if (result.queued) {
        const info = document.createElement('div');
        info.style.cssText = 'margin-top:8px;font-size:12px;opacity:.78';
        info.textContent = `${result.posts || 0} mesaja kadar yerel analiz kuyruğa alındı. Harici API bu işlemde otomatik kullanılmaz.`;
        button.parentNode?.appendChild(info);
      }
    } catch (_) {
      button.disabled = false;
      button.textContent = previous;
    }
  }

  async function openAnalysis(button) {
    const existing = document.querySelector('.warextAiThreadSummary');
    if (existing) {
      existing.scrollIntoView({behavior: 'smooth', block: 'start'});
      return;
    }

    const threadId = resolveThreadId();
    if (!threadId) return;
    button.disabled = true;
    try {
      renderSummary(await fetchSummary(threadId));
      document.querySelector('.warextAiThreadSummary')?.scrollIntoView({behavior: 'smooth', block: 'start'});
    } finally {
      button.disabled = false;
    }
  }

  function installButton() {
    const threadId = resolveThreadId();
    if (!threadId || document.querySelector('.warextAiThreadButton')) return;

    const host = document.querySelector('.p-title-pageAction') || document.querySelector('.p-title');
    if (!host) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button button--link warextAiThreadButton';
    button.textContent = 'AI Analizi';
    button.addEventListener('click', () => openAnalysis(button));
    host.appendChild(button);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installButton, {once: true});
  } else {
    installButton();
  }
})();
