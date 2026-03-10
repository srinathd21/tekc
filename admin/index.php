<?php
// index.php (DIRECTOR DASHBOARD) — Fully Dynamic (DB-driven)
// Uses tables you already have: sites, clients, employees, dpr_reports
// Optional (auto-detect): alerts, site_workers_daily (or similar)
// - Stats: Active Projects, Upcoming Tasks (DPR Pending Today), On-Site Workers (today), Alerts (open)
// - Ongoing Projects table: from sites (active only)
// - Progress Overview (bar): DPR submissions last 7 days
// - Team Performance (donut): Work progress status split (In Control / Delay / Open Constraints / No DPR)
// - Recent Activity: recent DPR submissions

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (Director) ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)$_SESSION['employee_id'];
$designation = trim((string)($_SESSION['designation'] ?? ''));

$allowed = [
  'Director',
  'Vice President',
  'General Manager',
  'Admin',
  'Administrator',
];
$allowedLower = array_map('strtolower', $allowed);

if (!in_array(strtolower($designation), $allowedLower, true)) {
  header("Location: index.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function hasTable(mysqli $conn, string $table): bool {
  $sql = "SELECT 1
          FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;
  mysqli_stmt_bind_param($st, "s", $table);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return $ok;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;
  mysqli_stmt_bind_param($st, "ss", $table, $column);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return $ok;
}

function projectHealthBadge($start, $end){
  // Simple schedule-based status:
  // - Delayed: end date passed
  // - At Risk: end date within next 7 days
  // - On Track: otherwise
  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Delayed', 'delayed'];
  }
  if ($end !== '' && $end !== '0000-00-00') {
    $d = (strtotime($end) - strtotime($today)) / 86400;
    if ($d >= 0 && $d <= 7) return ['At Risk', 'atrisk'];
  }
  // If not started yet, still show On Track (or you can make it "Upcoming")
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['On Track', 'ontrack'];
  }
  return ['On Track', 'ontrack'];
}

// ---------------- DATES ----------------
$todayYmd = date('Y-m-d');
$from7 = date('Y-m-d', strtotime('-6 days')); // inclusive 7 days window

// ---------------- DIRECTOR METADATA (optional) ----------------
$loggedName = $_SESSION['employee_name'] ?? ($_SESSION['name'] ?? 'User');

