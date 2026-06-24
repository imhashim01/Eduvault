/* =====================================================
   EduVault – ai_detection.js   *** FIXED VERSION ***

   BUGS FIXED:
   ----------------------------------------------------------
   BUG 6 – currentSubIdForAI was null when user clicked
            "Run AI Detection" before the async loadSimilarity()
            fetch had completed and called setAISubmissionId().
            The old code showed "Please select a submission"
            even though one was visually selected in docSelect.
            FIX: runAIDetection() now reads docSelect.value as
            a fallback when currentSubIdForAI is null.

   BUG 7 – If the server returned a non-JSON response (e.g. a
            PHP error page or a 500 with HTML), r.json() threw,
            going to .catch() with only "Server error during AI
            detection. Check XAMPP is running." — giving the
            user no useful information.
            FIX: .catch() now calls r.text() first, logs the raw
            response to console, and shows a more helpful toast
            with the actual HTTP status code.

   BUG 8 – The loading animation's setInterval kept running if
            the user navigated away and came back, stacking
            multiple intervals that fought each other.
            FIX: clearInterval guard added on every new run.
   ===================================================== */

/* ---- State ---- */
var currentSubIdForAI = null;
var _aiStepTimer      = null;   /* BUG 8 FIX: track the interval */

/* Called from similarity.js after loadSimilarity() resolves */
function setAISubmissionId(subId) {
  currentSubIdForAI = parseInt(subId) || null;
  resetAIPanel();
}
window.setAISubmissionId = setAISubmissionId;

/* ---- Reset panel to initial state ---- */
function resetAIPanel() {
  var results = document.getElementById('aiResults');
  var loading = document.getElementById('aiLoading');
  var btn     = document.getElementById('runAIBtn');
  if (results) results.style.display = 'none';
  if (loading) loading.style.display = 'none';
  if (btn)     { btn.disabled = false; btn.textContent = '🤖 Run AI Detection'; }
  var fill = document.getElementById('aiProgressFill');
  if (fill) fill.style.width = '0%';
  /* BUG 8 FIX: clear any lingering step timer */
  if (_aiStepTimer) { clearInterval(_aiStepTimer); _aiStepTimer = null; }
}

