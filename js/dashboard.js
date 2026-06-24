/* =====================================================
   EduVault – dashboard.js   (Pure JS, no libraries)
   ===================================================== */

var CURRENT_ROLE = 'student'; // updated after session fetch

/* ---- PANEL NAVIGATION ---- */
function showPanel(name) {
  document.querySelectorAll('.panel').forEach(function (p) { p.classList.remove('active'); });
  document.querySelectorAll('.nav-item[data-panel]').forEach(function (l) { l.classList.remove('active'); });

  var panel = document.getElementById('panel-' + name);
  var link  = document.querySelector('.nav-item[data-panel="' + name + '"]');
  if (panel) panel.classList.add('active');
  if (link)  link.classList.add('active');

  if (name === 'overview')   initOverviewCharts();
  if (name === 'analytics')  initAnalyticsCharts();
  if (name === 'similarity') initSimilarityCharts();

  closeSidebar();
}
window.showPanel = showPanel;

/* Navigate to similarity panel AND pre-load a specific submission */
function viewSimilarity(subId) {
  showPanel('similarity');
  /* Wait for panel to be visible, then select & load this submission */
  setTimeout(function() {
    var sel = document.getElementById('docSelect');
    if (sel && subId) {
      /* If the option already exists, select it and load */
      var opt = sel.querySelector('option[value="' + subId + '"]');
      if (opt) {
        sel.value = subId;
        if (typeof loadSimilarity === 'function') loadSimilarity(subId);
      } else {
        /* Dropdown not yet populated — load submissions first then select */
        if (typeof loadMySubmissionsForRadar === 'function') {
          loadMySubmissionsForRadar(subId);
        }
      }
    }
  }, 120);
}
window.viewSimilarity = viewSimilarity;

function openSidebar()  { document.getElementById('sidebar').classList.add('open'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); }
window.openSidebar  = openSidebar;
window.closeSidebar = closeSidebar;

function toggleNotif() {
  var p = document.getElementById('notifPanel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
window.toggleNotif = toggleNotif;

/* ---- BUILD ROLE-SPECIFIC SIDEBAR ---- */
function buildNav(role) {
  var nav = document.getElementById('sidenav');
  if (!nav) return;

  var studentLinks = [
    { panel: 'overview',     icon: '🏠', label: 'Overview' },
    { panel: 'submit',       icon: '📤', label: 'Submit Assignment' },
    { panel: 'submissions',  icon: '📁', label: 'My Submissions' },
    { panel: 'similarity',   icon: '🔬', label: 'Similarity Radar' },
  ];

  var adminLinks = [
    { panel: 'overview',    icon: '🏠', label: 'Dashboard' },
    { panel: 'admin',       icon: '🛡️', label: 'All Submissions' },
    { panel: 'analytics',   icon: '📊', label: 'Analytics' },
    { panel: 'similarity',  icon: '🔬', label: 'Similarity Radar' },
    { panel: 'users',       icon: '👥', label: 'Manage Users' },
  ];

  var links = role === 'admin' ? adminLinks : studentLinks;

  nav.innerHTML =
    '<span class="nav-label">' + (role === 'admin' ? 'Admin' : 'Student') + '</span>' +
    links.map(function (l, i) {
      return '<a href="#" class="nav-item' + (i === 0 ? ' active' : '') +
             '" data-panel="' + l.panel + '">' +
             '<span>' + l.icon + '</span> ' + l.label + '</a>';
    }).join('') +
    '<span class="nav-label">Account</span>' +
    '<a href="#" class="nav-item" data-panel="settings"><span>⚙</span> Settings</a>' +
    '<a href="php/logout.php" class="nav-item logout"><span>🚪</span> Sign Out</a>';

  /* Re-attach click handlers */
  nav.querySelectorAll('.nav-item[data-panel]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      showPanel(link.dataset.panel);
    });
  });

  /* Show/hide panels based on role */
  var studentOnly = ['panel-submit'];
  var adminOnly   = ['panel-admin', 'panel-analytics', 'panel-users'];

  if (role === 'admin') {
    studentOnly.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    /* Style admin overview differently */
    var welcome = document.getElementById('overviewWelcome');
    if (welcome) welcome.textContent = 'System overview — all student submissions and risk levels.';
  } else {
    adminOnly.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
  }
}

