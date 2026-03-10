<?php
// manage-sites.php (TEK-C style like manage-employees.php)
// ✅ Added Team column: Manager + Team Lead + Engineers (Name • Designation)
// ✅ Avoids fatal error if team_lead_employee_id column DOES NOT exist
// ✅ Team Lead display works in 2 ways:
//    1) If sites.team_lead_employee_id exists -> show that employee
//    2) Else -> show Team Lead(s) from assigned engineers where designation = 'Team Lead'
// ✅ Added Soft Delete functionality
// ✅ Added Activity Logging for all actions

session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

// OPTIONAL auth
// if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

// Set current user in session for logging (set these from your login system)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Default admin - replace with actual login
    $_SESSION['user_name'] = 'Admin User';
    $_SESSION['user_role'] = 'Administrator';
}

$success = '';
$error = '';
$sites = [];

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);
  return number_format((float)$v, 2);
}

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function projectStatusBadge($start, $end, $deleted_at = null){
  if ($deleted_at !== null) {
    return ['Deleted', 'status-gray', 'bi-trash'];
  }
  
  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'status-red', 'bi-check2-circle'];
  }
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'status-yellow', 'bi-clock'];
  }
  return ['Ongoing', 'status-green', 'bi-lightning'];
}

// Parses "name|designation||name|designation"
function parseMembersConcat($str){
  $str = trim((string)$str);
  if ($str === '') return [];
  $items = explode('||', $str);
  $out = [];
  foreach ($items as $it){
    $it = trim($it);
    if ($it === '') continue;
    $parts = explode('|', $it);
    $out[] = [
      'name' => trim($parts[0] ?? ''),
      'designation' => trim($parts[1] ?? '')
    ];
  }
  return $out;
}

