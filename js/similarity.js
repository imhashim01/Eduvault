/* =====================================================
   EduVault – similarity.js   FIXED VERSION

   ROOT CAUSE OF BUG:
   The entire SIM_DATA object was 100% hardcoded fake data
   (OS Lab Report, sara.pdf, Wikipedia matches etc.) that had
   nothing to do with the logged-in student's real submissions.
   The docSelect dropdown had 4 hardcoded fake options.
   Nothing ever called compare.php or read from the database.

   WHAT IS FIXED:
   1. loadMySubmissionsForRadar() — fetches the logged-in
      student's REAL submissions from get_submissions.php
      and populates the docSelect dropdown with them.
   2. loadSimilarity(id) — calls compare.php with the real
      submission ID and renders actual DB data on the radar.
   3. All hardcoded SIM_DATA removed entirely.
   4. View buttons in the table now pass the real submission ID.
   ===================================================== */

/* ============================================================
   STEP 1: Populate the dropdown with the student's own submissions
   ============================================================ */
function loadMySubmissionsForRadar(preSelectId) {
  var sel = document.getElementById('docSelect');
  if (!sel) return;

  sel.innerHTML = '<option value="">— Loading your submissions... —</option>';

  fetch('php/get_submissions.php', {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
    if (!res.success || !res.submissions || res.submissions.length === 0) {
      sel.innerHTML = '<option value="">— No submissions found —</option>';
      document.getElementById('simContent')     && (document.getElementById('simContent').style.display     = 'none');
      var ph = document.getElementById('simPlaceholder');
      if (ph) { ph.style.display = 'block'; ph.textContent = 'You have not submitted any assignments yet.'; }
      return;
    }

    /* Build dropdown options from real submissions */
    sel.innerHTML = '<option value="">— Select a submission to analyze —</option>';
    res.submissions.forEach(function(s) {
      var risk  = s.risk_level ? s.risk_level.toUpperCase() : 'UNKNOWN';
      var score = parseFloat(s.similarity_score || 0).toFixed(1);
      var opt   = document.createElement('option');
      opt.value       = s.id;
      opt.textContent = s.title + ' (' + s.course_code + ') — ' + score + '% [' + risk + ']';
      sel.appendChild(opt);
    });

    /* If a specific ID was requested (from View button), select it */
    var targetId = preSelectId || res.submissions[0].id;
    sel.value = targetId;
    if (typeof loadSimilarity === 'function') loadSimilarity(targetId);
  })
  .catch(function(err) {
    console.error('[Similarity] Failed to load submissions:', err);
    sel.innerHTML = '<option value="">— Error loading submissions —</option>';
  });
}

/* ============================================================
   STEP 2: Load real similarity data for a specific submission
   ============================================================ */
function loadSimilarity(subId) {
  subId = parseInt(subId);
  if (!subId || subId <= 0) return;

  var content     = document.getElementById('simContent');
  var placeholder = document.getElementById('simPlaceholder');

  if (placeholder) { placeholder.style.display = 'block'; placeholder.textContent = 'Loading analysis...'; }
  if (content)     { content.style.display = 'none'; }

  fetch('php/compare.php?id=' + subId, {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
    if (!res.success) {
      if (placeholder) { placeholder.style.display = 'block'; placeholder.textContent = 'Error: ' + (res.message || 'Could not load analysis.'); }
      return;
    }

    if (placeholder) placeholder.style.display = 'none';
    if (content)     content.style.display = 'block';

    var sub       = res.submission;
    var breakdown = res.breakdown;
    var matches   = res.matches || [];

    var score    = parseFloat(sub.similarity_score || 0);
    var risk     = sub.risk_level || 'low';
    var riskLabel= risk === 'high' ? 'HIGH RISK' : risk === 'medium' ? 'MEDIUM RISK' : 'LOW RISK';

    /* Tell ai_detection.js which submission is currently loaded */
    if (typeof setAISubmissionId === 'function') {
      setAISubmissionId(subId);
    }

    /* Compute radar axes from breakdown */
    var axes = [
      (breakdown.originality   || 0) / 100,
      (breakdown.structural    || 0) / 100,
      (breakdown.citation      || 0) / 100,
      (breakdown.paraphrase    || 0) / 100,
      (breakdown.source_diversity || 0) / 100
    ];
    /* Invert originality so HIGH score = more original (less red on radar) */
    axes[0] = Math.max(0.05, 1 - (score / 100));

    var baseline = [0.85, 0.40, 0.80, 0.45, 0.72];

    /* Render everything */
    updateRiskPill(risk, riskLabel);
    animateRing(score);
    animateRadar(axes, baseline);
    animateMetrics(breakdown, score);
    renderMatches(matches, sub);

    /* Update score display */
    var scoreEl = document.getElementById('scoreVal');
    if (scoreEl) scoreEl.textContent = Math.round(score);

  })
  .catch(function(err) {
    console.error('[Similarity] compare.php error:', err);
    if (placeholder) { placeholder.style.display = 'block'; placeholder.textContent = 'Server error loading analysis. Make sure you are logged in.'; }
  });
}
window.loadSimilarity = loadSimilarity;

