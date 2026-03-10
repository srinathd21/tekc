<?php
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/vendor/autoload.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$employeeId = (int)$_SESSION['employee_id'];

$conn = get_db_connection();
if (!$conn) die("DB Error");

$client = gmailClientForEmployee($conn, $employeeId);

$error = '';
$success = '';
$gmailEmail = '';

$to = '';
$subject = '';
$body = '';

// Prefill from query params (optional)
if (isset($_GET['to'])) $to = trim((string)$_GET['to']);
if (isset($_GET['subject'])) $subject = trim((string)$_GET['subject']);
if (isset($_GET['body'])) $body = trim((string)$_GET['body']);

/**
 * Build RFC 2822 raw email for Gmail API
 */
function build_raw_email(string $from, string $to, string $subject, string $bodyText): string {
  // Basic headers (UTF-8 safe)
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

  $headers = [];
  $headers[] = "From: {$from}";
  $headers[] = "To: {$to}";
  $headers[] = "Subject: {$encodedSubject}";
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "Content-Transfer-Encoding: 8bit";

  return implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
}

if (!$client) {
  $error = "Gmail is not connected. Please connect Gmail first.";
} else {
  try {
    $gmail = new Google\Service\Gmail($client);
    $profile = $gmail->users->getProfile('me');
    $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

// Handle send
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $to = trim((string)($_POST['to'] ?? ''));
  $subject = trim((string)($_POST['subject'] ?? ''));
  $body = trim((string)($_POST['body'] ?? ''));

  if (!$client) {
    $error = "Gmail is not connected. Please connect Gmail first.";
  } elseif ($to === '' || $subject === '' || $body === '') {
    $error = "Please fill To, Subject, and Message.";
  } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $error = "Please enter a valid email address in To.";
  } else {
    try {
      $gmail = new Google\Service\Gmail($client);

      // Ensure we have From email
      if ($gmailEmail === '') {
        $profile = $gmail->users->getProfile('me');
        $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';
      }

      if ($gmailEmail === '') {
        throw new Exception("Unable to detect sender Gmail address.");
      }

      $raw = build_raw_email($gmailEmail, $to, $subject, $body);

      $gMessage = new Google\Service\Gmail\Message();
      // ✅ use gmail_base64url_encode from gmail-client.php
      $gMessage->setRaw(gmail_base64url_encode($raw));

      // Send
      $sent = $gmail->users_messages->send('me', $gMessage);

      $success = "Email sent successfully.";
      $to = $subject = $body = '';
    } catch (Exception $e) {
      $error = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Compose Mail - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    html, body { height: 100%; }
    .app { min-height: 100vh; }
    .main { min-height: 100vh; display: flex; flex-direction: column; }
    .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px; -webkit-overflow-scrolling: touch; }

    @media (max-width: 991.98px){
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
    }
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="h4 fw-bold mb-0">Compose</h1>
            <div class="text-muted">
              <?php if ($client && $gmailEmail): ?>
                Connected as <b><?= htmlspecialchars($gmailEmail) ?></b>
              <?php else: ?>
                Connect your Gmail to send emails
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="mail-inbox.php">
              <i class="bi bi-arrow-left me-1"></i> Back to Inbox
            </a>
            <?php if (!$client): ?>
              <a class="btn btn-primary" href="gmail-connect.php">
                <i class="bi bi-google me-1"></i> Connect Gmail
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-body">
            <form method="post" action="mail-compose.php" autocomplete="off">
              <div class="mb-3">
                <label class="form-label fw-semibold">To</label>
                <input type="email" name="to" class="form-control" placeholder="recipient@example.com"
                       value="<?= htmlspecialchars($to) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Subject</label>
                <input type="text" name="subject" class="form-control" placeholder="Subject"
                       value="<?= htmlspecialchars($subject) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Message</label>
                <textarea name="body" class="form-control" rows="10" placeholder="Write your message..."
                          required><?= htmlspecialchars($body) ?></textarea>
              </div>

              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-send me-1"></i> Send
                </button>
                <a class="btn btn-outline-secondary" href="mail-inbox.php">Cancel</a>
              </div>
            </form>
          </div>
        </div>

        <div class="text-muted small mt-3">
          Note: This sends a plain-text email using Gmail API. Attachments and HTML emails can be added if needed.
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