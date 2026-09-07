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
  const reviewLabels = {
    pending: 'Bekleyen',
    cleared: 'Temizlendi',
    suspicious: 'Şüpheli',
    confirmed: 'Onaylandı'
  };

  function installStyle() {
    if (document.getElementById('warext-ai-style')) return;
    const style = document.createElement('style');
    style.id = 'warext-ai-style';
    style.textContent = `
      .warextAiReport{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:8px 10px;border:1px solid rgba(127,127,127,.25);border-radius:8px;background:rgba(127,127,127,.06);font-size:12px}
      .warextAiReport strong{font-size:13px}.warextAiReport button{margin-left:auto;border:0;background:transparent;color:inherit;cursor:pointer;text-decoration:underline}
      .warextAiDialog{width:min(780px,94vw);max-height:88vh;overflow:auto;border:1px solid rgba(127,127,127,.3);border-radius:12px;padding:0;background:Canvas;color:CanvasText}.warextAiDialog::backdrop{background:rgba(0,0,0,.42)}
      .warextAiDialogHead,.warextAiDialogBody{padding:14px 16px}.warextAiDialogHead{display:flex;justify-content:space-between;border-bottom:1px solid rgba(127,127,127,.18)}
      .warextAiGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.warextAiCell{padding:10px;border:1px solid rgba(127,127,127,.18);border-radius:8px}.warextAiSignals{margin-top:12px;font-size:12px;line-height:1.55}
      .warextAiSection{margin-top:14px;padding-top:12px;border-top:1px solid rgba(127,127,127,.18)}.warextAiSection h4{margin:0 0 8px;font-size:13px}.warextAiHistory{display:flex;flex-direction:column;gap:6px}.warextAiHistoryItem{padding:8px;border-radius:7px;background:rgba(127,127,127,.06);font-size:12px}
      .warextAiReviewForm{display:grid;grid-template-columns:160px 1fr auto;gap:8px}.warextAiReviewForm select,.warextAiReviewForm input,.warextAiReviewForm button{min-height:34px;border:1px solid rgba(127,127,127,.3);border-radius:7px;padding:6px 8px;background:Canvas;color:CanvasText}.warextAiReviewStatus{margin-top:7px;font-size:12px}
      @media(max-width:650px){.warextAiGrid{grid-template-columns:1fr}.warextAiReport{align-items:flex-start;flex-wrap:wrap}.warextAiReviewForm{grid-template-columns:1fr}.warextAiReport button{margin-left:0}}
    `;
    document.head.appendChild(style);
  }

  function postId(message) {
    const raw = String(message.dataset.content || '');
    const match = raw.match(/^post-(\d+)$/);
    return match ? Number(match[1]) : 0;
  }

  function endpoint(base, id) {
    const url = new URL(base, location.href);
    url.searchParams.set('post_id', String(id));
    return url.toString();
  }

  function csrfToken() {
    return document.querySelector('input[name="_xfToken"]')?.value || window.XF?.config?.csrf || '';
  }

  async function detail(id) {
    const response = await fetch(endpoint(config.detailEndpoint, id), { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
    if (!response.ok) return;
    const data = await response.json();
    openDialog(data);
  }

  function cell(key, value) {
    const item = document.createElement('div'); item.className='warextAiCell';
    const k=document.createElement('small'); k.textContent=key;
    const v=document.createElement('div'); v.textContent=value;
    item.append(k,v);
    return item;
  }

  function addSection(body, title) {
    const section=document.createElement('div'); section.className='warextAiSection';
    const heading=document.createElement('h4'); heading.textContent=title; section.appendChild(heading); body.appendChild(section);
    return section;
  }

  function formatPercent(value) {
    const n=Number(value);
    return Number.isFinite(n) ? `${Math.round(n*100)}%` : '-';
  }

  async function submitReview(data, state, note, statusEl, button) {
    if (!config.reviewEndpoint) return;
    const body=new URLSearchParams();
    body.set('post_id', String(data.postId));
    body.set('state', state);
    body.set('note', note);
    const token=csrfToken(); if (token) body.set('_xfToken', token);
    button.disabled=true; statusEl.textContent='Kaydediliyor…';
    try {
      const response=await fetch(config.reviewEndpoint, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()});
      if (!response.ok) throw new Error('review_failed');
      const result=await response.json();
      data.reviewState=result.state || state;
      statusEl.textContent=`Kaydedildi: ${reviewLabels[data.reviewState] || data.reviewState}`;
      const refreshed=await fetch(endpoint(config.detailEndpoint, data.postId), {credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
      if (refreshed.ok) openDialog(await refreshed.json());
    } catch (_) {
      statusEl.textContent='İnceleme durumu kaydedilemedi.';
    } finally {
      button.disabled=false;
    }
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
    const profile = data.profileMetrics || {};
    const signals = Array.isArray(data.signals) ? data.signals : [];
    const history = Array.isArray(data.reviewHistory) ? data.reviewHistory : [];
    dialog.innerHTML = '';

    const head = document.createElement('div'); head.className = 'warextAiDialogHead';
    const title = document.createElement('strong'); title.textContent = `AI Denetim Raporu · #${data.postId}`;
    const close = document.createElement('button'); close.type='button'; close.textContent='Kapat'; close.addEventListener('click',()=>dialog.close());
    head.append(title,close);

    const body = document.createElement('div'); body.className='warextAiDialogBody';
    const grid = document.createElement('div'); grid.className='warextAiGrid';
    const values = [
      ['Nihai risk', `${data.risk}/100`], ['Güven', `${data.confidence}/100`],
      ['Sonuç', labels[data.classification] || data.classification], ['İnceleme', reviewLabels[data.reviewState] || data.reviewState || 'Bekleyen'],
      ['Kelime', String(text.words ?? '-')], ['Cümle', String(text.sentences ?? '-')],
      ['Paste karakteri', String(behavior.pastedChars ?? '-')], ['Manuel karakter', String(behavior.typedChars ?? '-')],
      ['Writing Checker', writing.available ? 'Kullanıldı' : 'Yok'], ['Düzeltme sayısı', String(writing.correctionCount ?? 0)]
    ];
    for (const [key,value] of values) grid.appendChild(cell(key,value));
    body.appendChild(grid);

    if (profile.available) {
      const section=addSection(body,'Kullanıcı yazım profili');
      const profileGrid=document.createElement('div'); profileGrid.className='warextAiGrid';
      profileGrid.appendChild(cell('Geçmiş örnek', String(profile.sample_count ?? 0)));
      profileGrid.appendChild(cell('Profil sapması', `${profile.deviation_score ?? 0}/100`));
      profileGrid.appendChild(cell('Riske etkisi', `${Number(profile.risk_adjustment || 0) >= 0 ? '+' : ''}${profile.risk_adjustment ?? 0}`));
      profileGrid.appendChild(cell('Cümle düzeni', `${formatPercent(profile.current?.sentence_uniformity)} / geçmiş ${formatPercent(profile.baseline?.sentence_uniformity)}`));
      section.appendChild(profileGrid);
      if (profile.note) { const note=document.createElement('div'); note.className='warextAiSignals'; note.textContent=profile.note; section.appendChild(note); }
    }

    const signalSection=addSection(body,'Sinyaller');
    const sig=document.createElement('div'); sig.className='warextAiSignals';
    sig.textContent = signals.length ? signals.map(item => `${item.key}: ${item.value}`).join(' · ') : 'Belirgin ek sinyal kaydedilmedi.';
    signalSection.appendChild(sig);

    if (data.canReview && config.reviewEndpoint) {
      const reviewSection=addSection(body,'Moderasyon incelemesi');
      const form=document.createElement('div'); form.className='warextAiReviewForm';
      const select=document.createElement('select');
      for (const state of ['pending','cleared','suspicious','confirmed']) {
        const option=document.createElement('option'); option.value=state; option.textContent=reviewLabels[state]; option.selected=state===data.reviewState; select.appendChild(option);
      }
      const note=document.createElement('input'); note.type='text'; note.maxLength=500; note.placeholder='İnceleme notu (isteğe bağlı)';
      const save=document.createElement('button'); save.type='button'; save.textContent='Kaydet';
      const status=document.createElement('div'); status.className='warextAiReviewStatus'; status.textContent='Her değişiklik inceleme geçmişine kaydedilir.';
      save.addEventListener('click',()=>submitReview(data,select.value,note.value.trim(),status,save));
      form.append(select,note,save); reviewSection.append(form,status);
    }

    if (history.length) {
      const historySection=addSection(body,'İnceleme geçmişi');
      const list=document.createElement('div'); list.className='warextAiHistory';
      for (const item of history) {
        const row=document.createElement('div'); row.className='warextAiHistoryItem';
        const who=item.username || `#${item.reviewer_user_id || 0}`;
        const when=item.created_date ? new Date(Number(item.created_date)*1000).toLocaleString() : '';
        row.textContent=`${who}: ${reviewLabels[item.from_state] || item.from_state} → ${reviewLabels[item.to_state] || item.to_state}${item.note ? ` · ${item.note}` : ''}${when ? ` · ${when}` : ''}`;
        list.appendChild(row);
      }
      historySection.appendChild(list);
    }

    const disclaimer=document.createElement('div'); disclaimer.className='warextAiSignals'; disclaimer.textContent='Bu rapor kesin AI tespiti değildir; metin, editör davranışı, Writing Checker ve kullanıcı geçmişi sinyallerini birlikte değerlendiren moderasyon destek raporudur.';
    body.appendChild(disclaimer);
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
    const state=document.createElement('span'); state.textContent=reviewLabels[report.reviewState] || report.reviewState || 'Bekleyen';
    box.append(score,label,confidence,state);
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

  installStyle();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true}); else boot();
})();