/* ---- DATA ---- */
var SUBS       = [];
var ADMIN_DATA = [];

function loadDashboardData() {
  /* Session / user info first — determines role */
  fetch('php/dashboard_data.php?action=me')
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res.success) {
        window.location.href = 'login.html';
        return;
      }

      CURRENT_ROLE = res.role;

      /* Update UI with real user info */
      var initials = res.name.split(' ').map(function (n) { return n[0] || ''; }).join('').toUpperCase().slice(0,2);
      var el;
      if ((el = document.getElementById('sidebarName')))  el.textContent = res.name;
      if ((el = document.getElementById('sidebarRole')))  el.textContent = (res.role === 'admin' ? '🛡 Admin' : '🎓 Student') + (res.dept ? ' · ' + res.dept : '');
      if ((el = document.getElementById('sidebarAvatar'))) el.textContent = initials;
      if ((el = document.getElementById('topAvatar')))    el.textContent = initials;
      if ((el = document.getElementById('userName')))     el.textContent = res.name;
      if ((el = document.getElementById('userRole')))     el.textContent = res.role.charAt(0).toUpperCase() + res.role.slice(1);

      /* Build role-appropriate nav */
      buildNav(res.role);

      /* Load rest of data */
      loadSubmissions(res.role);
      loadStats(res.role);
    })
    .catch(function () {
      /* Not logged in or server down */
      window.location.href = 'login.html';
    });
}

function loadSubmissions(role) {
  if (role === 'admin') {
    fetch('php/dashboard_data.php?action=admin_submissions')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) {
          console.error('[loadSubmissions admin]', res.message);
          return;
        }
        ADMIN_DATA = res.data.map(function (r) {
          return {
            id:           r.id,
            student:      r.student,
            assign:       r.assign,
            dept:         r.dept,
            date:         r.date,
            sim:          parseFloat(r.sim) || 0,
            ai_probability: parseFloat(r.ai_prob) || 0,
            risk:         r.risk === 'medium' ? 'med' : (r.risk || 'low'),
            status:       r.status === 'flagged' ? 'review' : (r.status || 'pending'),
          };
        });
        renderAdmin(ADMIN_DATA);
        renderRecent(ADMIN_DATA.map(function(r){
          return { id: r.id, title: r.assign + ' (' + r.student + ')', course: r.dept, date: r.date, sim: r.sim, ai_probability: r.ai_probability, risk: r.risk, status: r.status };
        }));
      })
      .catch(function (err) { console.error('[loadSubmissions admin fetch]', err); });
  } else {
    fetch('php/dashboard_data.php?action=submissions')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) {
          console.error('[loadSubmissions student]', res.message);
          return;
        }
        SUBS = res.data.map(function (r) {
          return {
            id:            r.id,
            title:         r.title,
            course:        r.course,
            date:          r.date,
            sim:           parseFloat(r.sim) || 0,
            ai_probability: parseFloat(r.ai_prob) || 0,
            risk:          r.risk === 'medium' ? 'med' : (r.risk || 'low'),
            status:        r.status === 'flagged' ? 'review' : (r.status || 'pending'),
          };
        });
        renderSubs(SUBS);
        renderRecent(SUBS);
      })
      .catch(function (err) { console.error('[loadSubmissions student fetch]', err); });
  }
}

