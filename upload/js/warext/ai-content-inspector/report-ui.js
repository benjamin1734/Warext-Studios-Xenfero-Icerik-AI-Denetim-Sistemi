(() => {
  'use strict';
  if (window.__warextAiReportUi) return;
  window.__warextAiReportUi = true;

  const config = document.getElementById('warext-ai-config')?.dataset || {};
  if (!config.batchEndpoint) return;

  const labels = {
    human_likely: 'İnsan yazımı ağırlıklı',
    low_ai_signal: 'Düşük AI sinyali',
    ai_assistance_possible: 'AI desteği olabilir',
    ai_heavy_possible: 'AI ağırlıklı olabilir',
    high_risk: 'Yüksek AI riski',
    unknown: 'Belirsiz'
  };

  function installStyle() {
    if (document.getElementById('warext-ai-style')) return;
    const style = document.createElement('style');
    style.id = 'warext-ai-style';
    style.textContent = `
      .warextAiReport{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:8px 10px;border:1px solid rgba(127,127,127,.25);border-radius:8px;background:rgba(127,127,127,.06);font-size:12px}
      .warextAiReport strong{font-size:13px}.warextAiReport button{margin-left:auto;border:0;background:transparent;color:inherit;cursor:pointer;text-decoration:underline}
      .warextAiDialog{width:min(720px,94vw);border:1px solid rgba(127,127,127,.3);border-radius:12px;padding:0;background:Canvas;color:CanvasText}.warextAiDialog::backdrop{background:rgba(0,0,0,.42)}
      .warextAiDialogHead,.warextAiDialogBody{padding:14px 16px}.warextAiDialogHead{display:flex;justify-content:space-between;border-bottom:1px solid rgba(127,127,127,.18)}
      .warextAiGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.warextAiCell{padding:10px;border:1px solid rgba(127,127,127,.18);border-radius:8px}.warextAiSignals{margin-top:12px;font-size:12px;line-height:1.55}
      @media(max-width:650px){.warextAiGrid{grid-template-columns:1fr}.warextAiReport{align-items:flex-start;flex-wrap:wrap}}
    `;
    document.head.appendChild(style);
  }

  function postId(message) {
    const raw = String(message.dataset.content || '');
    const match = raw.match(/^post-(\d+)$/);
    return match ? Number(match[1]) : 0;
  }

  function endpoint(base, postId) {
    const url = new URL(base, location.href);
    url.searchParams.set('post_id', String(postId));
    return url.toString();
  }

  async function detail(postId) {
    const response = await fetch(endpoint(config.detailEndpoint, postId), { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
    if (!response.ok) return;
    const data = await response.json();
    openDialog(data);
  }

  function openDialog(data) {
    let dialog = document.getElementById('warext-ai-detail-dialog');
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.id = 'warext-ai-detail-dialog';
      dialog.className = 'warextAiDialog';
      document.body.appendChild(dialog);
    }
    const text = data.textMetrics || {};
    const behavior = data.behaviorMetrics || {};
    const writing = data.writingMetrics || {};
    const signals = Array.isArray(data.signals) ? data.signals : [];
    dialog.innerHTML = '';

    const head = document.createElement('div'); head.className = 'warextAiDialogHead';
    const title = document.createElement('strong'); title.textContent = `AI Denetim Raporu · #${data.postId}`;
    const close = document.createElement('button'); close.type='button'; close.textContent='Kapat'; close.addEventListener('click',()=>dialog.close());
    head.append(title,close);

    const body = document.createElement('div'); body.className='warextAiDialogBody';
    const grid = document.createElement('div'); grid.className='warextAiGrid';
    const cells = [
      ['Nihai risk', `${data.risk}/100`], ['Güven', `${data.confidence}/100`],
      ['Sonuç', labels[data.classification] || data.classification], ['İnceleme', data.reviewState || 'pending'],
      ['Kelime', String(text.words ?? '-')], ['Cümle', String(text.sentences ?? '-')],
      ['Paste karakteri', String(behavior.pastedChars ?? '-')], ['Manuel karakter', String(behavior.typedChars ?? '-')],
      ['Writing Checker', writing.available ? 'Kullanıldı' : 'Yok'], ['Düzeltme sayısı', String(writing.correctionCount ?? 0)]
    ];
    for (const [key,value] of cells) {
      const cell=document.createElement('div'); cell.className='warextAiCell';
      const k=document.createElement('small'); k.textContent=key; const v=document.createElement('div'); v.textContent=value;
      cell.append(k,v); grid.appendChild(cell);
    }
    body.appendChild(grid);
    const sig=document.createElement('div'); sig.className='warextAiSignals';
    sig.textContent = signals.length ? `Sinyaller: ${signals.map(item => item.key).join(', ')}` : 'Belirgin ek sinyal kaydedilmedi.';
    body.appendChild(sig);
    const note=document.createElement('div'); note.className='warextAiSignals'; note.textContent='Bu rapor kesin AI tespiti değildir; moderasyon için çoklu sinyal temelli risk değerlendirmesidir.';
    body.appendChild(note);
    dialog.append(head,body);
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open','');
  }

  function render(message, report, canDetailed) {
    if (message.querySelector('.warextAiReport')) return;
    const body = message.querySelector('.message-body') || message.querySelector('.message-content');
    if (!body) return;
    const box = document.createElement('div'); box.className='warextAiReport';
    const score=document.createElement('strong'); score.textContent=`AI riski: ${report.risk}/100`;
    const label=document.createElement('span'); label.textContent=labels[report.classification] || report.classification;
    const confidence=document.createElement('span'); confidence.textContent=`Güven: ${report.confidence}/100`;
    box.append(score,label,confidence);
    if (canDetailed && config.detailEndpoint) {
      const button=document.createElement('button'); button.type='button'; button.textContent='Detaylı rapor'; button.addEventListener('click',()=>detail(report.postId)); box.appendChild(button);
    }
    body.prepend(box);
  }

  async function boot() {
    const messages = Array.from(document.querySelectorAll('.message[data-content^="post-"]'));
    const ids = messages.map(postId).filter(Boolean);
    if (!ids.length) return;
    installStyle();
    const url = new URL(config.batchEndpoint, location.href); url.searchParams.set('post_ids', ids.join(','));
    const response = await fetch(url.toString(), { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
    if (!response.ok) return;
    const data = await response.json();
    const byId = new Map((data.reports || []).map(report => [Number(report.postId), report]));
    for (const message of messages) {
      const report = byId.get(postId(message));
      if (report) render(message, report, !!data.canDetailed);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true}); else boot();
})();
