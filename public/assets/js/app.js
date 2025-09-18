// Minimal helpers
function qs(sel, el){ return (el||document).querySelector(sel); }
function qsa(sel, el){ return Array.from((el||document).querySelectorAll(sel)); }

// Mobile camera tip: use <input type="file" accept="image/*" capture="environment" multiple>

// Simple toast
function toast(msg){
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);background:#1f2937;color:#fff;padding:10px 14px;border-radius:10px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,0.2)';
  document.body.appendChild(t);
  setTimeout(()=>t.remove(), 2500);
}

// Simple confirm modal
function confirmAction(msg){
  return window.confirm(msg);
}

// Lightweight line chart for trends (no deps)
function drawLineChart(canvas, series, opts){
  // series: [{x:Date|number, y:number}, ...] sorted by x
  const ctx = canvas.getContext('2d');
  const w = canvas.width = canvas.clientWidth;
  const h = canvas.height = canvas.clientHeight;
  ctx.clearRect(0,0,w,h);
  if (!series || !series.length) return;
  const xs = series.map(p=> +new Date(p.x));
  const ys = series.map(p=> p.y);
  const minX = Math.min(...xs), maxX = Math.max(...xs);
  const minY = Math.min(...ys), maxY = Math.max(...ys);
  const pad = 20;
  function sx(x){ return pad + (w - pad*2) * ((+new Date(x) - minX) / Math.max(1, (maxX - minX))); }
  function sy(y){ return h - pad - (h - pad*2) * ((y - minY) / Math.max(1, (maxY - minY))); }
  ctx.lineWidth = 2; ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary');
  ctx.beginPath();
  series.forEach((p,i)=>{ const X = sx(p.x), Y = sy(p.y); i? ctx.lineTo(X,Y): ctx.moveTo(X,Y); });
  ctx.stroke();
}

window.addEventListener('resize', ()=>{
  qsa('canvas[data-autodraw]').forEach(c=>{
    const json = c.getAttribute('data-series');
    if (json) drawLineChart(c, JSON.parse(json));
  });
});

function initNavToggle(){
  const toggle = qs('[data-nav-toggle]');
  const wrap = qs('#nav-wrap');
  if (!(toggle && wrap)) return;
  if (wrap.dataset.inited === '1') return; // idempotent
  wrap.dataset.inited = '1';
  let lastClick = 0;
  toggle.addEventListener('click', (e)=>{
    e.stopPropagation();
    const now = Date.now();
    if (now - lastClick < 250) return; // debounce double-fire on touch
    lastClick = now;
    const open = wrap.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  // Close on outside click (mobile)
  document.addEventListener('click', (e)=>{
    if (!wrap.classList.contains('open')) return;
    const within = wrap.contains(e.target) || toggle.contains(e.target);
    if (!within) { wrap.classList.remove('open'); toggle.setAttribute('aria-expanded','false'); }
  });
  // Prevent taps inside the menu from closing via outside handler on some browsers
  wrap.addEventListener('click', (e)=>{ e.stopPropagation(); });
  // Close when clicking a nav link
  qsa('.nav a', wrap).forEach(a=>{
    a.addEventListener('click', ()=>{
      wrap.classList.remove('open');
      toggle.setAttribute('aria-expanded','false');
    });
  });
}

function initDOM(){
  qsa('canvas[data-autodraw]').forEach(c=>{
    const json = c.getAttribute('data-series');
    if (json) drawLineChart(c, JSON.parse(json));
  });
  qsa('[data-modal-open]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-modal-open');
      const el = document.getElementById(id);
      if (el) el.classList.add('show');
    });
  });
  qsa('[data-modal-close]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-modal-close');
      const el = id ? document.getElementById(id) : btn.closest('.modal-backdrop');
      if (el) el.classList.remove('show');
    });
  });
  initImagePreviewModal();
  initFileActions();
  initPhotoModal();
  initDocModal();
  initPersonPhotoModal();
  initPersonViewTabs();
  initPersonIdSlots();
  initPolicyCoverageFilter();
  initAssetsFilter();
  initNavToggle();
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', initDOM);
} else {
  initDOM();
}