/* ============================================================
   Render functions
   ============================================================ */

function updateRiskPill(risk, label) {
  var pill = document.getElementById('riskPill');
  if (!pill) return;
  pill.className   = 'risk-pill ' + risk;
  pill.textContent = label;
}

/* ---- Animate score ring ---- */
function animateRing(score) {
  var canvas = document.getElementById('scoreRing');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S   = 200;
  canvas.width = S; canvas.height = S;
  var cx  = S/2, cy = S/2, R = 78;
  var col = score > 50 ? '#ef4444' : score > 20 ? '#eab308' : '#22c55e';
  var dur = 1100, start = performance.now();
  var scoreEl = document.getElementById('scoreVal');

  function ease(t) { return 1 - Math.pow(1-t, 3); }
  (function frame(now) {
    var prog = Math.min((now - start) / dur, 1);
    var cur  = score * ease(prog);
    ctx.clearRect(0, 0, S, S);
    for (var i = 0; i < 30; i++) {
      var a = (Math.PI*2/30)*i - Math.PI/2, r1=R+12, r2=R+18;
      ctx.beginPath(); ctx.moveTo(cx+r1*Math.cos(a), cy+r1*Math.sin(a));
      ctx.lineTo(cx+r2*Math.cos(a), cy+r2*Math.sin(a));
      ctx.strokeStyle='rgba(255,255,255,0.07)'; ctx.lineWidth=1; ctx.stroke();
    }
    ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2);
    ctx.strokeStyle='rgba(255,255,255,0.06)'; ctx.lineWidth=13; ctx.stroke();
    ctx.beginPath(); ctx.arc(cx,cy,R,-Math.PI/2,-Math.PI/2+(cur/100)*Math.PI*2);
    ctx.strokeStyle=col; ctx.lineWidth=13; ctx.lineCap='round';
    ctx.shadowColor=col; ctx.shadowBlur=22; ctx.stroke(); ctx.shadowBlur=0;
    if (scoreEl) { scoreEl.textContent = Math.round(cur); scoreEl.style.color = col; }
    if (prog < 1) requestAnimationFrame(frame);
  })(start);
}

/* ---- Animate radar chart ---- */
function animateRadar(data, baseline) {
  var canvas = document.getElementById('radarMain');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S   = 260; canvas.width = S; canvas.height = S;
  var cx  = S/2, cy = S/2, R = S/2-36;
  var axes= data.length;
  var lbls= ['Orig.','Struct.','Cite','Para.','Src.'];
  var dur = 950, st = performance.now();

  function pt(i,v) { var a=(Math.PI*2/axes)*i-Math.PI/2; return{x:cx+v*R*Math.cos(a),y:cy+v*R*Math.sin(a)}; }
  function ease(t) { return 1-Math.pow(1-t,3); }

  (function frame(now) {
    var prog=Math.min((now-st)/dur,1), e=ease(prog);
    ctx.clearRect(0,0,S,S);
    for(var r=1;r<=4;r++){
      ctx.beginPath();
      for(var i=0;i<axes;i++){var p=pt(i,r/4);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);}
      ctx.closePath(); ctx.strokeStyle='rgba(0,230,200,0.1)'; ctx.lineWidth=1; ctx.stroke();
    }
    for(var i=0;i<axes;i++){
      var ep=pt(i,1); ctx.beginPath(); ctx.moveTo(cx,cy); ctx.lineTo(ep.x,ep.y);
      ctx.strokeStyle='rgba(0,230,200,0.15)'; ctx.lineWidth=1; ctx.stroke();
    }
    ctx.beginPath();
    baseline.forEach(function(v,i){var p=pt(i,v*e);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);});
    ctx.closePath(); ctx.fillStyle='rgba(0,230,200,0.06)'; ctx.fill();
    ctx.strokeStyle='rgba(0,230,200,0.3)'; ctx.lineWidth=1.5;
    ctx.setLineDash([4,4]); ctx.stroke(); ctx.setLineDash([]);
    ctx.beginPath();
    data.forEach(function(v,i){var p=pt(i,v*e);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);});
    ctx.closePath(); ctx.fillStyle='rgba(239,68,68,0.14)'; ctx.fill();
    ctx.strokeStyle='#ef4444'; ctx.lineWidth=2;
    ctx.shadowColor='#ef4444'; ctx.shadowBlur=8; ctx.stroke(); ctx.shadowBlur=0;
    data.forEach(function(v,i){
      var p=pt(i,v*e); ctx.beginPath(); ctx.arc(p.x,p.y,3.5,0,Math.PI*2);
      ctx.fillStyle='#ef4444'; ctx.shadowColor='#ef4444'; ctx.shadowBlur=10;
      ctx.fill(); ctx.shadowBlur=0;
    });
    lbls.forEach(function(l,i){
      var p=pt(i,1.22); ctx.fillStyle='rgba(136,146,176,0.85)';
      ctx.font='10px Segoe UI,sans-serif'; ctx.textAlign='center'; ctx.textBaseline='middle';
      ctx.fillText(l,p.x,p.y);
    });
    if(prog<1) requestAnimationFrame(frame);
  })(st);
}