/* ---- Run AI Detection ---- */
function runAIDetection() {
  /* BUG 6 FIX: fall back to docSelect value if ID not yet set */
  var subId = currentSubIdForAI;
  if (!subId) {
    var sel = document.getElementById('docSelect');
    subId = sel ? (parseInt(sel.value) || null) : null;
    if (subId) currentSubIdForAI = subId;   /* cache it */
  }

  if (!subId) {
    showToast('Please select a submission from the dropdown above first.', 'error');
    return;
  }

  var btn     = document.getElementById('runAIBtn');
  var loading = document.getElementById('aiLoading');
  var results = document.getElementById('aiResults');

  /* BUG 8 FIX: kill any previous step timer before starting */
  if (_aiStepTimer) { clearInterval(_aiStepTimer); _aiStepTimer = null; }

  /* Show loading */
  if (btn)     { btn.disabled = true; btn.textContent = '⏳ Analyzing...'; }
  if (results) results.style.display = 'none';
  if (loading) loading.style.display = 'block';

  /* Animate loading messages */
  var steps = [
    'Tokenizing words and sentences...',
    'Running perplexity analysis...',
    'Measuring burstiness patterns...',
    'Checking vocabulary richness...',
    'Detecting transition phrases...',
    'Analyzing passive voice ratio...',
    'Scanning AI signature words...',
    'Computing final AI probability...'
  ];
  var stepIdx = 0;
  var fill    = document.getElementById('aiProgressFill');
  var msgEl   = document.getElementById('aiLoadingMsg');
  var stepEl  = document.getElementById('aiLoadingStep');

  _aiStepTimer = setInterval(function () {   /* BUG 8 FIX: stored reference */
    if (stepIdx < steps.length) {
      if (msgEl)  msgEl.textContent  = steps[stepIdx];
      if (stepEl) stepEl.textContent = 'Step ' + (stepIdx + 1) + ' of ' + steps.length;
      if (fill)   fill.style.width   = ((stepIdx + 1) / steps.length * 90) + '%';
      stepIdx++;
    }
  }, 380);

  /* Build FormData */
  var fd = new FormData();
  fd.append('submission_id', subId);

  /* BUG 7 FIX: two-stage response handling — try JSON, fall back to text */
  fetch('php/ai_detect.php', {
    method:  'POST',
    body:    fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function (r) {
    /* BUG 7 FIX: read response as text first so we can inspect it */
    return r.text().then(function (raw) {
      return { ok: r.ok, status: r.status, raw: raw };
    });
  })
  .then(function (resp) {
    clearInterval(_aiStepTimer); _aiStepTimer = null;
    if (fill) fill.style.width = '100%';

    setTimeout(function () {
      if (loading) loading.style.display = 'none';
      if (btn)     { btn.disabled = false; btn.textContent = '🤖 Re-run Detection'; }

      /* Try to parse JSON */
      var res = null;
      try {
        res = JSON.parse(resp.raw);
      } catch (e) {
        /* BUG 7 FIX: show actual server response in console for debugging */
        console.error('[EduVault AI] Non-JSON response (HTTP ' + resp.status + '):', resp.raw);
        showToast(
          'AI Detection error (HTTP ' + resp.status + '). ' +
          'Open browser console (F12) to see the PHP error details.',
          'error'
        );
        return;
      }

      if (!res.success) {
        showToast('AI Detection: ' + (res.message || 'Unknown error'), 'error');
        return;
      }

      renderAIResults(res);
      if (results) results.style.display = 'block';

    }, 400);
  })
  .catch(function (err) {
    /* Network-level failure (XAMPP not running, DNS failure, etc.) */
    if (_aiStepTimer) { clearInterval(_aiStepTimer); _aiStepTimer = null; }
    if (loading) loading.style.display = 'none';
    if (btn)     { btn.disabled = false; btn.textContent = '🤖 Run AI Detection'; }
    console.error('[EduVault AI] Network error:', err);
    showToast(
      'Cannot reach the server. Make sure XAMPP Apache is running and you are at http://localhost/...',
      'error'
    );
  });
}
window.runAIDetection = runAIDetection;

/* ---- Render all AI results ---- */
function renderAIResults(res) {
  var prob      = parseFloat(res.ai_probability || 0);
  var verdict   = res.verdict       || 'unknown';
  var label     = res.verdict_label || 'Unknown';
  var conf      = res.confidence    || '—';
  var note      = res.analysis_note || '';
  var wordCount = res.word_count    || 0;
  var detectors = res.detectors     || {};
  var flagged   = res.flagged_phrases || [];

  drawAIRing(prob);

  var numEl = document.getElementById('aiProbNum');
  if (numEl) animateNumber(numEl, prob, 1200);

  var badgeEl = document.getElementById('aiVerdictBadge');
  if (badgeEl) {
    badgeEl.textContent = label;
    badgeEl.className   = 'ai-verdict-badge ' + verdict;
  }

  var card = document.getElementById('aiVerdictCard');
  if (card) {
    var colors = {
      likely_ai:    '#c084fc',
      possibly_ai:  '#fb923c',
      mixed:        '#eab308',
      likely_human: '#22c55e',
      insufficient: '#2a2a4a'
    };
    card.style.borderLeft = '4px solid ' + (colors[verdict] || '#2a2a4a');
  }

  var confEl = document.getElementById('aiConfidence');
  var noteEl = document.getElementById('aiNote');
  var wcEl   = document.getElementById('aiWordCount');
  if (confEl) confEl.textContent = conf;
  if (noteEl) noteEl.textContent = note;
  if (wcEl)   wcEl.textContent   = wordCount.toLocaleString();

  renderDetectors(detectors);
  renderFlaggedPhrases(flagged);

  showToast(
    'AI detection complete — ' + prob + '% AI probability (' + label + ')',
    prob >= 50 ? 'error' : 'success'
  );
}

/* ---- Draw AI probability ring ---- */
function drawAIRing(prob) {
  var canvas = document.getElementById('aiRing');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S   = 160; canvas.width = S; canvas.height = S;
  var cx  = S / 2, cy = S / 2, R = 60;
  var col = prob >= 75 ? '#c084fc' : prob >= 50 ? '#fb923c' : prob >= 25 ? '#eab308' : '#22c55e';
  var dur = 1200, start = performance.now();

  function ease(t) { return 1 - Math.pow(1 - t, 3); }

  (function frame(now) {
    var prog = Math.min((now - start) / dur, 1);
    var cur  = prob * ease(prog);
    ctx.clearRect(0, 0, S, S);

    for (var i = 0; i < 24; i++) {
      var a = (Math.PI * 2 / 24) * i - Math.PI / 2;
      ctx.beginPath();
      ctx.moveTo(cx + (R + 10) * Math.cos(a), cy + (R + 10) * Math.sin(a));
      ctx.lineTo(cx + (R + 16) * Math.cos(a), cy + (R + 16) * Math.sin(a));
      ctx.strokeStyle = 'rgba(255,255,255,0.06)'; ctx.lineWidth = 1; ctx.stroke();
    }

    ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.06)'; ctx.lineWidth = 11; ctx.stroke();

    ctx.beginPath(); ctx.arc(cx, cy, R, -Math.PI / 2, -Math.PI / 2 + (cur / 100) * Math.PI * 2);
    ctx.strokeStyle = col; ctx.lineWidth = 11; ctx.lineCap = 'round';
    ctx.shadowColor = col; ctx.shadowBlur = 18; ctx.stroke(); ctx.shadowBlur = 0;

    if (prog < 1) requestAnimationFrame(frame);
  })(start);
}