// Filter coverage list by selected policy on the person edit page
function initPolicyCoverageFilter(){
  const pol = document.getElementById('link_policy_id');
  const cov = document.getElementById('link_policy_cov');
  if (!(pol && cov)) return;
  function refresh(){
    const pid = pol.value;
    Array.from(cov.options).forEach(opt=>{
      if (!opt.getAttribute('data-policy')) return; // keep the first generic option
      opt.hidden = pid && opt.getAttribute('data-policy') !== pid;
    });
    // Reset selection if current not visible
    const sel = cov.querySelector('option:checked');
    if (sel && sel.hidden){ cov.value=''; }
  }
  pol.addEventListener('change', refresh);
  refresh();
}

// Photo upload modal logic: dropzone + overlaid input, progress bars, iPhone-friendly
function initPhotoModal(){
  const modal = qs('#photoModal');
  if (!modal) return;
  const drop = qs('#pm_drop', modal);
  const input = qs('#pm_input', modal);
  const list = qs('#pm_list', modal);
  const errBox = qs('#pm_error', modal);
  const upBtn = qs('#pm_upload', modal);
  const status = qs('#pm_status', modal);

  let queue = [];

  function addFiles(fileList){
    const files = Array.from(fileList || []);
    files.forEach(f=>{
      if (!f.type || !/^image\//i.test(f.type)) return;
      queue.push({ file:f, id: Math.random().toString(36).slice(2), prog:0, error:'', done:false });
    });
    renderQueue();
  }
  function renderQueue(){
    list.innerHTML = '';
    queue.forEach(item=>{
      const row = document.createElement('div'); row.className='upl-row' + (item.done ? ' done' : '');
      const left = document.createElement('div'); left.className='upl-left';
      const icon = document.createElement('div'); icon.className='upl-icon'; icon.textContent='🖼️';
      const meta = document.createElement('div'); meta.className='upl-meta';
      const name = document.createElement('div'); name.className='upl-name'; name.textContent = item.file.name;
      const size = document.createElement('div'); size.className='upl-size'; size.textContent = prettySize(item.file.size);
      meta.appendChild(name); meta.appendChild(size);
      left.appendChild(icon); left.appendChild(meta);
      const close = document.createElement('button'); close.type='button'; close.className='upl-x'; close.textContent='✕';
      close.onclick=()=>{ queue = queue.filter(q=>q.id!==item.id); renderQueue(); };
      const bar = document.createElement('div'); bar.className='upl-bar';
      const fill = document.createElement('div'); fill.className='upl-fill'; fill.style.width = (item.prog||0)+'%';
      bar.appendChild(fill);
      const err = document.createElement('div'); err.className='upl-err'; if (item.error){ err.textContent=item.error; }
      row.appendChild(left); row.appendChild(close); row.appendChild(bar); row.appendChild(err);
      list.appendChild(row);
    });
    upBtn.disabled = queue.length===0;
  }
  function setError(msg){ if (msg){ errBox.textContent = msg; errBox.style.display='block'; } else { errBox.textContent=''; errBox.style.display='none'; } }
  function setStatus(msg){ status.textContent = msg||''; status.style.display = msg? 'block':'none'; }

  // Overlaid input covers the dropzone for iOS compatibility
  input && input.addEventListener('change', e=> addFiles(e.target.files));
  // Desktop drag & drop
  function dz(e){ e.preventDefault(); e.stopPropagation(); }
  ['dragenter','dragover','dragleave','drop'].forEach(evt=> drop && drop.addEventListener(evt, dz));
  drop && drop.addEventListener('drop', e=>{ addFiles(e.dataTransfer.files); });

  // Upload using XHR to get progress
  upBtn && upBtn.addEventListener('click', async ()=>{
    if (!queue.length) return;
    setError('');
    let done = 0;
    for (const item of queue) {
      await new Promise((resolve)=>{
        const xhr = new XMLHttpRequest();
        var baseEl = document.querySelector('base');
        var baseHref = baseEl ? baseEl.href : '';
        xhr.open('POST', baseHref + 'upload_asset_photo.php');
        xhr.responseType = 'json';
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
        xhr.onload = function(){
          var ct = xhr.getResponseHeader('Content-Type') || '';
          var json = xhr.response && typeof xhr.response === 'object' ? xhr.response : null;
          if (!json && ct.indexOf('application/json') === -1 && xhr.responseText) {
            try { json = JSON.parse(xhr.responseText); } catch(e) { json = null; }
          }
          if (xhr.status === 413) {
            item.error = 'File too large for server limits. Please reduce size or increase upload_max_filesize / client_max_body_size.';
            renderQueue(); return resolve();
          }
          if (xhr.status === 401 || xhr.status === 403) {
            item.error = 'Session expired or not authorized. Please reload and sign in again.';
            renderQueue(); return resolve();
          }
          if (xhr.status >= 200 && xhr.status < 300 && json && json.ok) {
            // Append to gallery
            const files = json.files || [];
            const gal = document.getElementById('photoGallery');
            const empty = document.getElementById('photoEmpty');
            if (empty) empty.style.display='none';
            if (gal && gal.style.display==='none') gal.style.display='grid';
            files.forEach(f=>{
              if (gal) {
                const wrap = document.createElement('div'); wrap.className='thumb'; wrap.setAttribute('data-file-wrap','');
                const del = document.createElement('button'); del.className='thumb-trash'; del.type='button'; del.title='Move to Trash'; del.setAttribute('data-file-trash',''); del.setAttribute('data-file-id', f.id);
                del.textContent = '🗑️';
                const im = document.createElement('img'); im.setAttribute('data-file-id', f.id); im.setAttribute('data-filename', f.filename||''); im.setAttribute('data-size', f.size||''); im.setAttribute('data-uploaded',''); im.src = f.url; im.alt = f.filename||'';
                wrap.appendChild(del); wrap.appendChild(im); gal.appendChild(wrap);
              }
            });
            item.prog = 100; item.done = true; item.error=''; renderQueue();
            resolve();
          } else {
            const err = (json && json.error) ? json.error : ('Upload failed (HTTP '+xhr.status+')');
            item.error = err; renderQueue();
            resolve();
          }
        };
        xhr.onerror = function(){ setError('Network error during upload.'); resolve(); };
        xhr.upload.onprogress = function(e){ if (e.lengthComputable){ item.prog = Math.round((e.loaded/e.total)*100); renderQueue(); } };
        const form = new FormData();
        var csrfEl = document.querySelector('input[name="csrf"]');
        form.append('csrf', csrfEl ? csrfEl.value : '');
        var id = null; try { id = new URLSearchParams(location.search).get('id'); } catch(e) { id = null; }
        form.append('asset_id', id || '');
        form.append('photo', item.file, item.file.name);
        xhr.send(form);
      });
      done++;
      setStatus('Uploaded '+done+' of '+queue.length);
    }
    setStatus('All uploads processed.');
    upBtn.disabled = true;
    toast('Photos uploaded');
  });
}

// Document upload modal (policy docs)
function initDocModal(){
  const modal = qs('#docModal');
  if (!modal) return;
  const drop = qs('#dm_drop', modal);
  const input = qs('#dm_input', modal);
  const list = qs('#dm_list', modal);
  const errBox = qs('#dm_error', modal);
  const upBtn = qs('#dm_upload', modal);
  const status = qs('#dm_status', modal);

  let queue = [];
  function add(files){ Array.from(files||[]).forEach(f=>{ queue.push({file:f, id:Math.random().toString(36).slice(2), prog:0, error:'', done:false}); }); render(); }
  function render(){
    list.innerHTML='';
    queue.forEach(it=>{
      const row=document.createElement('div'); row.className='upl-row'+(it.done?' done':'');
      const left=document.createElement('div'); left.className='upl-left';
      const icon=document.createElement('div'); icon.className='upl-icon'; icon.textContent='📄';
      const meta=document.createElement('div'); meta.className='upl-meta';
      const name=document.createElement('div'); name.className='upl-name'; name.textContent=it.file.name;
      const size=document.createElement('div'); size.className='upl-size'; size.textContent=prettySize(it.file.size);
      meta.appendChild(name); meta.appendChild(size); left.appendChild(icon); left.appendChild(meta);
      const x=document.createElement('button'); x.type='button'; x.className='upl-x'; x.textContent='✕'; x.onclick=()=>{ queue=queue.filter(q=>q.id!==it.id); render(); };
      const bar=document.createElement('div'); bar.className='upl-bar'; const fill=document.createElement('div'); fill.className='upl-fill'; fill.style.width=(it.prog||0)+'%'; bar.appendChild(fill);
      const err=document.createElement('div'); err.className='upl-err'; if (it.error) err.textContent=it.error;
      row.appendChild(left); row.appendChild(x); row.appendChild(bar); row.appendChild(err); list.appendChild(row);
    });
    upBtn.disabled = queue.length===0;
  }
  function setError(msg){ if(msg){ errBox.textContent=msg; errBox.style.display='block'; } else { errBox.textContent=''; errBox.style.display='none'; } }
  function setStatus(msg){ status.textContent=msg||''; status.style.display=msg?'block':'none'; }

  input && input.addEventListener('change', e=> add(e.target.files));
  function prevent(e){ e.preventDefault(); e.stopPropagation(); }
  ['dragenter','dragover','dragleave','drop'].forEach(ev=> drop && drop.addEventListener(ev, prevent));
  drop && drop.addEventListener('drop', e=> add(e.dataTransfer.files));

  upBtn && upBtn.addEventListener('click', async ()=>{
    if (!queue.length) return; setError(''); let done=0; const base = (document.querySelector('base')? document.querySelector('base').href : '');
    for (const it of queue){
      await new Promise((resolve)=>{
        const xhr=new XMLHttpRequest(); xhr.open('POST', base+'upload_policy_file.php'); xhr.responseType='json'; xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
        xhr.onload=function(){
          let json = xhr.response && typeof xhr.response==='object' ? xhr.response : null;
          if (!json && xhr.responseText){ try{ json=JSON.parse(xhr.responseText); }catch(e){} }
          if (xhr.status>=200 && xhr.status<300 && json && json.ok){
            // Append to docs table
            const tbl=document.querySelector('#docsTable tbody');
            (json.files||[]).forEach(f=>{
              if (tbl){
                const tr=document.createElement('tr');
                tr.innerHTML = '<td><a href="'+f.url+'" target="_blank">'+(f.filename||'')+'</a></td>'+
                               '<td>'+(f.mime||'')+'</td>'+
                               '<td>'+prettySize(f.size||0)+'</td>'+
                               '<td></td>'+
                               '<td></td>';
                tbl.appendChild(tr);
              }
            });
            it.prog=100; it.done=true; render(); resolve();
          } else { it.error=(json&&json.error)?json.error:('Upload failed (HTTP '+xhr.status+')'); render(); resolve(); }
        };
        xhr.upload.onprogress=function(e){ if (e.lengthComputable){ it.prog=Math.round((e.loaded/e.total)*100); render(); } };
        xhr.onerror=function(){ it.error='Network error'; render(); resolve(); };
        const form=new FormData(); const csrf=document.querySelector('input[name="csrf"]'); form.append('csrf', csrf?csrf.value:''); form.append('policy_id', (new URLSearchParams(location.search)).get('id')||''); form.append('file', it.file, it.file.name); xhr.send(form);
      });
      done++; setStatus('Uploaded '+done+' of '+queue.length);
    }
    toast('Documents uploaded');
    upBtn.disabled=true; setStatus('All uploads processed');
    // keep modal open to review; user can close
  });
}

// Tabs for person view/edit
function initPersonViewTabs(){
  const tabs = qsa('[data-tab-link]');
  if (!tabs.length) return;
  tabs.forEach(a=>{
    a.addEventListener('click', (e)=>{
      e.preventDefault();
      const key = a.getAttribute('data-tab-link');
      qsa('[data-tab-link]').forEach(x=> x.classList.toggle('active', x===a));
      qsa('[data-tab-panel]').forEach(p=> p.classList.toggle('show', p.getAttribute('data-tab-panel')===key));
    });
  });
}

// Inline ID slots uploader for person page
function initPersonIdSlots(){
  const slots = qsa('[data-id-slot]');
  if (!slots.length) return;
  const csrfEl = document.querySelector('input[name="csrf"]');
  const csrf = csrfEl ? csrfEl.value : '';
  const baseEl = document.querySelector('base');
  const baseHref = baseEl ? baseEl.href : '';

  slots.forEach(slot=>{
    const disabled = slot.hasAttribute('data-disabled');
    const input = slot.querySelector('input[type=file]');
    const browse = slot.querySelector('.dz .link');
    if (disabled) {
      if (input) input.disabled = true;
      return;
    }
    function send(file){
      if (!file) return;
      const personId = slot.getAttribute('data-person-id');
      const caption = slot.getAttribute('data-caption') || '';
      const form = new FormData();
      form.append('csrf', csrf);
      form.append('person_id', personId);
      form.append('caption', caption);
      form.append('photo', file, file.name);
      fetch(baseHref + 'upload_person_photo.php', { method:'POST', body: form, headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r=>r.json())
        .then(j=>{
          if (!j.ok) throw new Error(j.error||'Upload failed');
          const files = j.files||[];
          if (files.length){
            const url = files[0].url;
            let img = slot.querySelector('img.id-prev');
            if (!img){ img = document.createElement('img'); img.className='id-prev'; slot.appendChild(img); }
            img.src = url; img.alt = file.name;
            toast('Uploaded');
          }
        })
        .catch(err=> toast(err.message||'Error uploading'));
    }
    if (browse){ browse.addEventListener('click', (e)=>{ e.preventDefault(); input && input.click(); }); }
    if (input){ input.addEventListener('change', e=>{ const f = e.target.files && e.target.files[0]; if (f) send(f); }); }
    ['dragenter','dragover','dragleave','drop'].forEach(evt=> slot.addEventListener(evt, (e)=>{ e.preventDefault(); e.stopPropagation(); }));
    slot.addEventListener('drop', (e)=>{ const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) send(f); });
  });

  // Files tab generic uploader
  const drop = qs('[data-files-drop]');
  if (drop && !drop.hasAttribute('data-disabled')){
    const input = drop.querySelector('input[type=file]');
    const browse = drop.querySelector('.dz .link');
    const personId = drop.getAttribute('data-person-id');
    function push(files){
      Array.from(files||[]).forEach(file=>{
        const form = new FormData();
        form.append('csrf', csrf);
        form.append('person_id', personId);
        form.append('photo', file, file.name);
        fetch(baseHref + 'upload_person_photo.php', { method:'POST', body: form, headers:{'X-Requested-With':'XMLHttpRequest'} })
          .then(r=>r.json()).then(j=>{ if(!j.ok) throw new Error(j.error||'Upload failed'); toast('Uploaded'); location.reload(); })
          .catch(err=> toast(err.message||'Error'));
      });
    }
    if (browse){ browse.addEventListener('click', (e)=>{ e.preventDefault(); input && input.click(); }); }
    if (input){ input.addEventListener('change', e=> push(e.target.files)); }
    ['dragenter','dragover','dragleave','drop'].forEach(evt=> drop.addEventListener(evt, (e)=>{ e.preventDefault(); e.stopPropagation(); }));
    drop.addEventListener('drop', e=> push(e.dataTransfer.files));
  }
}

// Auto-submit filters on the Assets list (debounced for search)
function initAssetsFilter(){
  var form = document.getElementById('assetsFilter');
  if (!form) return;
  var timer = null;
  function submitSoon(){
    if (timer) clearTimeout(timer);
    timer = setTimeout(function(){ form.submit(); }, 300);
  }
  // Change events submit immediately (selects, checkboxes)
  form.addEventListener('change', function(e){
    if (e.target && e.target.tagName !== 'INPUT') { form.submit(); return; }
    // Checkboxes submit immediately
    if (e.target && e.target.type === 'checkbox') { form.submit(); }
  });
  // Search input debounced
  var q = document.getElementById('assets_q');
  if (q) { q.addEventListener('input', submitSoon); }
}

// Global image preview modal for any element with data-file-id
function initImagePreviewModal(){
  const modal = document.getElementById('imgModal');
  if (!modal) return;
  const img = document.getElementById('im_img');
  const title = document.getElementById('im_title');
  const meta = document.getElementById('im_meta');
  document.addEventListener('click', function(e){
    // Ignore clicks on action buttons (trash/restore/delete)
    if (e.target.closest('[data-file-trash],[data-file-restore],[data-file-delete]')) return;
    // Only preview when clicking an image or a link representing a file
    const el = e.target.closest('img[data-file-id], a[data-file-id]');
    if (!el) return;
    if (el.tagName === 'A') { e.preventDefault(); }
    const id = el.getAttribute('data-file-id');
    const name = el.getAttribute('data-filename') || 'File';
    const size = el.getAttribute('data-size') || '';
    const uploaded = el.getAttribute('data-uploaded') || '';
    title.textContent = name;
    var baseEl = document.querySelector('base');
    var baseHref = baseEl ? baseEl.href : '';
    img.src = baseHref + 'file.php?id=' + encodeURIComponent(id);
    meta.textContent = (size? prettySize(parseInt(size,10))+' • ' : '') + (uploaded || '');
    modal.classList.add('show');
  });
}

// File actions: trash/restore/delete via AJAX
function initFileActions(){
  document.addEventListener('click', function(e){
    const btn = e.target.closest('[data-file-trash],[data-file-restore],[data-file-delete]');
    if (!btn) return;
    const id = btn.getAttribute('data-file-id');
    const action = btn.hasAttribute('data-file-trash') ? 'trash' : (btn.hasAttribute('data-file-restore') ? 'restore' : 'delete');
    if (action === 'delete' && !confirm('Permanently delete this file? This cannot be undone.')) return;
    if (action === 'trash' && !confirm('Move this file to Trash?')) return;
    const form = new FormData();
    var csrfEl = document.querySelector('input[name="csrf"]');
    form.append('csrf', csrfEl ? csrfEl.value : '');
    form.append('file_id', id);
    form.append('action', action);
    var baseEl = document.querySelector('base');
    var baseHref = baseEl ? baseEl.href : '';
    fetch(baseHref + 'file_action.php', { method:'POST', body: form, headers:{'X-Requested-With':'XMLHttpRequest'} })
      .then(r=>r.json()).then(j=>{
        if (!j.ok) throw new Error(j.error||'Action failed');
        // Remove thumbnail if present
        const wrap = btn.closest('[data-file-wrap]');
        if (wrap) wrap.remove();
        toast('Done');
      }).catch(err=>{
        toast(err.message||'Error');
      });
  });
}

function prettySize(bytes){
  const units=['B','KB','MB','GB']; let i=0; let n=bytes;
  while(n>=1024 && i<units.length-1){ n/=1024; i++; }
  return (Math.round(n*10)/10)+' '+units[i];
}

// Person photos uploader (modal)
function initPersonPhotoModal(){
  const modal = qs('#personPhotoModal');
  if (!modal) return;
  const drop = qs('#pp_drop', modal);
  const input = qs('#pp_input', modal);
  const list = qs('#pp_list', modal);
  const errBox = qs('#pp_error', modal);
  const upBtn = qs('#pp_upload', modal);
  const status = qs('#pp_status', modal);
  let queue = [];
  function add(files){ Array.from(files||[]).forEach(f=>{ if (!/^image\//i.test(f.type)) return; queue.push({file:f,id:Math.random().toString(36).slice(2),prog:0,error:''}); }); render(); }
  function render(){ if(!list) return; list.innerHTML=''; queue.forEach(it=>{ const row=document.createElement('div'); row.className='upl-row'; const left=document.createElement('div'); left.className='upl-left'; const icon=document.createElement('div'); icon.className='upl-icon'; icon.textContent='🖼️'; const meta=document.createElement('div'); meta.className='upl-meta'; const name=document.createElement('div'); name.className='upl-name'; name.textContent=it.file.name; const size=document.createElement('div'); size.className='upl-size'; size.textContent=prettySize(it.file.size); meta.appendChild(name); meta.appendChild(size); left.appendChild(icon); left.appendChild(meta); const x=document.createElement('button'); x.type='button'; x.className='upl-x'; x.textContent='✕'; x.onclick=()=>{ queue=queue.filter(q=>q.id!==it.id); render(); }; const bar=document.createElement('div'); bar.className='upl-bar'; const fill=document.createElement('div'); fill.className='upl-fill'; fill.style.width=(it.prog||0)+'%'; bar.appendChild(fill); const err=document.createElement('div'); err.className='upl-err'; if (it.error) err.textContent=it.error; row.appendChild(left); row.appendChild(x); row.appendChild(bar); row.appendChild(err); list.appendChild(row); }); if (upBtn) upBtn.disabled = queue.length===0; }
  function setError(msg){ if(!errBox) return; if (msg){ errBox.textContent=msg; errBox.style.display='block'; } else { errBox.textContent=''; errBox.style.display='none'; } }
  function setStatus(msg){ if(!status) return; status.textContent=msg||''; status.style.display=msg?'block':'none'; }
  function prevent(e){ e.preventDefault(); e.stopPropagation(); }
  ['dragenter','dragover','dragleave','drop'].forEach(ev=> drop && drop.addEventListener(ev, prevent));
  drop && drop.addEventListener('drop', e=> add(e.dataTransfer.files));
  input && input.addEventListener('change', e=> add(e.target.files));
  upBtn && upBtn.addEventListener('click', async ()=>{
    if (!queue.length) return; setError(''); let done=0;
    for (const it of queue){ await new Promise((resolve)=>{ const xhr=new XMLHttpRequest(); var baseEl=document.querySelector('base'); var baseHref=baseEl?baseEl.href:''; xhr.open('POST', baseHref+'upload_person_photo.php'); xhr.responseType='json'; xhr.setRequestHeader('X-Requested-With','XMLHttpRequest'); xhr.onload=function(){ var json=xhr.response && typeof xhr.response==='object' ? xhr.response : null; if (xhr.status>=200 && xhr.status<300 && json && json.ok){ const gal=document.getElementById('personPhotoGallery'); const empty=document.getElementById('personPhotoEmpty'); if (empty) empty.style.display='none'; if (gal && gal.style.display==='none') gal.style.display='grid'; (json.files||[]).forEach(f=>{ if (gal){ const im=document.createElement('img'); im.src=f.url; im.alt=f.filename||''; gal.appendChild(im); } }); it.prog=100; it.error=''; render(); resolve(); } else { const err=(json && json.error)?json.error:('Upload failed (HTTP '+xhr.status+')'); it.error=err; render(); resolve(); } }; xhr.onerror=function(){ it.error='Network error'; render(); resolve(); }; xhr.upload.onprogress=function(e){ if (e.lengthComputable){ it.prog=Math.round((e.loaded/e.total)*100); render(); } }; const form=new FormData(); var csrf=document.querySelector('input[name="csrf"]'); form.append('csrf', csrf?csrf.value:''); var pid=null; try{ pid=new URLSearchParams(location.search).get('id'); }catch(e){} form.append('person_id', pid||''); form.append('photo', it.file, it.file.name); xhr.send(form); }); done++; setStatus('Uploaded '+done+' of '+queue.length); }
    toast('Photos uploaded'); if (upBtn) upBtn.disabled=true; setStatus('All uploads processed');
  });
}
