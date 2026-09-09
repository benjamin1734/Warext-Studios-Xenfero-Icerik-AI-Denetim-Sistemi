(() => {
  'use strict';
  if (window.__warextAiThreadReport) return;
  window.__warextAiThreadReport = true;

  const config = document.getElementById('warext-ai-config')?.dataset || {};
  if (!config.threadEndpoint) return;

  async function json(url) {
    const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) return null;
    return response.json();
  }

  function makeUrl(base, key, value) {
    const url = new URL(base, location.href);
    url.searchParams.set(key, String(value));
    return url.toString();
  }

  function render(data) {
    if (!data?.available || document.querySelector('.warextAiThreadSummary')) return;
    const first = document.querySelector('.message[data-content^="post-"]');
    if (!first?.parentNode) return;

    const box = document.createElement('section');
    box.className = 'warextAiThreadSummary block';
    box.style.cssText = 'margin-bottom:12px;padding:12px 14px;border:1px solid rgba(127,127,127,.25);border-radius:10px;background:rgba(127,127,127,.06)';

    const title = document.createElement('strong');
    title.textContent = 'Konu AI Denetim Özeti';
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
      const span = document.createElement('span'); span.textContent = part; meta.appendChild(span);
    }

    box.append(title, meta);
    if (data.canDetailed && Array.isArray(data.top) && data.top.length) {
      const top = document.createElement('div');
      top.style.cssText = 'margin-top:8px;font-size:12px;opacity:.85';
      top.textContent = 'En yüksek riskli mesajlar: ' + data.top.map(item => `#${item.post_id} (${item.risk_score}/100)`).join(' · ');
      box.appendChild(top);
    }

    const note = document.createElement('div');
    note.style.cssText = 'margin-top:8px;font-size:11px;opacity:.7';
    note.textContent = 'Bu özet moderasyon desteğidir; tek başına AI kullanımı kanıtı değildir.';
    box.appendChild(note);
    first.parentNode.insertBefore(box, first);
  }

  async function loadFromBatch(batch) {
    const threadId = Number(batch?.reports?.[0]?.threadId || 0);
    if (!threadId) return;
    render(await json(makeUrl(config.threadEndpoint, 'thread_id', threadId)));
  }

  function boot() {
    if (window.__warextAiBatchData) {
      loadFromBatch(window.__warextAiBatchData);
      return;
    }

    const handler = event => loadFromBatch(event.detail || {});
    window.addEventListener('warext-ai-batch-ready', handler, {once:true});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true}); else boot();
})();