// ---------------- ACTIVE PROJECTS ----------------
// "Active" means: expected_completion_date is empty OR >= today
$activeProjects = [];
$st = mysqli_prepare($conn, "
  SELECT
    s.id, s.project_name, s.start_date, s.expected_completion_date,
    c.client_name
  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id
  WHERE (s.expected_completion_date IS NULL
         OR s.expected_completion_date = ''
         OR s.expected_completion_date = '0000-00-00'
         OR s.expected_completion_date >= ?)
  ORDER BY s.created_at DESC
");
if ($st) {
  mysqli_stmt_bind_param($st, "s", $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $activeProjects = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}
$activeCount = count($activeProjects);

// ---------------- UPCOMING TASKS (DPR pending today across active projects) ----------------
$submittedSitesToday = [];
$st = mysqli_prepare($conn, "
  SELECT DISTINCT site_id
  FROM dpr_reports
  WHERE dpr_date = ?
");
if ($st) {
  mysqli_stmt_bind_param($st, "s", $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($r = mysqli_fetch_assoc($res)) {
    $submittedSitesToday[(int)$r['site_id']] = true;
  }
  mysqli_stmt_close($st);
}
$pendingDprToday = 0;
foreach ($activeProjects as $p) {
  $sid = (int)$p['id'];
  if (!isset($submittedSitesToday[$sid])) $pendingDprToday++;
}

// ---------------- ON-SITE WORKERS (optional table auto-detect) ----------------
// If you have a daily attendance table, set it here.
// Example expected schema: site_workers_daily(site_id, work_date, worker_count)
// If not found, fallback to 0.
$onSiteWorkersToday = 0;
$workersTable = null;

// Try common table names (you can add yours)
$candidateWorkerTables = ['site_workers_daily', 'daily_workers', 'site_attendance_daily', 'worker_attendance'];
foreach ($candidateWorkerTables as $t) {
  if (hasTable($conn, $t)) { $workersTable = $t; break; }
}

if ($workersTable) {
  // Try best-effort columns
  $colDate = hasColumn($conn, $workersTable, 'work_date') ? 'work_date' :
             (hasColumn($conn, $workersTable, 'date') ? 'date' : null);
  $colCnt  = hasColumn($conn, $workersTable, 'worker_count') ? 'worker_count' :
             (hasColumn($conn, $workersTable, 'count') ? 'count' : null);

  if ($colDate && $colCnt) {
    $sql = "SELECT SUM($colCnt) AS total FROM $workersTable WHERE $colDate = ?";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
      mysqli_stmt_bind_param($st, "s", $todayYmd);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = mysqli_fetch_assoc($res);
      $onSiteWorkersToday = (int)($row['total'] ?? 0);
      mysqli_stmt_close($st);
    }
  }
}

// ---------------- ALERTS (optional table OR derive from constraints_json in DPR) ----------------
$alertsOpen = 0;

// Preferred: alerts table (status='open')
if (hasTable($conn, 'alerts')) {
  $statusCol = hasColumn($conn, 'alerts', 'status') ? 'status' : null;
  if ($statusCol) {
    $st = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM alerts WHERE $statusCol IN ('open','Open','OPEN')");
    if ($st) {
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = mysqli_fetch_assoc($res);
      $alertsOpen = (int)($row['c'] ?? 0);
      mysqli_stmt_close($st);
    }
  }
} else {
  // Fallback: count "Open" constraints in DPRs from last 7 days
  $st = mysqli_prepare($conn, "
    SELECT constraints_json
    FROM dpr_reports
    WHERE dpr_date BETWEEN ? AND ?
      AND constraints_json IS NOT NULL
      AND constraints_json <> ''
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "ss", $from7, $todayYmd);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
      $json = $row['constraints_json'] ?? '';
      $arr = json_decode($json, true);
      if (!is_array($arr)) continue;
      foreach ($arr as $c) {
        $stt = strtolower(trim((string)($c['status'] ?? '')));
        if ($stt === 'open') $alertsOpen++;
      }
    }
    mysqli_stmt_close($st);
  }
}

// ---------------- Ongoing Projects Table (top 8) ----------------
$ongoingRows = [];
$st = mysqli_prepare($conn, "
  SELECT s.id, s.project_name, s.start_date, s.expected_completion_date
  FROM sites s
  WHERE (s.expected_completion_date IS NULL
         OR s.expected_completion_date = ''
         OR s.expected_completion_date = '0000-00-00'
         OR s.expected_completion_date >= ?)
  ORDER BY s.created_at DESC
  LIMIT 8
");
if ($st) {
  mysqli_stmt_bind_param($st, "s", $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ongoingRows = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- Bar Chart: DPR count per day (last 7 days) ----------------
$barLabels = [];
$barData = [];
for ($i=0; $i<7; $i++) {
  $d = date('Y-m-d', strtotime("-".(6-$i)." days"));
  $barLabels[] = date('D', strtotime($d));
  $barData[$d] = 0;
}

$st = mysqli_prepare($conn, "
  SELECT dpr_date, COUNT(*) AS cnt
  FROM dpr_reports
  WHERE dpr_date BETWEEN ? AND ?
  GROUP BY dpr_date
");
if ($st) {
  mysqli_stmt_bind_param($st, "ss", $from7, $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($r = mysqli_fetch_assoc($res)) {
    $d = $r['dpr_date'];
    if (isset($barData[$d])) $barData[$d] = (int)$r['cnt'];
  }
  mysqli_stmt_close($st);
}
$barValues = array_values($barData);

// ---------------- Donut: Work progress status split (last 7 days) ----------------
// We’ll compute:
// 1) In Control count (from work_progress_json rows with status = "In Control")
// 2) Delay count
// 3) Open constraints count
// 4) No DPR today across active projects
$inControl = 0;
$delay = 0;
$openConstraints = 0;

// Work progress json
$st = mysqli_prepare($conn, "
  SELECT work_progress_json, constraints_json
  FROM dpr_reports
  WHERE dpr_date BETWEEN ? AND ?
");
if ($st) {
  mysqli_stmt_bind_param($st, "ss", $from7, $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    // work progress
    $wp = json_decode($row['work_progress_json'] ?? '', true);
    if (is_array($wp)) {
      foreach ($wp as $t) {
        $stt = strtolower(trim((string)($t['status'] ?? '')));
        if ($stt === 'in control') $inControl++;
        if ($stt === 'delay') $delay++;
      }
    }
    // constraints
    $cs = json_decode($row['constraints_json'] ?? '', true);
    if (is_array($cs)) {
      foreach ($cs as $c) {
        $stt = strtolower(trim((string)($c['status'] ?? '')));
        if ($stt === 'open') $openConstraints++;
      }
    }
  }
  mysqli_stmt_close($st);
}

$noDprToday = $pendingDprToday;

// ---------------- Recent Activity (latest 6 DPRs) ----------------
$recentActivity = [];
$st = mysqli_prepare($conn, "
  SELECT r.created_at, r.dpr_no, s.project_name, e.full_name
  FROM dpr_reports r
  INNER JOIN sites s ON s.id = r.site_id
  INNER JOIN employees e ON e.id = r.employee_id
  ORDER BY r.created_at DESC
  LIMIT 6
");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recentActivity = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TEK-C Dashboard</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

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
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:14px; padding-bottom:14px; }

    .badge-pill{ border-radius:999px; padding:8px 12px; font-weight:900; font-size:12px; border:1px solid transparent; display:inline-flex; align-items:center; gap:8px; }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }
    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .muted-link{ color:#6b7280; font-weight:800; text-decoration:none; }
    .muted-link:hover{ color:#374151; }

    .activity-item{ display:flex; gap:12px; padding:12px 0; border-top:1px solid var(--border); }
    .activity-item:first-child{ border-top:0; padding-top:6px; }
    .activity-avatar{ width:42px; height:42px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; flex:0 0 auto; }
    .activity-title{ font-weight:850; margin:0; color:#1f2937; font-size:14px; }
    .activity-sub{ margin:2px 0 0; color:#6b7280; font-weight:650; font-size:12px; }

    .chart-wrap{ height:190px; }
    .donut-wrap{ height:240px; }

    .legend{ display:flex; flex-wrap:wrap; gap:18px 26px; padding:6px 2px 4px; align-items:center; }
    .legend-item{ display:flex; align-items:center; gap:8px; font-weight:800; color:#374151; }
    .legend-dot{ width:10px; height:10px; border-radius:50%; background:#999; }

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

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                <div>
                  <div class="stat-label">Active Projects</div>
                  <div class="stat-value"><?php echo (int)$activeCount; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Upcoming Tasks</div>
                  <div class="stat-value"><?php echo (int)$pendingDprToday; ?></div>
                  <div class="activity-sub">DPR pending today</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">On-Site Workers</div>
                  <div class="stat-value"><?php echo (int)$onSiteWorkersToday; ?></div>
                  <div class="activity-sub">Today</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Alerts</div>
                  <div class="stat-value"><?php echo (int)$alertsOpen; ?></div>
                  <div class="activity-sub">Open issues</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Middle row -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Ongoing Projects</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="min-width:220px;">Project Name</th>
                        <th style="min-width:140px;">Status</th>
                        <th style="min-width:130px;">Start Date</th>
                        <th style="min-width:130px;">End Date</th>
                        <th class="text-end" style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($ongoingRows)): ?>
                        <tr><td colspan="5" class="text-muted" style="font-weight:800;">No ongoing projects found.</td></tr>
                      <?php else: ?>
                        <?php foreach ($ongoingRows as $p): ?>
                          <?php
                            [$label, $cls] = projectHealthBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');
                          ?>
                          <tr>
                            <td><?php echo e($p['project_name'] ?? ''); ?></td>
                            <td>
                              <span class="badge-pill <?php echo e($cls); ?>">
                                <span class="mini-dot"></span> <?php echo e($label); ?>
                              </span>
                            </td>
                            <td><?php echo e(safeDate($p['start_date'] ?? '')); ?></td>
                            <td><?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?></td>
                            <td class="text-end">
                              <a class="muted-link" href="my-sites.php" title="Open">
                                <i class="bi bi-box-arrow-up-right"></i>
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Progress Overview</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>
                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
                <div class="activity-sub mt-2">DPR submissions (last 7 days)</div>
              </div>
            </div>
          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Recent Activity</h3>
                  <a class="muted-link" href="report.php" style="font-size:12px;">View reports</a>
                </div>

                <?php if (empty($recentActivity)): ?>
                  <div class="text-muted" style="font-weight:800;">No recent activity.</div>
                <?php else: ?>
                  <?php foreach ($recentActivity as $a): ?>
                    <?php
                      $name = $a['full_name'] ?? 'User';
                      $initial = strtoupper(substr(trim($name), 0, 1));
                      $when = !empty($a['created_at']) ? date('d M Y, h:i A', strtotime($a['created_at'])) : '';
                    ?>
                    <div class="activity-item">
                      <div class="activity-avatar"><?php echo e($initial); ?></div>
                      <div class="flex-grow-1">
                        <p class="activity-title mb-0">
                          <?php echo e($name); ?>
                          <span class="text-muted" style="font-weight:700;">submitted</span>
                          <?php echo e($a['dpr_no'] ?? 'DPR'); ?>
                          <span class="text-muted" style="font-weight:700;">for</span>
                          <?php echo e($a['project_name'] ?? 'Project'); ?>
                        </p>
                        <p class="activity-sub"><?php echo e($when); ?></p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>

              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Team Performance</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend">
                  <div class="legend-item"><span class="legend-dot" style="background: var(--yellow);"></span> In Control</div>
                  <div class="legend-item"><span class="legend-dot" style="background: var(--orange);"></span> Delay</div>
                  <div class="legend-item"><span class="legend-dot" style="background: #9ca3af;"></span> Open Constraints</div>
                  <div class="legend-item"><span class="legend-dot" style="background: #6b7280;"></span> No DPR Today</div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>

    </main>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- TEK-C Custom JavaScript -->
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {

      // ===== Charts =====
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.color = "#6b7280";

      // Bar Chart: DPR submissions (last 7 days)
      const barCtx = document.getElementById("barChart");
      if (barCtx) {
        new Chart(barCtx, {
          type: "bar",
          data: {
            labels: <?php echo json_encode($barLabels); ?>,
            datasets: [
              {
                label: "DPR Submissions",
                data: <?php echo json_encode($barValues); ?>,
                backgroundColor: "rgba(107,114,128,.85)",
                borderRadius: 10,
                barThickness: 18
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              x: { grid: { display: false }, ticks: { font: { weight: 700 } } },
              y: { grid: { color: "rgba(233,236,239,1)" }, border: { display: false }, ticks: { stepSize: 5 } }
            }
          }
        });
      }

      // Donut Chart: In Control / Delay / Open Constraints / No DPR Today
      const donutCtx = document.getElementById("donutChart");
      if (donutCtx) {
        new Chart(donutCtx, {
          type: "doughnut",
          data: {
            labels: ["In Control","Delay","Open Constraints","No DPR Today"],
            datasets: [{
              data: <?php echo json_encode([(int)$inControl, (int)$delay, (int)$openConstraints, (int)$noDprToday]); ?>,
              backgroundColor: [
                "rgba(242,201,76,.95)",   // yellow
                "rgba(242,153,74,.95)",   // orange
                "rgba(156,163,175,.95)",  // gray
                "rgba(107,114,128,.95)"   // dark gray
              ],
              borderWidth: 0,
              hoverOffset: 8
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "68%",
            plugins: { legend: { display: false } }
          },
          plugins: [{
            id: "centerText",
            afterDraw(chart){
              const { ctx } = chart;
              const meta = chart.getDatasetMeta(0);
              if(!meta?.data?.length) return;
              const x = meta.data[0].x;
              const y = meta.data[0].y;

              ctx.save();
              ctx.fillStyle = "#374151";
              ctx.textAlign = "center";
              ctx.textBaseline = "middle";

              ctx.font = "800 14px " + Chart.defaults.font.family;
              ctx.fillText("Last 7", x, y - 8);
              ctx.font = "900 14px " + Chart.defaults.font.family;
              ctx.fillText("Days", x, y + 12);
              ctx.restore();
            }
          }]
        });
      }

      // Footer year
      const yearElement = document.getElementById("year");
      if (yearElement) yearElement.textContent = new Date().getFullYear();
    });
  </script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>