/* ---- Render 8 detector cards ---- */
function renderDetectors(detectors) {
  var grid = document.getElementById('aiDetectorsGrid');
  if (!grid) return;

  var order = ['perplexity','burstiness','vocabulary','uniformity','transitions','passive_voice','hedging','ai_signatures'];
  var icons = {
    perplexity:    '📈', burstiness:   '🔶', vocabulary:  '📚', uniformity:   '⚖',
    transitions:   '🔗', passive_voice:'✍',  hedging:     '🤔', ai_signatures:'🤖'
  };

  grid.innerHTML = '';
  order.forEach(function (key, idx) {
    var d = detectors[key];
    if (!d) return;
    var score   = Math.round(d.score || 0);
    var color   = score >= 70 ? '#ef4444' : score >= 45 ? '#eab308' : '#22c55e';
    var details = String(d.details || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    var card = document.createElement('div');
    card.className = 'ai-detector-card box-card';
    card.innerHTML =
      '<span style="font-size:1.4rem;flex-shrink:0">' + (icons[key] || '●') + '</span>' +
      '<div class="ai-det-info">' +
        '<span class="ai-det-label">' + (d.label || key) + '</span>' +
        '<div class="ai-det-bar-wrap">' +
          '<div class="ai-det-bar" id="aiBar_' + key + '" style="background:' + color + ';width:0%"></div>' +
        '</div>' +
        '<span class="ai-det-details">' + details + '</span>' +
      '</div>' +
      '<span class="ai-det-score" style="color:' + color + '">' + score + '%</span>';
    grid.appendChild(card);

    /* Staggered bar animation */
    (function (barId, target, delay) {
      setTimeout(function () {
        var bar = document.getElementById(barId);
        if (bar) { bar.style.transition = 'width 0.9s ease'; bar.style.width = target + '%'; }
      }, 200 + delay * 80);
    })('aiBar_' + key, score, idx);
  });
}

/* ---- Render flagged phrases ---- */
function renderFlaggedPhrases(flagged) {
  var wrap = document.getElementById('aiFlagged');
  var list = document.getElementById('aiFlaggedList');
  if (!wrap || !list) return;

  if (!flagged || flagged.length === 0) {
    wrap.style.display = 'none';
    return;
  }
  wrap.style.display = 'block';
  list.innerHTML = flagged.map(function (phrase) {
    return '<div class="ai-flagged-item">&ldquo;' +
      String(phrase).replace(/</g, '&lt;').replace(/>/g, '&gt;') +
      '&rdquo;</div>';
  }).join('');
}

/* ---- Number counter animation ---- */
function animateNumber(el, target, duration) {
  var start = performance.now();
  (function frame(now) {
    var prog = Math.min((now - start) / duration, 1);
    var ease = 1 - Math.pow(1 - prog, 3);
    el.textContent = Math.round(target * ease);
    if (prog < 1) requestAnimationFrame(frame);
  })(start);
}