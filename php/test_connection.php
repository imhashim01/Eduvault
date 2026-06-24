<?php
/* =====================================================
   EduVault – php/test_connection.php
   Open this page in your browser to verify XAMPP is
   connected correctly:
   http://localhost/EduVault2/php/test_connection.php
   
   DELETE or RENAME this file before going live.
   ===================================================== */

require_once __DIR__ . '/db.php';

$result = testConnection();

$ok      = $result['ok'] ?? false;
$color   = $ok ? '#00e6c8' : '#ff4d6d';
$icon    = $ok ? '✅' : '❌';
$status  = $ok ? 'Connected' : 'Failed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>EduVault – DB Test</title>
  <style>
    body { background:#060810; color:#cdd6f4; font-family:'Segoe UI',sans-serif;
           display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; }
    .card { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
            border-radius:16px; padding:40px 48px; max-width:520px; width:100%; }
    h1  { color:<?= $color ?>; font-size:1.6rem; margin:0 0 24px; }
    .row{ display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,.08);
          padding:10px 0; font-size:.95rem; }
    .label { color:#8b9cc8; }
    .val   { color:#e2e8f0; font-weight:500; }
    .err   { background:rgba(255,77,109,.12); border:1px solid rgba(255,77,109,.3);
             border-radius:8px; padding:14px; margin-top:20px; font-size:.9rem; color:#ff4d6d; }
    .tip   { margin-top:24px; font-size:.85rem; color:#8b9cc8; }
    a      { color:#00e6c8; }
  </style>
</head>
<body>
<div class="card">
  <h1><?= $icon ?> Database <?= $status ?></h1>

  <?php if ($ok): ?>
    <div class="row"><span class="label">Host</span>       <span class="val"><?= htmlspecialchars($result['host']) ?>:<?= htmlspecialchars($result['port']) ?></span></div>
    <div class="row"><span class="label">Database</span>   <span class="val"><?= htmlspecialchars($result['db']) ?></span></div>
    <div class="row"><span class="label">MySQL Version</span><span class="val"><?= htmlspecialchars($result['version']) ?></span></div>
    <div class="row"><span class="label">User</span>        <span class="val"><?= htmlspecialchars(DB_USER) ?></span></div>
    <div class="row"><span class="label">Status</span>      <span class="val" style="color:#00e6c8">✔ Ready</span></div>
    <p class="tip">✅ Connection OK! You can now use EduVault.<br>
       👉 <a href="../index.html">Go to homepage</a> |
          <a href="../login.html">Login</a></p>
  <?php else: ?>
    <div class="err"><strong>Error:</strong> <?= htmlspecialchars($result['error'] ?? 'Unknown error') ?></div>
    <p class="tip">
      <strong>Troubleshooting steps:</strong><br><br>
      1. Open <strong>XAMPP Control Panel</strong> and make sure <strong>MySQL</strong> is <em>Running</em>.<br><br>
      2. Open <a href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a> and create a database
         named <code><?= htmlspecialchars(DB_NAME) ?></code>, then import
         <code>database/eduvault.sql</code>.<br><br>
      3. Edit <code>php/config.php</code> and set the correct
         <code>DB_USER</code> / <code>DB_PASS</code>.<br><br>
      4. Reload this page.
    </p>
  <?php endif; ?>
</div>
</body>
</html>
