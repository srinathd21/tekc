<?php
// apply-leave.php
// Calendar-based leave application (multi-date selection)
// - Select date range in calendar (click start/end)
// - Excludes Sundays (configurable)
// - Submits one leave request with selected dates JSON
// - Optional half-day handling per selected date (simple UI)
// - Designed to match TEK-C style + existing includes

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$employeeName = (string)($_SESSION['employee_name'] ?? '');

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ymd($v){
  $v = trim((string)$v);
  if ($v === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $v);
  return ($dt && $dt->format('Y-m-d') === $v) ? $v : '';
}

function dateListInclusive(string $startYmd, string $endYmd): array {
  $out = [];
  $s = DateTime::createFromFormat('Y-m-d', $startYmd);
  $e = DateTime::createFromFormat('Y-m-d', $endYmd);
  if (!$s || !$e) return $out;

  if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }

  $cur = clone $s;
  while ($cur <= $e) {
    $out[] = $cur->format('Y-m-d');
    $cur->modify('+1 day');
  }
  return $out;
}

function isSunday(string $ymd): bool {
  $ts = strtotime($ymd);
  return $ts ? (date('w', $ts) == '0') : false; // 0=Sunday
}

/**
 * OPTIONAL TABLES (expected)
 * leave_requests columns example:
 * id, employee_id, leave_type, from_date, to_date, total_days, reason,
 * selected_dates_json, status, applied_at, created_at
 */

// ---------------- EMPLOYEE INFO ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? $employeeName;

// ---------------- STATE ----------------
$success = '';
$error = '';

// Defaults
$leaveType = 'CL';
$reason = '';
$contactDuringLeave = '';
$handoverTo = '';
$fromDate = '';
$toDate = '';
$selectedDates = [];
$halfDayMap = []; // ['2026-01-12' => 'FH'|'SH']

// Prefill month shown in calendar
$today = new DateTime();
$viewMonth = (int)($_GET['m'] ?? (int)$today->format('n'));
$viewYear  = (int)($_GET['y'] ?? (int)$today->format('Y'));
if ($viewMonth < 1 || $viewMonth > 12) $viewMonth = (int)$today->format('n');
if ($viewYear < 2020 || $viewYear > 2100) $viewYear = (int)$today->format('Y');

