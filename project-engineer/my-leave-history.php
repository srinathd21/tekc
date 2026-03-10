<?php
// my-leave-history.php
// Displays the leave history of the logged-in employee along with statistics.

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
$employeeName = (string)($_SESSION['employee_name'] ?? '');

// ---------------- FETCH LEAVE HISTORY ----------------
$leaveHistory = [];
$st = mysqli_prepare($conn, "
  SELECT id, leave_type, from_date, to_date, total_days, reason, status, applied_at
  FROM leave_requests
  WHERE employee_id = ?
  ORDER BY applied_at DESC
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $leaveHistory = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- FETCH LEAVE STATS ----------------
$leaveStats = [
    'total_requests' => 0,
    'total_days' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

foreach ($leaveHistory as $leave) {
    $leaveStats['total_requests']++;
    $leaveStats['total_days'] += $leave['total_days'];

    if ($leave['status'] === 'Pending') {
        $leaveStats['pending']++;
    } elseif ($leave['status'] === 'Approved') {
        $leaveStats['approved']++;
    } elseif ($leave['status'] === 'Rejected') {
        $leaveStats['rejected']++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Leave History - TEK-C</title>

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

    .table-responsive{
      margin-top: 20px;
    }

    .table th, .table td{
      vertical-align: middle;
      text-align: center;
    }

    .badge {
      font-size: 12px;
      padding: 5px 10px;
      border-radius: 20px;
    }
    @media (max-width: 991.98px){
  .main{
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }
  .sidebar{
    position: fixed !important;
    transform: translateX(-100%);
    z-index: 1040 !important;
  }
  .sidebar.open, .sidebar.active, .sidebar.show{
    transform: translateX(0) !important;
  }
}
@media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
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
            <h1 class="h-title">My Leave History</h1>
            <p class="h-sub">View your past leave applications</p>
          </div>
        </div>

        <!-- Leave Stats -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-info-circle"></i></div>
            <div>
              <p class="sec-title mb-0">Leave Statistics</p>
              <p class="sec-sub mb-0">Summary of your leave requests</p>
            </div>
          </div>

          <div class="row">
            <div class="col-md-4">
              <div class="d-flex justify-content-between">
                <span>Total Requests</span>
                <span><?php echo e($leaveStats['total_requests']); ?></span>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between">
                <span>Total Leave Days</span>
                <span><?php echo e($leaveStats['total_days']); ?></span>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between">
                <span>Pending Requests</span>
                <span><?php echo e($leaveStats['pending']); ?></span>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between">
                <span>Approved Requests</span>
                <span><?php echo e($leaveStats['approved']); ?></span>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between">
                <span>Rejected Requests</span>
                <span><?php echo e($leaveStats['rejected']); ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Leave History Table -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Leave History</p>
              <p class="sec-sub mb-0">Your previous leave requests</p>
            </div>
          </div>

          <?php if (empty($leaveHistory)): ?>
            <div class="text-muted" style="font-weight:800;">No leave history found.</div>
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
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($leaveHistory as $leave): ?>
                    <?php
                      $status = $leave['status'];
                      $badge = 'secondary';
                      if ($status === 'Pending') $badge = 'warning text-dark';
                      elseif ($status === 'Approved') $badge = 'success';
                      elseif ($status === 'Rejected') $badge = 'danger';
                    ?>
                    <tr>
                      <td style="font-weight:1000;">#<?php echo (int)$leave['id']; ?></td>
                      <td><?php echo e($leave['leave_type']); ?></td>
                      <td><?php echo e($leave['from_date']); ?></td>
                      <td><?php echo e($leave['to_date']); ?></td>
                      <td><?php echo e($leave['total_days']); ?></td>
                      <td><span class="badge bg-<?php echo e($badge); ?>"><?php echo e($status); ?></span></td>
                      <td><?php echo e($leave['applied_at']); ?></td>
                      <td><?php echo e($leave['reason']); ?></td>
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

</body>
</html>