/* ---- Animate metric bars using real breakdown data ---- */
function animateMetrics(breakdown, score) {
  var metrics = [
    { id:'m1', vid:'mv1', name:'Originality Score',  val: Math.round(Math.max(0, 100 - score)) },
    { id:'m2', vid:'mv2', name:'Structural Match',   val: Math.round(breakdown.structural    || 0) },
    { id:'m3', vid:'mv3', name:'Citation Integrity', val: Math.round(breakdown.citation      || 0) },
    { id:'m4', vid:'mv4', name:'Paraphrase Index',   val: Math.round(breakdown.paraphrase    || 0) },
    { id:'m5', vid:'mv5', name:'Source Diversity',   val: Math.round(breakdown.source_diversity || 0) }
  ];

  metrics.forEach(function(m, idx) {
    var fill = document.getElementById(m.id);
    var val  = document.getElementById(m.vid);
    if (!fill) return;

    var color = m.val > 60 ? '#ef4444' : m.val > 30 ? '#eab308' : '#22c55e';
    /* Originality: high value = good (green) */
    if (m.id === 'm1') color = m.val > 60 ? '#22c55e' : m.val > 30 ? '#eab308' : '#ef4444';

    fill.style.width      = '0%';
    fill.style.background = color;
    if (val) val.textContent = m.val + '%';

    var nameEl = fill.closest && fill.closest('.metric') && fill.closest('.metric').querySelector('.m-name');
    if (nameEl) nameEl.textContent = m.name;

    (function(el, target, delay) {
      setTimeout(function() {
        el.style.transition = 'width 1s ease';
        el.style.width = target + '%';
      }, 150 + delay * 80);
    })(fill, m.val, idx);
  });
}

/* ---- Render matched segments from real DB data ---- */
function renderMatches(matches, sub) {
  var list = document.getElementById('matchList');
  if (!list) return;

  if (!matches || matches.length === 0) {
    list.innerHTML = '<p style="color:#9090aa;padding:1rem;font-size:13px;">No matching submissions found in the database for this assignment.</p>';
    return;
  }

  list.innerHTML = matches.map(function(m) {
    var pct  = parseFloat(m.score || 0);
    var cls  = pct >= 50 ? 'high-m' : pct >= 20 ? 'med-m' : 'low-m';
    var pcls = pct >= 50 ? 'high'   : pct >= 20 ? 'med'   : 'low';
    var snippet = m.snippet || m.matched_text_snippet || '(No text snippet available)';
    var source  = m.source  || 'Unknown source';

    return '<div class="match-item ' + cls + '">' +
      '<div class="match-top">' +
        '<span class="match-src">' + escHtml(source) + '</span>' +
        '<span class="match-pct ' + pcls + '">' + pct.toFixed(1) + '% match</span>' +
      '</div>' +
      '<p>' + escHtml(snippet) + '</p>' +
    '</div>';
  }).join('');
}

/* ---- HTML escape helper ---- */
function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
   Dropdown change handler
   ============================================================ */
(function() {
  var sel = document.getElementById('docSelect');
  if (sel) {
    sel.addEventListener('change', function() {
      if (this.value) loadSimilarity(parseInt(this.value));
    });
  }
})();

/* Exposed so dashboard.js initSimilarityCharts() can call it */
window.loadMySubmissionsForRadar = loadMySubmissionsForRadar;

/* ============================================================
   Re-analyze button handler
   Reloads the currently selected submission's similarity data
   ============================================================ */
function reanalyze() {
  var sel = document.getElementById('docSelect');
  if (!sel || !sel.value) {
    showToast('Please select a submission from the dropdown first.', 'error');
    return;
  }
  var subId = parseInt(sel.value);
  if (!subId) return;

  var btn = document.querySelector('.analysis-bar .btn-primary');
  if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }

  loadSimilarity(subId);

  setTimeout(function() {
    if (btn) { btn.disabled = false; btn.textContent = '⟳ Re-analyze'; }
  }, 2000);
}
window.reanalyze = reanalyze;