function loadStats(role) {
  fetch('php/dashboard_data.php?action=stats')
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res.success) return;
      var map = {
        'statTotal':   res.total,
        'statFlagged': res.flagged,
        'statUsers':   res.users,
        'statAvg':     res.avg_sim + '%',
      };
      /* Update KPI cards */
      Object.keys(map).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.textContent = map[id];
      });
      /* Update overview KPI numbers too */
      var kpiNums = document.querySelectorAll('.kpi-num');
      if (kpiNums[0]) kpiNums[0].textContent = res.total;
      if (kpiNums[1]) kpiNums[1].textContent = res.total - res.flagged;
      if (kpiNums[2]) kpiNums[2].textContent = res.flagged;

      if (role === 'admin') {
        /* Admin 4th KPI: total users */
        if (kpiNums[3]) kpiNums[3].textContent = res.users;
        var kpiLabels = document.querySelectorAll('.kpi-lbl');
        if (kpiLabels[3]) kpiLabels[3].textContent = 'Registered Students';
      } else {
        if (kpiNums[3]) kpiNums[3].textContent = res.avg_sim + '%';
      }
    })
    .catch(function () {});
}

/* Load data when page is ready */
document.addEventListener('DOMContentLoaded', loadDashboardData);

/* ---- HELPERS ---- */
function riskClass(risk) {
  return risk === 'high' ? 'high' : risk === 'med' ? 'med' : 'low';
}
function statusBadge(s) {
  if (s === 'approved') return '<span class="status-approved">Approved</span>';
  if (s === 'review')   return '<span class="status-review">Under Review</span>';
  if (s === 'flagged')  return '<span class="status-review">Flagged</span>';
  return '<span class="status-rejected">' + (s || 'Pending') + '</span>';
}

/* ---- RECENT TABLE (overview) ---- */
function renderRecent(data) {
  var tbody = document.getElementById('recentBody');
  if (!tbody) return;
  tbody.innerHTML = data.slice(0, 5).map(function (r) {
    return '<tr>' +
      '<td>📄 ' + r.title + '</td>' +
      '<td>' + r.course + '</td>' +
      '<td class="mono">' + r.date + '</td>' +
      '<td><span class="badge-sim ' + riskClass(r.risk) + '">' + r.sim + '%</span></td>' +
      '<td>' + statusBadge(r.status) + '</td>' +
      '<td><button class="act-btn" onclick="viewSimilarity(' + r.id + ')">View</button></td>' +
    '</tr>';
  }).join('') || '<tr><td colspan="6" style="text-align:center;color:#8b9cc8;padding:24px">No submissions yet</td></tr>';
}

/* ---- STUDENT: MY SUBMISSIONS TABLE ---- */
var currentSubs = [];

function renderSubs(data) {
  var tbody = document.getElementById('subsBody');
  if (!tbody) return;
  tbody.innerHTML = data.map(function (r) {
    var aiProb = parseFloat(r.ai_probability || 0);
    var aiColor = aiProb >= 75 ? '#c084fc' : aiProb >= 50 ? '#fb923c' : aiProb >= 25 ? '#eab308' : '#22c55e';
    var aiText  = aiProb > 0 ? aiProb.toFixed(0) + '%' : '—';
    return '<tr>' +
      '<td>📄 ' + r.title + '</td>' +
      '<td>' + r.course + '</td>' +
      '<td class="mono">' + r.date + '</td>' +
      '<td><span class="badge-sim ' + riskClass(r.risk) + '">' + r.sim + '%</span></td>' +
      '<td><span style="font-size:12px;font-family:Courier New,monospace;color:' + aiColor + '">' + aiText + '</span></td>' +
      '<td>' + statusBadge(r.status) + '</td>' +
      '<td style="display:flex;gap:.3rem">' +
        '<button class="act-btn" onclick="viewSimilarity(' + (r.id||0) + ')">Analyze</button>' +
      '</td>' +
    '</tr>';
  }).join('') || '<tr><td colspan="7" style="text-align:center;color:#8b9cc8;padding:24px">No submissions yet — <a href="#" onclick="showPanel(\'submit\')" style="color:#00e6c8">submit your first assignment</a></td></tr>';

  var cnt = document.getElementById('subCount');
  if (cnt) cnt.textContent = 'Showing ' + data.length + ' entr' + (data.length === 1 ? 'y' : 'ies');
}

