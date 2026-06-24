/* =====================================================
   EduVault – upload.js   FIXED VERSION
   
   BUGS FIXED:
   1. form had onsubmit="handleUpload(event)" — function never defined.
      Removed from HTML; we use addEventListener here instead.
   2. filePreview visibility check (style.display === 'none') was 
      unreliable. Replaced with a boolean flag: fileReady.
   3. fileInput was INSIDE dropZone — clicking the zone triggered 
      click twice (bubbling). Fixed: fileInput moved outside dropZone.
   4. Drag-dropped file was never passed to FormData. Fixed by storing
      it in selectedFile and appending it explicitly to FormData.
   ===================================================== */

(function () {

  /* ---- State ---- */
  var selectedFile = null;   // holds the actual File object
  var fileReady    = false;  // true only when a valid file is staged

  /* ---- Element refs ---- */
  var dropZone, fileInput, filePreview, fileNameEl,
      fileSizeEl, uploadForm, uploadBtn,
      progressWrap, progressFill, progressLbl;

  function init() {
    dropZone     = document.getElementById('dropZone');
    fileInput    = document.getElementById('fileInput');
    filePreview  = document.getElementById('filePreview');
    fileNameEl   = document.getElementById('fileName');
    fileSizeEl   = document.getElementById('fileSize');
    uploadForm   = document.getElementById('uploadForm');
    uploadBtn    = document.getElementById('uploadBtn');
    progressWrap = document.getElementById('progressWrap');
    progressFill = document.getElementById('progressFill');
    progressLbl  = document.getElementById('progressLabel');

    if (!dropZone || !fileInput || !uploadForm) return;
    bindEvents();
  }

  var ALLOWED_EXT = ['pdf', 'doc', 'docx', 'txt'];
  var MAX_BYTES   = 25 * 1024 * 1024;

  function fmtBytes(b) {
    if (b < 1024)    return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
  }

  function getExt(name) {
    return (name.split('.').pop() || '').toLowerCase();
  }

  /* ---- Stage a validated file ---- */
  function stageFile(file) {
    if (!file) return;
    var ext = getExt(file.name);
    if (ALLOWED_EXT.indexOf(ext) === -1) {
      showToast('Invalid file type. Allowed: PDF, DOC, DOCX, TXT', 'error');
      return;
    }
    if (file.size > MAX_BYTES) {
      showToast('File too large. Maximum 25 MB allowed.', 'error');
      return;
    }
    selectedFile = file;
    fileReady    = true;
    fileNameEl.textContent     = file.name;
    fileSizeEl.textContent     = fmtBytes(file.size);
    filePreview.style.display  = 'flex';
    dropZone.style.display     = 'none';
  }

  /* ---- Reset ---- */
  function resetUpload() {
    selectedFile = null;
    fileReady    = false;
    fileInput.value            = '';
    filePreview.style.display  = 'none';
    dropZone.style.display     = 'block';
    if (progressWrap) progressWrap.style.display = 'none';
    if (progressFill) progressFill.style.width   = '0%';
  }
  window.removeFile = resetUpload;

  /* ---- Bind events ---- */
  function bindEvents() {

    /* Click dropZone → open picker (unless user clicked the browse label) */
    dropZone.addEventListener('click', function (e) {
      if (e.target.tagName === 'LABEL' || e.target.tagName === 'INPUT') return;
      fileInput.click();
    });

    /* File chosen via picker */
    fileInput.addEventListener('change', function () {
      if (this.files && this.files[0]) stageFile(this.files[0]);
    });

    /* Drag over highlight */
    ['dragenter', 'dragover'].forEach(function (ev) {
      dropZone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        dropZone.classList.add('drag-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dropZone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        dropZone.classList.remove('drag-over');
      });
    });

    /* File dropped */
    dropZone.addEventListener('drop', function (e) {
      var file = e.dataTransfer && e.dataTransfer.files[0];
      if (file) stageFile(file);
    });

    /* Form submit (listener — not inline onsubmit) */
    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      doUpload();
    });
  }

  /* ---- Core upload function ---- */
  function doUpload() {
    var titleEl  = uploadForm.querySelector('[name=title]');
    var courseEl = uploadForm.querySelector('[name=course]');

    if (!titleEl || !titleEl.value.trim()) {
      showToast('Please enter an assignment title.', 'error'); return;
    }
    if (!courseEl || !courseEl.value.trim()) {
      showToast('Please enter a course code.', 'error'); return;
    }

    /* KEY FIX: use the fileReady flag, not style.display string */
    if (!fileReady || !selectedFile) {
      showToast('Please select a file before submitting.', 'error'); return;
    }

    /* Lock UI */
    uploadBtn.disabled    = true;
    uploadBtn.textContent = 'Uploading...';
    progressWrap.style.display = 'flex';

    /* Animated stages */
    var stages = [
      { pct: 20, msg: 'Uploading file...' },
      { pct: 50, msg: 'Extracting text...' },
      { pct: 75, msg: 'Running similarity engine...' },
      { pct: 92, msg: 'Building radar report...' }
    ];
    var curPct = 0, si = 0;

    function animateTo(target, cb) {
      var t = setInterval(function () {
        if (curPct < target) { curPct++; progressFill.style.width = curPct + '%'; }
        else { clearInterval(t); if (cb) cb(); }
      }, 22);
    }
    function runStage() {
      if (si >= stages.length) return;
      var s = stages[si++];
      progressLbl.textContent = s.msg;
      animateTo(s.pct, function () { if (si < stages.length) setTimeout(runStage, 220); });
    }
    runStage();

    /* Build FormData — append file explicitly with field name "file" */
    var fd = new FormData();
    fd.append('file', selectedFile, selectedFile.name);   // <-- THE CRITICAL FIX
    uploadForm.querySelectorAll('input:not([type=file]), select, textarea').forEach(function (el) {
      if (el.name) fd.append(el.name, el.value);
    });

    /* POST — do NOT set Content-Type header; browser handles multipart boundary */
    fetch('php/upload_assignment.php', { method: 'POST', body: fd })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (res) {
        progressLbl.textContent = res.success ? 'Analysis complete! ✓' : 'Upload failed.';
        var fin = setInterval(function () {
          if (curPct < 100) { curPct++; progressFill.style.width = curPct + '%'; }
          else { clearInterval(fin); setTimeout(function () { handleRes(res); }, 300); }
        }, 12);
      })
      .catch(function (err) {
        console.error('[EduVault Upload]', err);
        showToast('Server error — check XAMPP is running and you are logged in.', 'error');
        unlock();
      });
  }

  function handleRes(res) {
    if (res.success) {
      showToast(
        '✅ Submitted! Similarity: ' + res.similarity_score + '% (' + (res.risk_level || '').toUpperCase() + ' risk)',
        'success'
      );
      unlock();
      resetUpload();
      uploadForm.reset();
      setTimeout(function () {
        if (typeof showPanel    === 'function') showPanel('submissions');
        if (typeof loadSubmissions === 'function') loadSubmissions();
      }, 700);
    } else {
      showToast('Upload failed: ' + (res.message || 'Unknown error'), 'error');
      unlock();
    }
  }

  function unlock() {
    uploadBtn.disabled    = false;
    uploadBtn.textContent = '🚀 Submit & Analyze';
    if (progressWrap) progressWrap.style.display = 'none';
    if (progressFill) progressFill.style.width   = '0%';
  }

  /* Expose init so dashboard.js can re-call it when panel switches */
  window.initUpload = init;

  /* Auto-init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();