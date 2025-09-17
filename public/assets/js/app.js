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
  initPhotoModal();
  initNavToggle();
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', initDOM);
} else {
  initDOM();
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
              if (gal) { const im = document.createElement('img'); im.src = f.url; im.alt = f.filename; gal.appendChild(im); }
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

function prettySize(bytes){
  const units=['B','KB','MB','GB']; let i=0; let n=bytes;
  while(n>=1024 && i<units.length-1){ n/=1024; i++; }
  return (Math.round(n*10)/10)+' '+units[i];
}