function filterSubs() {
  var q      = (document.getElementById('subSearch').value || '').toLowerCase();
  var course = document.getElementById('filterCourse').value;
  var status = document.getElementById('filterStatus').value.toLowerCase();
  var risk   = document.getElementById('filterRisk').value;

  var filtered = SUBS.filter(function (r) {
    var mQ = !q      || r.title.toLowerCase().includes(q) || r.course.toLowerCase().includes(q);
    var mC = !course || r.course === course;
    var mS = !status || r.status === status;
    var mR = !risk   || r.risk === risk;
    return mQ && mC && mS && mR;
  });
  renderSubs(filtered);
}
window.filterSubs = filterSubs;

function clearFilters() {
  ['subSearch','filterCourse','filterStatus','filterRisk'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.value = '';
  });
  renderSubs(SUBS);
}
window.clearFilters = clearFilters;

var sortDir = {};
function sortSubs(col) {
  sortDir[col] = !sortDir[col];
  var keys = ['title','course','date','sim'];
  var k    = keys[col];
  var sorted = SUBS.slice().sort(function (a, b) {
    return sortDir[col] ? (a[k] > b[k] ? 1 : -1) : (a[k] < b[k] ? 1 : -1);
  });
  renderSubs(sorted);
}
window.sortSubs = sortSubs;

/* ---- ADMIN: ALL SUBMISSIONS TABLE ---- */
function renderAdmin(data) {
  var tbody = document.getElementById('adminBody');
  if (!tbody) return;
  tbody.innerHTML = data.map(function (r) {
    var initials = r.student.split(' ').map(function (n) { return n[0] || ''; }).join('');
    return '<tr>' +
      '<td><div style="display:flex;align-items:center;gap:.6rem">' +
        '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--purple));display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;color:#000;flex-shrink:0">' + initials + '</div>' +
        r.student + '</div></td>' +
      '<td>' + r.assign + '</td>' +
      '<td>' + r.dept + '</td>' +
      '<td class="mono">' + r.date + '</td>' +
      '<td><span class="badge-sim ' + riskClass(r.risk) + '">' + r.sim + '%</span></td>' +
      '<td>' + statusBadge(r.status) + '</td>' +
      '<td style="display:flex;gap:.3rem">' +
        '<button class="act-btn" onclick="viewSimilarity(' + (r.id||0) + ')">View</button>' +
        '<button class="act-btn warn" onclick="adminApprove(this,' + (r.id||0) + ')">Approve</button>' +
        '<button class="act-btn danger" onclick="adminFlag(this,' + (r.id||0) + ')">Flag</button>' +
      '</td>' +
    '</tr>';
  }).join('') || '<tr><td colspan="7" style="text-align:center;color:#8b9cc8;padding:24px">No submissions in the system yet</td></tr>';
}

/* Admin action: approve submission */
function adminApprove(btn, id) {
  fetch('php/admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=update_submission_status&id=' + id + '&status=approved'
  })
  .then(function(r){ return r.json(); })
  .then(function(res){
    if (res.success) {
      var td = btn.closest('tr').querySelectorAll('td')[5];
      if (td) td.innerHTML = statusBadge('approved');
      showToast('Submission approved.', 'success');
    } else {
      showToast(res.message || 'Action failed.', 'error');
    }
  })
  .catch(function(){ showToast('Server error.', 'error'); });
}
window.adminApprove = adminApprove;

/* Admin action: flag submission */
function adminFlag(btn, id) {
  fetch('php/admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=update_submission_status&id=' + id + '&status=flagged'
  })
  .then(function(r){ return r.json(); })
  .then(function(res){
    if (res.success) {
      var td = btn.closest('tr').querySelectorAll('td')[5];
      if (td) td.innerHTML = statusBadge('review');
      showToast('Submission flagged for review.', 'success');
    } else {
      showToast(res.message || 'Action failed.', 'error');
    }
  })
  .catch(function(){ showToast('Server error.', 'error'); });
}
window.adminFlag = adminFlag;