// ---------- Handle Soft Delete Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  
  // Soft Delete Site
  if ($_POST['action'] === 'soft_delete' && isset($_POST['site_id'])) {
    $site_id = (int)$_POST['site_id'];
    
    // Get site details before delete for logging
    $site_query = mysqli_query($conn, "SELECT project_name, project_code FROM sites WHERE id = $site_id");
    $site_data = mysqli_fetch_assoc($site_query);
    $site_name = $site_data['project_name'] ?? 'Unknown';
    
    $stmt = mysqli_prepare($conn, "UPDATE sites SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1");
    $current_user = $_SESSION['user_id'] ?? 1;
    mysqli_stmt_bind_param($stmt, "ii", $current_user, $site_id);
    
    if (mysqli_stmt_execute($stmt)) {
      $success = "Site moved to trash successfully!";
      
      // Log the soft delete action
      logActivity(
        $conn,
        'SOFT_DELETE',
        'sites',
        "Soft deleted site: $site_name",
        $site_id,
        $site_name,
        json_encode($site_data),
        null
      );
    } else {
      $error = "Error deleting site: " . mysqli_stmt_error($stmt);
    }
    mysqli_stmt_close($stmt);
  }
  
  // Restore Site from trash
  elseif ($_POST['action'] === 'restore' && isset($_POST['site_id'])) {
    $site_id = (int)$_POST['site_id'];
    
    $site_query = mysqli_query($conn, "SELECT project_name, project_code FROM sites WHERE id = $site_id");
    $site_data = mysqli_fetch_assoc($site_query);
    $site_name = $site_data['project_name'] ?? 'Unknown';
    
    $stmt = mysqli_prepare($conn, "UPDATE sites SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $site_id);
    
    if (mysqli_stmt_execute($stmt)) {
      $success = "Site restored successfully!";
      
      logActivity(
        $conn,
        'RESTORE',
        'sites',
        "Restored site: $site_name",
        $site_id,
        $site_name,
        null,
        json_encode(['restored_at' => date('Y-m-d H:i:s')])
      );
    } else {
      $error = "Error restoring site: " . mysqli_stmt_error($stmt);
    }
    mysqli_stmt_close($stmt);
  }
  
  // Permanent Delete
  elseif ($_POST['action'] === 'permanent_delete' && isset($_POST['site_id'])) {
    $site_id = (int)$_POST['site_id'];
    
    $site_query = mysqli_query($conn, "SELECT project_name, project_code, contract_document FROM sites WHERE id = $site_id");
    $site_data = mysqli_fetch_assoc($site_query);
    $site_name = $site_data['project_name'] ?? 'Unknown';
    
    // Delete contract document if exists
    if (!empty($site_data['contract_document']) && file_exists($site_data['contract_document'])) {
      @unlink($site_data['contract_document']);
    }
    
    // Delete from site_project_engineers first (foreign key)
    mysqli_query($conn, "DELETE FROM site_project_engineers WHERE site_id = $site_id");
    
    $stmt = mysqli_prepare($conn, "DELETE FROM sites WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $site_id);
    
    if (mysqli_stmt_execute($stmt)) {
      $success = "Site permanently deleted!";
      
      logActivity(
        $conn,
        'DELETE',
        'sites',
        "Permanently deleted site: $site_name",
        $site_id,
        $site_name,
        json_encode($site_data),
        null
      );
    } else {
      $error = "Error permanently deleting site: " . mysqli_stmt_error($stmt);
    }
    mysqli_stmt_close($stmt);
  }
}

// Get filter for showing trash
$show_trash = isset($_GET['show_trash']) && $_GET['show_trash'] === '1';

// ---------- Detect optional team_lead_employee_id column ----------
$hasTeamLeadCol = false;
$chk = mysqli_query($conn, "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'");
if ($chk) {
  $hasTeamLeadCol = (mysqli_num_rows($chk) > 0);
  mysqli_free_result($chk);
}

// ---------- Fetch sites with client + team (with soft delete filter) ----------
$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";
$teamLeadJoin   = $hasTeamLeadCol
  ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id"
  : "LEFT JOIN employees tl ON 1=0";

$deleted_filter = $show_trash 
  ? "WHERE s.deleted_at IS NOT NULL" 
  : "WHERE s.deleted_at IS NULL";

$sql = "
  SELECT
    s.*,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.client_type,
    c.state AS client_state,

    $teamLeadSelect

    m.full_name   AS manager_name,
    m.designation AS manager_designation,

    tl.full_name   AS team_lead_name,
    tl.designation AS team_lead_designation,

    GROUP_CONCAT(
      DISTINCT CONCAT(
        COALESCE(pe.full_name,''),'|',
        COALESCE(pe.designation,'')
      )
      ORDER BY pe.full_name
      SEPARATOR '||'
    ) AS engineers_concat,
    
    dby.full_name AS deleted_by_name

  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  $teamLeadJoin
  LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
  LEFT JOIN employees pe ON pe.id = spe.employee_id
  LEFT JOIN employees dby ON dby.id = s.deleted_by
  $deleted_filter
  GROUP BY s.id
  ORDER BY 
    CASE WHEN s.deleted_at IS NOT NULL THEN 1 ELSE 0 END,
    s.created_at DESC
";

$res = mysqli_query($conn, $sql);
if ($res) {
  $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_free_result($res);
} else {
  $error = "Error fetching sites: " . mysqli_error($conn);
}

// ---------- Stats (excluding deleted) ----------
$total_sites = 0;
$ongoing = 0; $upcoming = 0; $completed = 0; $deleted = 0;
$today = date('Y-m-d');

foreach ($sites as $s) {
  if (!empty($s['deleted_at'])) {
    $deleted++;
    continue;
  }
  $total_sites++;
  $start = $s['start_date'] ?? '';
  $end   = $s['expected_completion_date'] ?? '';
  if (!empty($end) && $end !== '0000-00-00' && $end < $today) $completed++;
  elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) $upcoming++;
  else $ongoing++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Sites - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-ic.gray{ background: #6b7280; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top; border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }
    .table tr.deleted-row {
      background-color: #fef2f2;
      opacity: 0.8;
    }
    .table tr.deleted-row td {
      color: #6b7280;
    }

    .btn-add {
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-add:hover { background:#2a8bc9; color:#fff; }

    .btn-trash {
      background: #ef4444;
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(239, 68, 68, 0.18);
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-trash:hover { background:#dc2626; color:#fff; }

    .btn-export {
      background: #10b981;
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
      white-space: nowrap;
    }
    .btn-export:hover { background:#0da271; color:#fff; }

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 5px 8px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }

    .btn-action-danger {
      background: transparent;
      border: 1px solid rgba(239, 68, 68, 0.3);
      border-radius: 8px;
      padding: 5px 8px;
      color: #ef4444;
      font-size: 12px;
    }
    .btn-action-danger:hover { background: rgba(239, 68, 68, 0.1); color: #dc2626; }

    .site-title{ font-weight:800; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .site-sub{ font-size:11px; color:#6b7280; font-weight:600; line-height:1.2; }

    .contact-info {
      font-size: 11px;
      color: #6b7280;
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 2px;
      line-height: 1.2;
    }
    .contact-info i{ font-size: 11px; }

    .status-badge{
      padding: 3px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border:1px solid rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border:1px solid rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.22); }
    .status-gray{ background: rgba(107,114,128,.12); color:#6b7280; border:1px solid rgba(107,114,128,.22); }

    /* ✅ TEAM display */
    .team-cell{ display:flex; flex-direction:column; gap:6px; }
    .team-line{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size: 11px; line-height:1.2; }
    .team-tag{
      font-size: 10px;
      font-weight: 900;
      color:#6b7280;
      text-transform: uppercase;
      letter-spacing: .25px;
      white-space:nowrap;
    }
    .name-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding: 4px 8px;
      border-radius: 999px;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      font-weight: 800;
      color:#111827;
      white-space:nowrap;
    }
    .name-pill .desg{ color:#6b7280; font-weight:800; }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }

    th.actions-col, td.actions-col { width: 140px !important; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid maxw">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Manage Sites</h1>
            <p class="text-muted mb-0">View and manage all site/project records</p>
          </div>
          <div class="d-flex gap-2">
            <a href="add-site.php" class="btn-add">
              <i class="bi bi-plus-circle"></i> Add Site
            </a>
            <?php if ($show_trash): ?>
              <a href="manage-sites.php" class="btn-add" style="background:#6b7280;">
                <i class="bi bi-archive"></i> Active Sites
              </a>
            <?php else: ?>
              <a href="manage-sites.php?show_trash=1" class="btn-trash">
                <i class="bi bi-trash"></i> Trash (<?php echo (int)$deleted; ?>)
              </a>
            <?php endif; ?>
            <button class="btn-export" data-bs-toggle="modal" data-bs-target="#exportModal">
              <i class="bi bi-download"></i> Export
            </button>
          </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Stats (only show if not in trash view) -->
        <?php if (!$show_trash): ?>
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-geo-alt-fill"></i></div>
              <div>
                <div class="stat-label">Total Sites</div>
                <div class="stat-value"><?php echo (int)$total_sites; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-lightning-fill"></i></div>
              <div>
                <div class="stat-label">Ongoing</div>
                <div class="stat-value"><?php echo (int)$ongoing; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-clock-fill"></i></div>
              <div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?php echo (int)$upcoming; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo (int)$completed; ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="alert alert-warning mb-3">
          <i class="bi bi-exclamation-triangle me-2"></i>
          You are viewing deleted sites. <a href="manage-sites.php" class="alert-link">View active sites</a>
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title"><?php echo $show_trash ? 'Deleted Sites' : 'Sites Directory'; ?></h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="sitesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Site / Project</th>
                  <th>Client</th>
                  <th>Location / Type</th>
                  <th>Value</th>
                  <th>Start / End</th>
                  <th>Status</th>
                  <th>Team</th>
                  <?php if ($show_trash): ?>
                    <th>Deleted By / Date</th>
                  <?php endif; ?>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>

              <tbody>
              <?php foreach ($sites as $s): ?>
                <?php
                  $is_deleted = !empty($s['deleted_at']);
                  [$stLabel, $stClass, $stIcon] = projectStatusBadge(
                    $s['start_date'] ?? '', 
                    $s['expected_completion_date'] ?? '',
                    $s['deleted_at'] ?? null
                  );

                  $clientName = trim((string)($s['client_name'] ?? ''));
                  $company    = trim((string)($s['company_name'] ?? ''));
                  $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

                  $managerName = trim((string)($s['manager_name'] ?? ''));
                  $managerDesg = trim((string)($s['manager_designation'] ?? ''));

                  $teamLeadName = trim((string)($s['team_lead_name'] ?? ''));
                  $teamLeadDesg = trim((string)($s['team_lead_designation'] ?? ''));

                  $engineers = parseMembersConcat($s['engineers_concat'] ?? '');

                  // Fallback Team Lead(s) from engineer assignments if no TL column or empty
                  $fallbackTeamLeads = [];
                  if ($teamLeadName === '') {
                    foreach ($engineers as $eng) {
                      if (strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) {
                        $fallbackTeamLeads[] = $eng;
                      }
                    }
                  }

                  // Engineers list (exclude Team Lead from fallback mode)
                  $engineerOnly = [];
                  foreach ($engineers as $eng) {
                    if ($teamLeadName === '' && strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) continue;
                    $engineerOnly[] = $eng;
                  }
                  
                  $row_class = $is_deleted ? 'deleted-row' : '';
                ?>
                <tr class="<?php echo $row_class; ?>">
                  <td>
                    <div class="site-title"><?php echo e($s['project_name'] ?? ''); ?></div>
                    <div class="site-sub">
                      <i class="bi bi-file-earmark-text"></i>
                      Agreement: <?php echo e($s['agreement_number'] ?? '—'); ?>
                    </div>
                  </td>

                  <td>
                    <div class="site-title"><?php echo e($clientLine); ?></div>
                    <?php if (!empty($s['client_state'])): ?>
                      <div class="contact-info"><i class="bi bi-geo-alt"></i> <?php echo e($s['client_state']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($s['client_mobile'])): ?>
                      <div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($s['client_mobile']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($s['client_email'])): ?>
                      <div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($s['client_email']); ?></div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <div class="site-title"><?php echo e($s['project_type'] ?? ''); ?></div>
                    <div class="site-sub"><i class="bi bi-pin-map"></i> <?php echo e($s['project_location'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div class="site-title">₹ <?php echo e(showMoney($s['contract_value'] ?? '')); ?></div>
                    <div class="site-sub">PMC: ₹ <?php echo e(showMoney($s['pmc_charges'] ?? '')); ?></div>
                  </td>

                  <td>
                    <div class="site-title">Start: <?php echo e(safeDate($s['start_date'] ?? '')); ?></div>
                    <div class="site-sub">End: <?php echo e(safeDate($s['expected_completion_date'] ?? '')); ?></div>
                  </td>

                  <td>
                    <span class="status-badge <?php echo e($stClass); ?>">
                      <i class="bi <?php echo e($stIcon); ?>"></i>
                      <?php echo e($stLabel); ?>
                    </span>
                  </td>

                  <!-- ✅ TEAM -->
                  <td>
                    <div class="team-cell">
                      <div class="team-line">
                        <?php if ($managerName !== ''): ?>
                          <span class="name-pill">
                            <?php echo e($managerName); ?>
                            <?php if ($managerDesg !== ''): ?><span class="desg">• <?php echo e($managerDesg); ?></span><?php endif; ?>
                          </span>
                        <?php else: ?>
                          <span class="name-pill"><span class="desg">Not assigned</span></span>
                        <?php endif; ?>
                      </div>

                      <div class="team-line">
                        <?php if ($teamLeadName !== ''): ?>
                          <span class="name-pill">
                            <?php echo e($teamLeadName); ?>
                            <?php if ($teamLeadDesg !== ''): ?><span class="desg">• <?php echo e($teamLeadDesg); ?></span><?php endif; ?>
                          </span>
                        <?php elseif (!empty($fallbackTeamLeads)): ?>
                          <?php foreach ($fallbackTeamLeads as $tl): ?>
                            <span class="name-pill">
                              <?php echo e($tl['name']); ?>
                              <?php if (!empty($tl['designation'])): ?><span class="desg">• <?php echo e($tl['designation']); ?></span><?php endif; ?>
                            </span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="name-pill"><span class="desg">Not assigned</span></span>
                        <?php endif; ?>
                      </div>

                      <div class="team-line">
                        <?php if (!empty($engineerOnly)): ?>
                          <?php
                            $maxShow = 3;
                            $count = 0;
                            foreach ($engineerOnly as $eng):
                              $count++;
                              if ($count > $maxShow) break;
                          ?>
                            <span class="name-pill">
                              <?php echo e($eng['name']); ?>
                              <?php if (!empty($eng['designation'])): ?><span class="desg">• <?php echo e($eng['designation']); ?></span><?php endif; ?>
                            </span>
                          <?php endforeach; ?>

                          <?php if (count($engineerOnly) > $maxShow): ?>
                            <span class="name-pill"><span class="desg">+<?php echo (int)(count($engineerOnly) - $maxShow); ?> more</span></span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="name-pill"><span class="desg">None</span></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>

                  <?php if ($show_trash): ?>
                    <td>
                      <div class="site-sub">
                        <i class="bi bi-person"></i> <?php echo e($s['deleted_by_name'] ?? 'Unknown'); ?>
                      </div>
                      <div class="site-sub">
                        <i class="bi bi-clock"></i> <?php echo e(safeDate($s['deleted_at'] ?? '')); ?>
                      </div>
                    </td>
                  <?php endif; ?>

                  <td class="text-end actions-col">
                    <?php if ($is_deleted): ?>
                      <!-- Restore and Permanent Delete buttons for trash view -->
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this site?');">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn-action" title="Restore">
                          <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                      </form>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this site? This action cannot be undone.');">
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn-action-danger" title="Permanent Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php else: ?>
                      <!-- Normal view buttons -->
                      <a href="view-site.php?id=<?php echo (int)$s['id']; ?>" class="btn-action" title="View Site">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a href="edit-site.php?id=<?php echo (int)$s['id']; ?>" class="btn-action" title="Edit Site">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <a href="view-client.php?id=<?php echo (int)$s['client_id']; ?>" class="btn-action" title="View Client">
                        <i class="bi bi-person"></i>
                      </a>
                      <?php if (!empty($s['contract_document'])): ?>
                        <a href="<?php echo e($s['contract_document']); ?>" class="btn-action" target="_blank" rel="noopener" title="Contract">
                          <i class="bi bi-file-earmark-arrow-down"></i>
                        </a>
                      <?php endif; ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Move this site to trash?');">
                        <input type="hidden" name="action" value="soft_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn-action-danger" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Activity Log Link -->
        <div class="text-end mb-3">
          <a href="activity-logs.php?module=sites" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history"></i> View Site Activity Logs
          </a>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Sites</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="export-sites.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Export Format *</label>
              <select class="form-control" name="export_format" required>
                <option value="csv">CSV (Excel)</option>
                <option value="pdf">PDF Document</option>
                <option value="excel">Excel File</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="apply_filters" name="apply_filters" value="1" checked>
                <label class="form-check-label" for="apply_filters">Apply Current Filters</label>
                <div class="form-text">Include current search/filter criteria in export</div>
              </div>
            </div>
            <div class="col-12">
              <div class="alert alert-warning mb-0" role="alert" style="box-shadow:none;">
                <i class="bi bi-info-circle me-2"></i>
                Create <b>export-sites.php</b> if you want export to work.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-export">
            <i class="bi bi-download me-2"></i> Export
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<!-- TEK-C Custom JS -->
<script src="assets/js/sidebar-toggle.js"></script>

<script>
  (function () {
    $(function () {
      $('#sitesTable').DataTable({
        responsive: true,
        autoWidth: false,
        scrollX: false,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [[0, 'asc']],
        columnDefs: [
          { targets: 'actions-col', orderable: false, searchable: false }
        ],
        language: {
          zeroRecords: "No matching sites found",
          info: "Showing _START_ to _END_ of _TOTAL_ sites",
          infoEmpty: "No sites to show",
          lengthMenu: "Show _MENU_",
          search: "Search:"
        }
      });

      setTimeout(function() {
        $('.dataTables_filter input').focus();
      }, 400);
    });
  })();
</script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>