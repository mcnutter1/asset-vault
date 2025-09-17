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

// Photo upload modal logic (camera/library/files with previews and progress)
function initPhotoModal(){
  const modal = qs('#photoModal');
  if (!modal) return;
  const takeBtn = qs('#pm_take', modal);
  const libBtn = qs('#pm_library', modal);
  const filesBtn = qs('#pm_files', modal);
  const inCam = qs('#pm_input_camera', modal);
  const inLib = qs('#pm_input_library', modal);
  const inFiles = qs('#pm_input_files', modal);
  const qWrap = qs('#pm_queue', modal);
  const errBox = qs('#pm_error', modal);
  const upBtn = qs('#pm_upload', modal);
  const status = qs('#pm_status', modal);

  let queue = [];

  function openPicker(input){ input.value=''; input.click(); }
  function addFiles(fileList){
    const files = Array.from(fileList || []);
    files.forEach(f=>{
      if (!f.type || !/^image\//i.test(f.type)) return;
      queue.push({ file:f, id: Math.random().toString(36).slice(2), prog:0 });
    });
    renderQueue();
  }
  function renderQueue(){
    qWrap.innerHTML = '';
    queue.forEach(item=>{
      const d = document.createElement('div');
      d.style.position='relative';
      const img = document.createElement('img');
      img.alt = item.file.name;
      img.style.width='100%'; img.style.height='90px'; img.style.objectFit='cover'; img.style.borderRadius='8px';
      const rm = document.createElement('button');
      rm.type='button'; rm.textContent='✕'; rm.className='btn sm ghost';
      rm.style.cssText='position:absolute;top:6px;right:6px;padding:2px 6px;background:#fff;border:1px solid var(--border);border-radius:999px;font-weight:700;line-height:1;';
      rm.onclick=()=>{ queue = queue.filter(q=>q.id!==item.id); renderQueue(); };
      const bar = document.createElement('div');
      bar.style.cssText='position:absolute;left:0;right:0;bottom:0;height:4px;background:#e5e7eb;border-radius:0 0 8px 8px;overflow:hidden;';
      const fill = document.createElement('div');
      fill.style.cssText='height:100%;width:'+item.prog+'%;background:var(--primary);transition:width .2s';
      bar.appendChild(fill);
      d.appendChild(img); d.appendChild(rm); d.appendChild(bar);
      qWrap.appendChild(d);
      // thumbnail
      const reader = new FileReader(); reader.onload = e=>{ img.src = e.target.result; }; reader.readAsDataURL(item.file);
    });
    upBtn.disabled = queue.length===0;
  }
  function setError(msg){ if (msg){ errBox.textContent = msg; errBox.style.display='block'; } else { errBox.textContent=''; errBox.style.display='none'; } }
  function setStatus(msg){ status.textContent = msg||''; status.style.display = msg? 'block':'none'; }

  takeBtn && takeBtn.addEventListener('click', ()=> openPicker(inCam));
  libBtn && libBtn.addEventListener('click', ()=> openPicker(inLib));
  filesBtn && filesBtn.addEventListener('click', ()=> openPicker(inFiles));
  inCam && inCam.addEventListener('change', e=> addFiles(e.target.files));
  inLib && inLib.addEventListener('change', e=> addFiles(e.target.files));
  inFiles && inFiles.addEventListener('change', e=> addFiles(e.target.files));

  // Upload using XHR to get progress
  upBtn && upBtn.addEventListener('click', async ()=>{
    if (!queue.length) return;
    setError('');
    let done = 0;
    for (const item of queue) {
      await new Promise((resolve)=>{
        const xhr = new XMLHttpRequest();
        xhr.open('POST', (document.querySelector('base')?.href||'') + 'upload_asset_photo.php');
        xhr.responseType = 'json';
        xhr.onload = function(){
          if (xhr.status >= 200 && xhr.status < 300 && xhr.response && xhr.response.ok) {
            // Append to gallery
            const files = xhr.response.files || [];
            const gal = document.getElementById('photoGallery');
            const empty = document.getElementById('photoEmpty');
            if (empty) empty.style.display='none';
            if (gal && gal.style.display==='none') gal.style.display='grid';
            files.forEach(f=>{
              if (gal) { const im = document.createElement('img'); im.src = f.url; im.alt = f.filename; gal.appendChild(im); }
            });
            resolve();
          } else {
            const err = (xhr.response && xhr.response.error) ? xhr.response.error : 'Upload failed';
            setError(err);
            resolve();
          }
        };
        xhr.onerror = function(){ setError('Network error during upload.'); resolve(); };
        xhr.upload.onprogress = function(e){ if (e.lengthComputable){ item.prog = Math.round((e.loaded/e.total)*100); renderQueue(); } };
        const form = new FormData();
        form.append('csrf', document.querySelector('input[name="csrf"]')?.value || '');
        form.append('asset_id', (new URLSearchParams(location.search)).get('id') || '');
        form.append('photo', item.file, item.file.name);
        xhr.send(form);
      });
      done++;
      setStatus('Uploaded '+done+' of '+queue.length);
    }
    queue = []; renderQueue(); setStatus('');
    // Close modal
    modal.classList.remove('show');
    toast('Photos uploaded');
  });
}