function filterAdmin() {
  var q    = (document.getElementById('adminSearch').value || '').toLowerCase();
  var dept = document.getElementById('adminDept').value;
  var risk = document.getElementById('adminRisk').value;
  var filtered = ADMIN_DATA.filter(function (r) {
    return (!q    || r.student.toLowerCase().includes(q) || r.assign.toLowerCase().includes(q)) &&
           (!dept || r.dept === dept) &&
           (!risk || r.risk === risk);
  });
  renderAdmin(filtered);
}
window.filterAdmin = filterAdmin;

/* ---- GLOBAL SEARCH ---- */
var gs = document.getElementById('globalSearch');
if (gs) {
  gs.addEventListener('input', function () {
    var q = this.value.toLowerCase();
    if (!q) return;
    if (CURRENT_ROLE === 'admin') {
      var found = ADMIN_DATA.filter(function (r) {
        return r.student.toLowerCase().includes(q) || r.assign.toLowerCase().includes(q);
      });
      showPanel('admin');
      renderAdmin(found);
    } else {
      var found = SUBS.filter(function (r) {
        return r.title.toLowerCase().includes(q) || r.course.toLowerCase().includes(q);
      });
      showPanel('submissions');
      renderSubs(found);
    }
  });
}

/* ---- CSV EXPORT ---- */
function exportCSV() {
  var rows = [['Student','Assignment','Department','Date','Similarity','Status']];
  ADMIN_DATA.forEach(function (r) {
    rows.push([r.student, r.assign, r.dept, r.date, r.sim + '%', r.status]);
  });
  var csv  = rows.map(function (r) { return r.map(function (v) { return '"' + v + '"'; }).join(','); }).join('\n');
  var blob = new Blob([csv], { type: 'text/csv' });
  var a    = document.createElement('a');
  a.href   = URL.createObjectURL(blob);
  a.download = 'eduvault_submissions.csv';
  a.click();
}
window.exportCSV = exportCSV;

/* ============================================================
   CANVAS CHARTS
   ============================================================ */
function drawLine(canvasId, datasets, labels) {
  var canvas = document.getElementById(canvasId);
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var W   = canvas.parentElement.offsetWidth - 26 || 500;
  var H   = 180;
  canvas.width = W; canvas.height = H;
  var PAD = { top:16, right:16, bottom:28, left:38 };
  var cW = W - PAD.left - PAD.right;
  var cH = H - PAD.top  - PAD.bottom;
  var allVals = datasets.reduce(function (a, d) { return a.concat(d.data); }, []);
  var max = Math.max.apply(null, allVals) * 1.1 || 1;

  ctx.clearRect(0, 0, W, H);
  for (var g = 0; g <= 4; g++) {
    var gy = PAD.top + (cH / 4) * g;
    ctx.beginPath(); ctx.moveTo(PAD.left, gy); ctx.lineTo(W - PAD.right, gy);
    ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 1; ctx.stroke();
    ctx.fillStyle = 'rgba(136,146,176,0.5)';
    ctx.font = '9px Courier New, monospace'; ctx.textAlign = 'right';
    ctx.fillText(Math.round(max - (max / 4) * g), PAD.left - 4, gy + 3);
  }
  labels.forEach(function (l, i) {
    var x = PAD.left + (cW / (labels.length - 1)) * i;
    ctx.fillStyle = 'rgba(136,146,176,0.6)';
    ctx.font = '9px Courier New, monospace'; ctx.textAlign = 'center';
    ctx.fillText(l, x, H - 4);
  });
  datasets.forEach(function (ds) {
    var pts = ds.data.map(function (v, i) {
      return { x: PAD.left + (cW / (ds.data.length - 1)) * i, y: PAD.top + cH - (v / max) * cH };
    });
    ctx.beginPath();
    pts.forEach(function (p, i) { i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y); });
    ctx.lineTo(pts[pts.length - 1].x, PAD.top + cH);
    ctx.lineTo(pts[0].x, PAD.top + cH);
    ctx.closePath();
    var grad = ctx.createLinearGradient(0, PAD.top, 0, PAD.top + cH);
    grad.addColorStop(0, ds.color.replace(')', ',0.2)').replace('rgb', 'rgba'));
    grad.addColorStop(1, 'transparent');
    ctx.fillStyle = grad; ctx.fill();
    ctx.beginPath();
    pts.forEach(function (p, i) { i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y); });
    ctx.strokeStyle = ds.color; ctx.lineWidth = 2.5;
    ctx.shadowColor = ds.color; ctx.shadowBlur = 7; ctx.stroke(); ctx.shadowBlur = 0;
    pts.forEach(function (p) {
      ctx.beginPath(); ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = ds.color; ctx.shadowColor = ds.color; ctx.shadowBlur = 8; ctx.fill(); ctx.shadowBlur = 0;
    });
  });
}