// ---------------- HANDLE SUBMIT ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {

  $leaveType = trim((string)($_POST['leave_type'] ?? 'CL'));
  $reason = trim((string)($_POST['reason'] ?? ''));
  $contactDuringLeave = trim((string)($_POST['contact_during_leave'] ?? ''));
  $handoverTo = trim((string)($_POST['handover_to'] ?? ''));
  $fromDate = ymd($_POST['from_date'] ?? '');
  $toDate   = ymd($_POST['to_date'] ?? '');

  $selectedDatesRaw = $_POST['selected_dates'] ?? '[]';
  $decoded = json_decode((string)$selectedDatesRaw, true);
  if (!is_array($decoded)) $decoded = [];

  // sanitize selected dates
  $selectedDates = [];
  foreach ($decoded as $d) {
    $d2 = ymd($d);
    if ($d2 !== '') $selectedDates[] = $d2;
  }
  $selectedDates = array_values(array_unique($selectedDates));
  sort($selectedDates);

  // Half day map (optional)
  $halfDayMapRaw = $_POST['half_day_map'] ?? '{}';
  $decodedHalf = json_decode((string)$halfDayMapRaw, true);
  if (!is_array($decodedHalf)) $decodedHalf = [];
  $halfDayMap = [];
  foreach ($decodedHalf as $k => $v) {
    $k2 = ymd($k);
    $v2 = strtoupper(trim((string)$v));
    if ($k2 !== '' && in_array($v2, ['FH','SH'], true)) {
      $halfDayMap[$k2] = $v2;
    }
  }

  // Validation
  $allowedLeaveTypes = ['CL','SL','EL','LOP','OD','WFH'];
  if (!in_array($leaveType, $allowedLeaveTypes, true)) {
    $error = "Invalid leave type selected.";
  }

  if ($error === '' && $reason === '') {
    $error = "Please enter reason for leave.";
  }

  if ($error === '' && (empty($selectedDates))) {
    $error = "Please select at least one leave date on the calendar.";
  }

  // derive from/to from selected dates if not provided
  if ($error === '') {
    sort($selectedDates);
    $calcFrom = $selectedDates[0];
    $calcTo   = $selectedDates[count($selectedDates)-1];

    if ($fromDate === '') $fromDate = $calcFrom;
    if ($toDate === '')   $toDate = $calcTo;

    // Ensure from/to align with selected range (use selected range as truth)
    $fromDate = $calcFrom;
    $toDate   = $calcTo;
  }

  // Remove Sundays (business rule example) if user somehow selected them
  if ($error === '') {
    $filtered = [];
    foreach ($selectedDates as $d) {
      if (!isSunday($d)) $filtered[] = $d;
    }
    $selectedDates = array_values($filtered);

    if (empty($selectedDates)) {
      $error = "Selected dates contain only Sundays. Please select working days.";
    } else {
      $fromDate = $selectedDates[0];
      $toDate   = $selectedDates[count($selectedDates)-1];
    }
  }

  // Prevent duplicate pending/approved overlap (simple overlap check)
  if ($error === '') {
    $st = mysqli_prepare($conn, "
      SELECT id
      FROM leave_requests
      WHERE employee_id = ?
        AND status IN ('Pending','Approved')
        AND NOT (to_date < ? OR from_date > ?)
      LIMIT 1
    ");
    if ($st) {
      mysqli_stmt_bind_param($st, "iss", $employeeId, $fromDate, $toDate);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $dup = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
      if ($dup) {
        $error = "You already have a pending/approved leave overlapping the selected date(s).";
      }
    }
  }

  // Total days calculation (1 per date, half-day = 0.5)
  $totalDays = 0.0;
  if ($error === '') {
    foreach ($selectedDates as $d) {
      $totalDays += isset($halfDayMap[$d]) ? 0.5 : 1.0;
    }
    if ($totalDays <= 0) $error = "Invalid total leave days.";
  }

  // Save
  if ($error === '') {
    $selectedDatesPayload = [];
    foreach ($selectedDates as $d) {
      $selectedDatesPayload[] = [
        'date' => $d,
        'half_day' => $halfDayMap[$d] ?? null, // FH/SH/null
      ];
    }
    $selectedDatesJson = json_encode($selectedDatesPayload, JSON_UNESCAPED_UNICODE);

    $status = 'Pending';
    $appliedAt = date('Y-m-d H:i:s');

    $ins = mysqli_prepare($conn, "
      INSERT INTO leave_requests
      (employee_id, leave_type, from_date, to_date, total_days, reason, contact_during_leave, handover_to, selected_dates_json, status, applied_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "isssdssssss",
        $employeeId,
        $leaveType,
        $fromDate,
        $toDate,
        $totalDays,
        $reason,
        $contactDuringLeave,
        $handoverTo,
        $selectedDatesJson,
        $status,
        $appliedAt
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to apply leave: " . mysqli_stmt_error($ins);
      } else {
        mysqli_stmt_close($ins);
        header("Location: apply-leave.php?saved=1");
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "Leave applied successfully. Waiting for approval.";
}

// ---------------- RECENT LEAVE REQUESTS ----------------
$recentLeaves = [];
$st = mysqli_prepare($conn, "
  SELECT id, leave_type, from_date, to_date, total_days, status, applied_at
  FROM leave_requests
  WHERE employee_id = ?
  ORDER BY id DESC
  LIMIT 8
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recentLeaves = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- CALENDAR PREP ----------------
$firstDay = DateTime::createFromFormat('Y-n-j', $viewYear . '-' . $viewMonth . '-1');
$daysInMonth = (int)$firstDay->format('t');
$startWeekday = (int)$firstDay->format('w'); // 0 (Sun) - 6 (Sat)

$prev = clone $firstDay; $prev->modify('-1 month');
$next = clone $firstDay; $next->modify('+1 month');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apply Leave - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px 12px 14px; }
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:12px;
      margin-bottom:12px;
    }

    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; line-height:1.1; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding:10px 12px;
      border-radius:14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:10px;
    }
    .sec-ic{
      width:34px; height:34px; border-radius:12px;
      display:grid; place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue, #2d9cdb);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
      font-weight:750;
      font-size:14px;
    }

    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
    @media (max-width: 992px){
      .grid-2, .grid-3{ grid-template-columns:1fr; }
    }

    .btn-primary-tek{
      background: var(--blue, #2d9cdb);
      border:none;
      border-radius:12px;
      padding:10px 16px;
      font-weight:1000;
      display:inline-flex; align-items:center; gap:8px;
      box-shadow:0 12px 26px rgba(45,156,219,.18);
      color:#fff;
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }

    /* -------- Calendar -------- */
    .cal-wrap{
      border:1px solid #e5e7eb;
      border-radius:14px;
      overflow:hidden;
      background:#fff;
    }

    .cal-head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:8px;
      padding:10px 12px;
      border-bottom:1px solid #eef2f7;
      background:#fbfcfe;
    }

    .cal-title{
      font-weight:1000;
      color:#111827;
      margin:0;
    }

    .cal-grid{
      display:grid;
      grid-template-columns: repeat(7, 1fr);
      gap:0;
      border-top:1px solid #eef2f7;
      border-left:1px solid #eef2f7;
    }

    .cal-dow, .cal-cell{
      border-right:1px solid #eef2f7;
      border-bottom:1px solid #eef2f7;
      min-height:64px;
      position:relative;
      background:#fff;
    }

    .cal-dow{
      min-height:38px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:12px;
      font-weight:900;
      color:#6b7280;
      background:#f9fafb;
    }

    .cal-cell.empty{ background:#fafafa; }
    .cal-cell button{
      width:100%;
      height:100%;
      border:none;
      background:transparent;
      text-align:left;
      padding:8px;
      cursor:pointer;
    }

    .cal-day-num{
      font-weight:900;
      color:#111827;
      font-size:13px;
    }

    .cal-cell.today .cal-day-num{
      color: var(--blue, #2d9cdb);
    }

    .cal-cell.sunday{
      background:#fff8f8;
    }

    .cal-cell.disabled button{
      cursor:not-allowed;
      opacity:.55;
    }

    .cal-cell.selected{
      background: rgba(45,156,219,.09);
    }

    .cal-cell.range-start,
    .cal-cell.range-end{
      background: rgba(45,156,219,.18);
      box-shadow: inset 0 0 0 2px rgba(45,156,219,.55);
    }

    .cal-pill{
      position:absolute;
      right:6px; bottom:6px;
      font-size:10px;
      font-weight:900;
      padding:2px 6px;
      border-radius:999px;
      background:#eaf6ff;
      color:#1d6fa5;
    }

    .legend{
      display:flex; flex-wrap:wrap; gap:8px;
      margin-top:10px;
      font-size:12px;
      font-weight:800;
      color:#4b5563;
    }
    .legend .item{
      display:flex; align-items:center; gap:6px;
      border:1px solid #e5e7eb;
      border-radius:999px;
      padding:4px 8px;
      background:#fff;
    }
    .swatch{
      width:14px; height:14px; border-radius:4px; border:1px solid #d1d5db;
      display:inline-block;
    }
    .swatch.sel{ background: rgba(45,156,219,.09); }
    .swatch.edge{ background: rgba(45,156,219,.18); border-color: rgba(45,156,219,.55);}
    .swatch.sun{ background:#fff8f8; }

    .selected-list{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px;
      background:#fff;
      max-height:250px;
      overflow:auto;
    }
    .selected-item{
      display:grid;
      grid-template-columns: 1fr 130px;
      gap:8px;
      align-items:center;
      padding:6px 0;
      border-bottom:1px dashed #eef2f7;
    }
    .selected-item:last-child{ border-bottom:none; }

    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    @media (max-width: 576px){
      .content-scroll{ padding:12px 8px 14px; }
      .panel{ padding:10px; border-radius:14px; }
      .cal-cell, .cal-cell button{ min-height:52px; }
      .cal-cell button{ padding:6px; }
      .cal-day-num{ font-size:12px; }
      .selected-item{ grid-template-columns:1fr; }
    }

    @media (max-width: 991.98px){
      .main{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
      }
      .sidebar{
        position:fixed !important;
        transform:translateX(-100%);
        z-index:1040 !important;
      }
      .sidebar.open, .sidebar.active, .sidebar.show{
        transform:translateX(0) !important;
      }
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

        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">Apply Leave</h1>
            <p class="h-sub">Select dates from calendar and submit leave request</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <form method="POST" id="leaveForm" autocomplete="off">
          <input type="hidden" name="submit_leave" value="1">
          <input type="hidden" name="selected_dates" id="selected_dates_input" value="[]">
          <input type="hidden" name="half_day_map" id="half_day_map_input" value="{}">
          <input type="hidden" name="from_date" id="from_date_input" value="<?php echo e($fromDate); ?>">
          <input type="hidden" name="to_date" id="to_date_input" value="<?php echo e($toDate); ?>">

          <!-- Leave header -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-calendar-plus"></i></div>
              <div>
                <p class="sec-title mb-0">Leave Details</p>
                <p class="sec-sub mb-0">Choose leave type and reason</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                <select class="form-select" name="leave_type" required>
                  <option value="CL" <?php echo ($leaveType==='CL'?'selected':''); ?>>CL (Casual Leave)</option>
                  <option value="SL" <?php echo ($leaveType==='SL'?'selected':''); ?>>SL (Sick Leave)</option>
                  <option value="EL" <?php echo ($leaveType==='EL'?'selected':''); ?>>EL (Earned Leave)</option>
                  <option value="LOP" <?php echo ($leaveType==='LOP'?'selected':''); ?>>LOP (Loss of Pay)</option>
                  <option value="OD" <?php echo ($leaveType==='OD'?'selected':''); ?>>OD (On Duty)</option>
                  <option value="WFH" <?php echo ($leaveType==='WFH'?'selected':''); ?>>WFH</option>
                </select>
              </div>
              <div>
                <label class="form-label">Selected From</label>
                <input class="form-control" id="from_date_view" readonly value="<?php echo e($fromDate); ?>">
              </div>
              <div>
                <label class="form-label">Selected To</label>
                <input class="form-control" id="to_date_view" readonly value="<?php echo e($toDate); ?>">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Contact During Leave</label>
                <input class="form-control" name="contact_during_leave" value="<?php echo e($contactDuringLeave); ?>" placeholder="Mobile number / alternate contact">
              </div>
              <div>
                <label class="form-label">Handover To</label>
                <input class="form-control" name="handover_to" value="<?php echo e($handoverTo); ?>" placeholder="Employee name / team member">
              </div>
            </div>

            <div class="mt-2">
              <label class="form-label">Reason <span class="text-danger">*</span></label>
              <textarea class="form-control" name="reason" rows="3" required placeholder="Enter reason for leave"><?php echo e($reason); ?></textarea>
            </div>
          </div>

          <!-- Calendar -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-calendar3"></i></div>
              <div>
                <p class="sec-title mb-0">Calendar View</p>
                <p class="sec-sub mb-0">Click start date and end date to select range (Sundays disabled)</p>
              </div>
            </div>

            <div class="cal-wrap">
              <div class="cal-head">
                <a class="btn btn-sm btn-outline-secondary" style="border-radius:10px;font-weight:900;"
                   href="?m=<?php echo (int)$prev->format('n'); ?>&y=<?php echo (int)$prev->format('Y'); ?>">
                  <i class="bi bi-chevron-left"></i>
                </a>

                <p class="cal-title mb-0">
                  <?php echo e($firstDay->format('F Y')); ?>
                </p>

                <a class="btn btn-sm btn-outline-secondary" style="border-radius:10px;font-weight:900;"
                   href="?m=<?php echo (int)$next->format('n'); ?>&y=<?php echo (int)$next->format('Y'); ?>">
                  <i class="bi bi-chevron-right"></i>
                </a>
              </div>

              <div class="cal-grid" id="calendarGrid">
                <?php
                  $dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                  foreach ($dows as $dw) {
                    echo '<div class="cal-dow">'.e($dw).'</div>';
                  }

                  // Leading blanks
                  for ($i=0; $i<$startWeekday; $i++) {
                    echo '<div class="cal-cell empty"></div>';
                  }

                  $todayYmd = date('Y-m-d');

                  for ($day=1; $day <= $daysInMonth; $day++) {
                    $d = DateTime::createFromFormat('Y-n-j', $viewYear.'-'.$viewMonth.'-'.$day);
                    $ymd = $d->format('Y-m-d');
                    $weekday = (int)$d->format('w');
                    $isSun = ($weekday === 0);
                    $isPast = ($ymd < $todayYmd); // block past dates (change if needed)

                    $classes = ['cal-cell'];
                    if ($ymd === $todayYmd) $classes[] = 'today';
                    if ($isSun) $classes[] = 'sunday';
                    if ($isSun || $isPast) $classes[] = 'disabled';

                    echo '<div class="'.e(implode(' ', $classes)).'" data-date="'.e($ymd).'" data-disabled="'.(($isSun || $isPast)?'1':'0').'">';
                    echo '  <button type="button" class="cal-btn" '.(($isSun || $isPast)?'disabled':'').' data-date="'.e($ymd).'">';
                    echo '    <div class="cal-day-num">'.(int)$day.'</div>';
                    if ($isSun) {
                      echo '    <span class="cal-pill">Sun</span>';
                    } elseif ($isPast) {
                      echo '    <span class="cal-pill">Past</span>';
                    }
                    echo '  </button>';
                    echo '</div>';
                  }

                  // Trailing blanks to complete grid rows
                  $totalCells = $startWeekday + $daysInMonth;
                  $remaining = (7 - ($totalCells % 7)) % 7;
                  for ($i=0; $i<$remaining; $i++) {
                    echo '<div class="cal-cell empty"></div>';
                  }
                ?>
              </div>
            </div>

            <div class="legend">
              <div class="item"><span class="swatch sel"></span> Selected range</div>
              <div class="item"><span class="swatch edge"></span> Start / End</div>
              <div class="item"><span class="swatch sun"></span> Sunday (disabled)</div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button type="button" class="btn btn-outline-secondary" id="clearSelection" style="border-radius:12px;font-weight:900;">
                <i class="bi bi-eraser"></i> Clear Selection
              </button>
              <span class="badge-pill"><i class="bi bi-calendar-range"></i> Total Selected Days: <span id="selected_count">0</span></span>
              <span class="badge-pill"><i class="bi bi-calculator"></i> Total Leave Days: <span id="total_leave_days">0</span></span>
            </div>
          </div>

          <!-- Selected Dates + Half Day -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-list-check"></i></div>
              <div>
                <p class="sec-title mb-0">Selected Dates</p>
                <p class="sec-sub mb-0">Optionally mark individual date as half-day (FH/SH)</p>
              </div>
            </div>

            <div id="selectedList" class="selected-list">
              <div class="small-muted">No dates selected.</div>
            </div>

            <div class="small-muted mt-2">
              FH = First Half, SH = Second Half. If not selected, date is considered full day.
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek">
                <i class="bi bi-send-check"></i> Submit Leave Request
              </button>
            </div>
          </div>
        </form>

        <!-- Recent Requests -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent Leave Requests</p>
              <p class="sec-sub mb-0">Your latest leave applications</p>
            </div>
          </div>

          <?php if (empty($recentLeaves)): ?>
            <div class="text-muted" style="font-weight:800;">No leave requests found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Total Days</th>
                    <th>Status</th>
                    <th>Applied At</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentLeaves as $r): ?>
                    <?php
                      $status = (string)($r['status'] ?? 'Pending');
                      $badge = 'secondary';
                      if ($status === 'Pending') $badge = 'warning text-dark';
                      elseif ($status === 'Approved') $badge = 'success';
                      elseif ($status === 'Rejected') $badge = 'danger';
                    ?>
                    <tr>
                      <td style="font-weight:1000;">#<?php echo (int)$r['id']; ?></td>
                      <td><?php echo e($r['leave_type']); ?></td>
                      <td><?php echo e($r['from_date']); ?></td>
                      <td><?php echo e($r['to_date']); ?></td>
                      <td><?php echo e($r['total_days']); ?></td>
                      <td><span class="badge bg-<?php echo e($badge); ?>"><?php echo e($status); ?></span></td>
                      <td><?php echo e($r['applied_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const calendarGrid = document.getElementById('calendarGrid');
  const selectedDatesInput = document.getElementById('selected_dates_input');
  const halfDayMapInput = document.getElementById('half_day_map_input');
  const fromDateInput = document.getElementById('from_date_input');
  const toDateInput = document.getElementById('to_date_input');
  const fromDateView = document.getElementById('from_date_view');
  const toDateView = document.getElementById('to_date_view');
  const selectedCountEl = document.getElementById('selected_count');
  const totalLeaveDaysEl = document.getElementById('total_leave_days');
  const selectedList = document.getElementById('selectedList');
  const clearBtn = document.getElementById('clearSelection');

  let rangeStart = null;
  let rangeEnd = null;
  let selectedDates = [];    // full date list after exclusions
  let halfDayMap = {};       // { 'YYYY-MM-DD': 'FH'|'SH' }

  function parseYmd(v){
    if (!v) return null;
    const p = v.split('-');
    if (p.length !== 3) return null;
    const d = new Date(Number(p[0]), Number(p[1])-1, Number(p[2]));
    d.setHours(0,0,0,0);
    return d;
  }

  function fmtYmd(d){
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function dateRangeInclusive(a,b){
    const out = [];
    let start = parseYmd(a), end = parseYmd(b);
    if (!start || !end) return out;
    if (end < start) { const t = start; start = end; end = t; }
    const cur = new Date(start);
    while (cur <= end){
      out.push(fmtYmd(cur));
      cur.setDate(cur.getDate()+1);
    }
    return out;
  }

  function isSunday(ymd){
    const d = parseYmd(ymd);
    return d ? d.getDay() === 0 : false;
  }

  function isCellDisabled(ymd){
    const cell = document.querySelector(`.cal-cell[data-date="${ymd}"]`);
    if (!cell) return true; // if current month cell not visible, treat as disabled in this page
    return cell.dataset.disabled === '1';
  }

  function refreshCalendarStyles(){
    document.querySelectorAll('.cal-cell[data-date]').forEach(cell => {
      cell.classList.remove('selected', 'range-start', 'range-end');
    });

    selectedDates.forEach(d => {
      const cell = document.querySelector(`.cal-cell[data-date="${d}"]`);
      if (cell) cell.classList.add('selected');
    });

    if (rangeStart){
      const startCell = document.querySelector(`.cal-cell[data-date="${rangeStart}"]`);
      if (startCell) startCell.classList.add('range-start');
    }
    if (rangeEnd){
      const endCell = document.querySelector(`.cal-cell[data-date="${rangeEnd}"]`);
      if (endCell) endCell.classList.add('range-end');
    }
  }

  function calcTotalLeaveDays(){
    let total = 0;
    selectedDates.forEach(d => {
      total += (halfDayMap[d] ? 0.5 : 1);
    });
    return total;
  }

  function refreshSelectedList(){
    if (!selectedList) return;

    if (!selectedDates.length){
      selectedList.innerHTML = '<div class="small-muted">No dates selected.</div>';
      return;
    }

    let html = '';
    selectedDates.forEach(d => {
      const half = halfDayMap[d] || '';
      html += `
        <div class="selected-item">
          <div>
            <div style="font-weight:900;color:#111827;">${d}</div>
            <div class="small-muted">${half ? ('Half Day: ' + (half === 'FH' ? 'First Half' : 'Second Half')) : 'Full Day'}</div>
          </div>
          <div>
            <select class="form-select form-select-sm halfday-select" data-date="${d}">
              <option value="" ${half==='' ? 'selected' : ''}>Full Day</option>
              <option value="FH" ${half==='FH' ? 'selected' : ''}>First Half</option>
              <option value="SH" ${half==='SH' ? 'selected' : ''}>Second Half</option>
            </select>
          </div>
        </div>
      `;
    });

    selectedList.innerHTML = html;

    selectedList.querySelectorAll('.halfday-select').forEach(sel => {
      sel.addEventListener('change', function(){
        const d = this.dataset.date;
        const v = (this.value || '').trim();
        if (!v) delete halfDayMap[d];
        else halfDayMap[d] = v;
        syncHiddenInputs();
        refreshCounters();
        refreshSelectedList(); // refresh subtitles
      });
    });
  }

  function refreshCounters(){
    selectedCountEl.textContent = String(selectedDates.length);
    totalLeaveDaysEl.textContent = String(calcTotalLeaveDays());
  }

  function syncHiddenInputs(){
    selectedDatesInput.value = JSON.stringify(selectedDates);
    halfDayMapInput.value = JSON.stringify(halfDayMap);

    const from = selectedDates.length ? selectedDates[0] : '';
    const to   = selectedDates.length ? selectedDates[selectedDates.length - 1] : '';

    fromDateInput.value = from;
    toDateInput.value = to;
    if (fromDateView) fromDateView.value = from;
    if (toDateView) toDateView.value = to;
  }

  function applyRange(start, end){
    let all = dateRangeInclusive(start, end);

    // remove Sundays + disabled cells (past dates / Sundays in visible month)
    all = all.filter(d => !isSunday(d));

    // Keep only visible/current month dates on this page
    all = all.filter(d => {
      const cell = document.querySelector(`.cal-cell[data-date="${d}"]`);
      return !!cell && cell.dataset.disabled !== '1';
    });

    selectedDates = all;

    // cleanup half-day map for deselected dates
    Object.keys(halfDayMap).forEach(k => {
      if (!selectedDates.includes(k)) delete halfDayMap[k];
    });

    // Ensure sorted
    selectedDates.sort();

    syncHiddenInputs();
    refreshCounters();
    refreshSelectedList();
    refreshCalendarStyles();
  }

  function resetSelection(){
    rangeStart = null;
    rangeEnd = null;
    selectedDates = [];
    halfDayMap = {};
    syncHiddenInputs();
    refreshCounters();
    refreshSelectedList();
    refreshCalendarStyles();
  }

  // Click to select range
  document.querySelectorAll('.cal-btn[data-date]').forEach(btn => {
    btn.addEventListener('click', function(){
      const d = this.dataset.date;
      if (!d) return;

      const cell = this.closest('.cal-cell');
      if (!cell || cell.dataset.disabled === '1') return;

      // 1st click => start
      if (!rangeStart || (rangeStart && rangeEnd)) {
        rangeStart = d;
        rangeEnd = null;
        selectedDates = [d];
        if (isSunday(d) || isCellDisabled(d)) {
          selectedDates = [];
        }
        // halfday preserve only if same date remains
        Object.keys(halfDayMap).forEach(k => {
          if (!selectedDates.includes(k)) delete halfDayMap[k];
        });
        syncHiddenInputs();
        refreshCounters();
        refreshSelectedList();
        refreshCalendarStyles();
        return;
      }

      // 2nd click => end
      if (rangeStart && !rangeEnd) {
        rangeEnd = d;
        applyRange(rangeStart, rangeEnd);
        return;
      }
    });
  });

  clearBtn?.addEventListener('click', resetSelection);

  // Restore from server POST state if any (optional simple restore)
  try {
    const postedDates = JSON.parse(selectedDatesInput.value || '[]');
    const postedHalf  = JSON.parse(halfDayMapInput.value || '{}');

    if (Array.isArray(postedDates) && postedDates.length) {
      selectedDates = postedDates.filter(Boolean).sort();
      halfDayMap = (postedHalf && typeof postedHalf === 'object') ? postedHalf : {};

      rangeStart = selectedDates[0] || null;
      rangeEnd   = selectedDates[selectedDates.length - 1] || null;

      syncHiddenInputs();
      refreshCounters();
      refreshSelectedList();
      refreshCalendarStyles();
    } else {
      refreshCounters();
      refreshSelectedList();
      refreshCalendarStyles();
    }
  } catch (e) {
    refreshCounters();
    refreshSelectedList();
    refreshCalendarStyles();
  }

  // Final form validation (client-side)
  const leaveForm = document.getElementById('leaveForm');
  leaveForm?.addEventListener('submit', function(e){
    if (!selectedDates.length) {
      e.preventDefault();
      alert('Please select at least one date in the calendar.');
      return;
    }
  });
});
</script>
</body>
</html>