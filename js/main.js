/* =====================================================
   EduVault – main.js   (No frameworks, pure JS)
   ===================================================== */

/* ---- HAMBURGER NAV ---- */
(function () {
  var btn = document.getElementById('hamburger');
  var links = document.getElementById('navLinks');
  if (!btn || !links) return;
  btn.addEventListener('click', function () {
    var open = links.style.display === 'flex';
    links.style.display = open ? '' : 'flex';
    links.style.flexDirection = 'column';
    links.style.position = 'absolute';
    links.style.top = '70px';
    links.style.right = '1rem';
    links.style.background = '#0b0e1a';
    links.style.border = '1px solid rgba(255,255,255,0.08)';
    links.style.borderRadius = '12px';
    links.style.padding = '1rem';
    links.style.gap = '0.8rem';
    links.style.zIndex = '999';
  });
})();

/* ---- COUNTER ANIMATION ---- */
(function () {
  var counters = document.querySelectorAll('.stat-n[data-target]');
  if (!counters.length) return;

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var el = entry.target;
      var target = parseFloat(el.dataset.target);
      var decimals = parseInt(el.dataset.decimal) || 0;
      var duration = 1400;
      var start = null;

      function ease(t) { return 1 - Math.pow(1 - t, 3); }

      function tick(ts) {
        if (!start) start = ts;
        var progress = Math.min((ts - start) / duration, 1);
        var val = target * ease(progress);
        el.textContent = decimals > 0 ? val.toFixed(decimals) : Math.round(val).toLocaleString();
        if (progress < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
      observer.unobserve(el);
    });
  }, { threshold: 0.5 });

  counters.forEach(function (c) { observer.observe(c); });
})();

/* ---- HERO RADAR (landing & auth) ---- */
(function () {
  var canvas = document.getElementById('heroRadar') || document.getElementById('authRadar');
  if (!canvas) return;

  var ctx = canvas.getContext('2d');
  var W = canvas.width, H = canvas.height;
  var cx = W / 2, cy = H / 2;
  var R = Math.min(cx, cy) - 28;
  var axes = 5;
  var t = 0;

  var dataA = [0.75, 0.82, 0.60, 0.68, 0.55];
  var dataB = [0.45, 0.50, 0.40, 0.48, 0.62];

  function point(i, v, offset) {
    var a = (Math.PI * 2 / axes) * i - Math.PI / 2 + (offset || 0);
    return { x: cx + v * R * Math.cos(a), y: cy + v * R * Math.sin(a) };
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    t += 0.008;

    // Grid rings
    for (var r = 1; r <= 4; r++) {
      ctx.beginPath();
      for (var i = 0; i < axes; i++) {
        var p = point(i, r / 4);
        i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
      }
      ctx.closePath();
      ctx.strokeStyle = 'rgba(0,230,200,0.1)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    // Axis spokes
    for (var i = 0; i < axes; i++) {
      var p = point(i, 1);
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(p.x, p.y);
      ctx.strokeStyle = 'rgba(0,230,200,0.15)';
      ctx.lineWidth = 1; ctx.stroke();
    }

    // Animated polygon A
    var animA = dataA.map(function (v, i) {
      return Math.max(0.1, Math.min(1, v + Math.sin(t + i * 0.9) * 0.05));
    });
    ctx.beginPath();
    for (var i = 0; i < axes; i++) {
      var p = point(i, animA[i]);
      i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
    }
    ctx.closePath();
    ctx.fillStyle = 'rgba(0,230,200,0.12)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,230,200,0.85)';
    ctx.lineWidth = 2;
    ctx.shadowColor = '#00e6c8'; ctx.shadowBlur = 8;
    ctx.stroke(); ctx.shadowBlur = 0;

    // Polygon B (dashed)
    ctx.beginPath();
    for (var i = 0; i < axes; i++) {
      var wobble = Math.sin(t * 0.7 + i * 1.3) * 0.04;
      var p = point(i, dataB[i] + wobble);
      i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
    }
    ctx.closePath();
    ctx.fillStyle = 'rgba(139,92,246,0.08)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(139,92,246,0.45)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([4, 4]); ctx.stroke(); ctx.setLineDash([]);

    // Dots
    for (var i = 0; i < axes; i++) {
      var p = point(i, animA[i]);
      ctx.beginPath(); ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = '#00e6c8';
      ctx.shadowColor = '#00e6c8'; ctx.shadowBlur = 10;
      ctx.fill(); ctx.shadowBlur = 0;
    }

    requestAnimationFrame(draw);
  }
  draw();
})();

/* ---- SCROLL REVEAL ---- */
(function () {
  var cards = document.querySelectorAll('.feature-card, .step-card, .stat-box');
  if (!cards.length) return;
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) {
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        observer.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });
  cards.forEach(function (c) {
    c.style.opacity = '0';
    c.style.transform = 'translateY(24px)';
    c.style.transition = 'opacity 0.55s ease, transform 0.55s ease';
    observer.observe(c);
  });
})();

/* ---- TOAST ---- */
function showToast(msg, type) {
  var old = document.querySelector('.ev-toast');
  if (old) old.remove();

  var t = document.createElement('div');
  t.className = 'ev-toast ev-toast-' + (type || 'info');
  t.innerHTML = '<span>' + msg + '</span><button onclick="this.parentElement.remove()">&#10005;</button>';

  var style = document.getElementById('ev-toast-style');
  if (!style) {
    style = document.createElement('style');
    style.id = 'ev-toast-style';
    style.textContent = [
      '.ev-toast{position:fixed;bottom:1.8rem;right:1.8rem;z-index:9999;display:flex;align-items:center;gap:.8rem;padding:.85rem 1.3rem;border-radius:10px;font-family:inherit;font-size:.84rem;backdrop-filter:blur(14px);border:1px solid;max-width:360px;animation:fadeUp .3s ease both}',
      '.ev-toast button{background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:.9rem;padding:0;margin-left:.4rem}',
      '.ev-toast-success{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);color:#22c55e}',
      '.ev-toast-error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#ef4444}',
      '.ev-toast-info{background:rgba(0,230,200,.12);border-color:rgba(0,230,200,.35);color:#00e6c8}'
    ].join('');
    document.head.appendChild(style);
  }
  document.body.appendChild(t);
  setTimeout(function () { if (t.parentElement) t.remove(); }, 5000);
}
window.showToast = showToast;