function drawDonut(canvasId, data, colors) {
  var canvas = document.getElementById(canvasId);
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S = 160; canvas.width = S; canvas.height = S;
  var cx = S/2, cy = S/2, R = S/2 - 12, iR = R * 0.58;
  var total = data.reduce(function (a, b) { return a + b; }, 0) || 1;
  var start = -Math.PI / 2;
  ctx.clearRect(0, 0, S, S);
  data.forEach(function (v, i) {
    var sweep = (v / total) * Math.PI * 2;
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, R, start, start + sweep);
    ctx.closePath(); ctx.fillStyle = colors[i];
    ctx.shadowColor = colors[i]; ctx.shadowBlur = 10; ctx.fill(); ctx.shadowBlur = 0;
    start += sweep;
  });
  ctx.beginPath(); ctx.arc(cx, cy, iR, 0, Math.PI * 2);
  ctx.fillStyle = '#0b0e1a'; ctx.fill();
}

function drawBar(canvasId, datasets, labels) {
  var canvas = document.getElementById(canvasId);
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var W   = canvas.parentElement.offsetWidth - 26 || 500;
  var H   = 200; canvas.width = W; canvas.height = H;
  var PAD = { top:16, right:16, bottom:28, left:42 };
  var cW  = W - PAD.left - PAD.right;
  var cH  = H - PAD.top  - PAD.bottom;
  var allV = datasets.reduce(function (a, d) { return a.concat(d.data); }, []);
  var max  = Math.max.apply(null, allV) * 1.15 || 1;
  var grpW = cW / labels.length;
  var bW   = (grpW - 10) / datasets.length;
  ctx.clearRect(0, 0, W, H);
  for (var g = 0; g <= 4; g++) {
    var gy = PAD.top + (cH / 4) * g;
    ctx.beginPath(); ctx.moveTo(PAD.left, gy); ctx.lineTo(W - PAD.right, gy);
    ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 1; ctx.stroke();
    ctx.fillStyle = 'rgba(136,146,176,0.5)'; ctx.font = '9px Courier New'; ctx.textAlign = 'right';
    ctx.fillText(Math.round(max - (max / 4) * g), PAD.left - 4, gy + 3);
  }
  datasets.forEach(function (ds, di) {
    ds.data.forEach(function (v, i) {
      var bH = (v / max) * cH;
      var x  = PAD.left + i * grpW + di * bW + 5;
      var y  = PAD.top + cH - bH;
      ctx.fillStyle = ds.color; ctx.shadowColor = ds.color; ctx.shadowBlur = 5;
      ctx.beginPath();
      if (ctx.roundRect) ctx.roundRect(x, y, bW - 2, bH, 3); else ctx.rect(x, y, bW - 2, bH);
      ctx.fill(); ctx.shadowBlur = 0;
    });
  });
  labels.forEach(function (l, i) {
    ctx.fillStyle = 'rgba(136,146,176,0.6)'; ctx.font = '9px Courier New'; ctx.textAlign = 'center';
    ctx.fillText(l, PAD.left + i * grpW + grpW / 2, H - 5);
  });
}

