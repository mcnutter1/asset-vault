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
  initNavToggle();
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', initDOM);
} else {
  initDOM();
}