function drawRadar(canvasId, datasets) {
  var canvas = document.getElementById(canvasId);
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S   = Math.min(canvas.parentElement.offsetWidth - 26, 260) || 260;
  canvas.width = S; canvas.height = S;
  var cx = S/2, cy = S/2, R = S/2 - 32;
  var axes = datasets[0].data.length;
  var lbls = ['CS','Eng','Biz','Law','Med'];
  function pt(i, v) {
    var a = (Math.PI * 2 / axes) * i - Math.PI / 2;
    return { x: cx + v * R * Math.cos(a), y: cy + v * R * Math.sin(a) };
  }
  ctx.clearRect(0, 0, S, S);
  for (var r = 1; r <= 4; r++) {
    ctx.beginPath();
    for (var i = 0; i < axes; i++) { var p = pt(i, r/4); i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y); }
    ctx.closePath(); ctx.strokeStyle = 'rgba(0,230,200,0.1)'; ctx.lineWidth = 1; ctx.stroke();
  }
  datasets.forEach(function (ds) {
    ctx.beginPath();
    ds.data.forEach(function (v, i) { var p = pt(i, v); i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y); });
    ctx.closePath(); ctx.fillStyle = ds.fill; ctx.fill();
    ctx.strokeStyle = ds.color; ctx.lineWidth = 2; ctx.shadowColor = ds.color; ctx.shadowBlur = 8; ctx.stroke(); ctx.shadowBlur = 0;
  });
  lbls.forEach(function (l, i) {
    var p = pt(i, 1.22); ctx.fillStyle = 'rgba(136,146,176,0.8)';
    ctx.font = '10px Segoe UI, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(l, p.x, p.y);
  });
}

function drawScoreRing(score) {
  var canvas = document.getElementById('scoreRing');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S = 200; canvas.width = S; canvas.height = S;
  var cx = S/2, cy = S/2, R = 78;
  var col = score > 50 ? '#ef4444' : score > 20 ? '#eab308' : '#22c55e';
  var start = -Math.PI/2, end = start + (score/100) * Math.PI * 2;
  ctx.clearRect(0, 0, S, S);
  for (var i = 0; i < 30; i++) {
    var a = (Math.PI*2/30)*i - Math.PI/2;
    ctx.beginPath(); ctx.moveTo(cx+(R+12)*Math.cos(a), cy+(R+12)*Math.sin(a));
    ctx.lineTo(cx+(R+18)*Math.cos(a), cy+(R+18)*Math.sin(a));
    ctx.strokeStyle='rgba(255,255,255,0.07)'; ctx.lineWidth=1; ctx.stroke();
  }
  ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI*2);
  ctx.strokeStyle='rgba(255,255,255,0.06)'; ctx.lineWidth=12; ctx.stroke();
  ctx.beginPath(); ctx.arc(cx, cy, R, start, end);
  ctx.strokeStyle=col; ctx.lineWidth=12; ctx.lineCap='round';
  ctx.shadowColor=col; ctx.shadowBlur=22; ctx.stroke(); ctx.shadowBlur=0;
  var sv = document.getElementById('scoreVal');
  if (sv) { sv.textContent = score; sv.style.color = col; }
}

/* ---- CHART INIT ---- */
var overviewDone = false;
function initOverviewCharts() {
  if (overviewDone) return; overviewDone = true;
  setTimeout(function () {
    drawLine('timelineChart',
      [{ data:[2,4,3,5,6,4,7,8,5,6,9,12], color:'rgb(0,230,200)' },
       { data:[2,3,3,4,5,4,6,7,4,5,8,9],  color:'rgb(34,197,94)' }],
      ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
    drawDonut('riskDonut', [9,2,1], ['#22c55e','#eab308','#ef4444']);
  }, 80);
}

var analyticsDone = false;
function initAnalyticsCharts() {
  if (analyticsDone) return; analyticsDone = true;
  setTimeout(function () {
    drawBar('analyticsBar',
      [{ data:[45,62,58,71,89,95,102,88,110,125,138,145], color:'#00e6c8' },
       { data:[5,8,6,9,11,13,12,10,14,15,17,19],          color:'#ef4444' }],
      ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
    drawRadar('deptRadar',
      [{ data:[0.6,0.4,0.7,0.5,0.45], color:'#00e6c8', fill:'rgba(0,230,200,0.12)' }]);
    drawBar('histChart',
      [{ data:[30,18,22,15,10,5,3], color:'#8b5cf6' }],
      ['0-10','10-20','20-30','30-40','40-50','50-70','70+']);
    drawDonut('pieChart', [45,35,15,5], ['#00e6c8','#8b5cf6','#eab308','#22c55e']);
  }, 80);
}

var simDone = false;
function initSimilarityCharts() {
  /* Reset every time panel opens so fresh data loads */
  simDone = false;
  if (typeof loadMySubmissionsForRadar === 'function') {
    loadMySubmissionsForRadar();
  }
}

function drawRadarMain(data) {
  var canvas = document.getElementById('radarMain');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var S = 260; canvas.width = S; canvas.height = S;
  var cx = S/2, cy = S/2, R = S/2 - 36;
  var axes = data.length, lbls = ['Orig.','Struct.','Cite','Para.','Src.'];
  var dur = 900, st = performance.now();
  function pt(i, v) { var a=(Math.PI*2/axes)*i-Math.PI/2; return{x:cx+v*R*Math.cos(a),y:cy+v*R*Math.sin(a)}; }
  function ease(t) { return 1-Math.pow(1-t,3); }
  (function frame(now) {
    var prog = Math.min((now-st)/dur,1), e = ease(prog);
    ctx.clearRect(0,0,S,S);
    for (var r=1;r<=4;r++){ctx.beginPath();for(var i=0;i<axes;i++){var p=pt(i,r/4);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);}ctx.closePath();ctx.strokeStyle='rgba(0,230,200,0.1)';ctx.lineWidth=1;ctx.stroke();}
    for (var i=0;i<axes;i++){var ep=pt(i,1);ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(ep.x,ep.y);ctx.strokeStyle='rgba(0,230,200,0.15)';ctx.lineWidth=1;ctx.stroke();}
    var base=[0.85,0.40,0.80,0.45,0.72];
    ctx.beginPath();base.forEach(function(v,i){var p=pt(i,v*e);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);});
    ctx.closePath();ctx.fillStyle='rgba(0,230,200,0.06)';ctx.fill();
    ctx.strokeStyle='rgba(0,230,200,0.3)';ctx.lineWidth=1.5;ctx.setLineDash([4,4]);ctx.stroke();ctx.setLineDash([]);
    ctx.beginPath();data.forEach(function(v,i){var p=pt(i,v*e);i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y);});
    ctx.closePath();ctx.fillStyle='rgba(239,68,68,0.14)';ctx.fill();
    ctx.strokeStyle='#ef4444';ctx.lineWidth=2;ctx.shadowColor='#ef4444';ctx.shadowBlur=8;ctx.stroke();ctx.shadowBlur=0;
    data.forEach(function(v,i){var p=pt(i,v*e);ctx.beginPath();ctx.arc(p.x,p.y,3.5,0,Math.PI*2);ctx.fillStyle='#ef4444';ctx.shadowColor='#ef4444';ctx.shadowBlur=10;ctx.fill();ctx.shadowBlur=0;});
    lbls.forEach(function(l,i){var p=pt(i,1.22);ctx.fillStyle='rgba(136,146,176,0.85)';ctx.font='10px Segoe UI,sans-serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(l,p.x,p.y);});
    if (prog<1) requestAnimationFrame(frame);
  })(performance.now());
}

/* ---- INIT ---- */
initOverviewCharts();

window.addEventListener('resize', function () {
  overviewDone = false; analyticsDone = false; simDone = false;
  var active = document.querySelector('.panel.active');
  if (!active) return;
  var name = active.id.replace('panel-', '');
  if (name === 'overview')   initOverviewCharts();
  if (name === 'analytics')  initAnalyticsCharts();
  if (name === 'similarity') initSimilarityCharts();
});