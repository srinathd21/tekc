<!-- Sidebar (MANAGER MENU) — Time Management + Attendance/Leave -->
<aside id="sidebar" class="sidebar" aria-label="Sidebar">
  <div class="brand">
    <div class="brand-badge p-0">
      <img src="assets/tek-c.png" alt="TEK-C" />
    </div>
    <div class="brand-title">TEK-C</div>
  </div>

  <div class="nav-section">

    <!-- Dashboard -->
    <a class="side-link" href="index.php">
      <i class="bi bi-grid-1x2"></i><span class="label">Dashboard</span>
    </a>

    <!-- My Sites -->
    <a class="side-link" href="my-sites.php">
      <i class="bi bi-geo-alt"></i><span class="label">My Sites</span>
    </a>

    <!-- Today Task -->
    <a class="side-link" href="today-tasks.php">
      <i class="bi bi-check2-square"></i><span class="label">Today Task</span>
    </a>

    <!-- Attendance quick -->
    <a class="side-link" href="punchin.php">
      <i class="bi bi-fingerprint"></i><span class="label">Attendance</span>
    </a>

    <!-- ✅ Mail -->
    <button class="side-link side-toggle" type="button"
            id="mailToggle"
            aria-expanded="false"
            aria-controls="mailMenu"
            title="Mail">
      <i class="bi bi-envelope"></i>
      <span class="label">Mail</span>
      <i class="bi bi-chevron-down chevron ms-auto"></i>
    </button>

    <div class="side-submenu" id="mailMenu" hidden>
      <a class="side-sublink" href="mail-inbox.php">
        <i class="bi bi-inbox"></i><span class="label">Inbox</span>
      </a>
      <a class="side-sublink" href="mail-compose.php">
        <i class="bi bi-pencil-square"></i><span class="label">Compose</span>
      </a>
      <a class="side-sublink" href="mail-sent.php">
        <i class="bi bi-send"></i><span class="label">Sent</span>
      </a>
      <a class="side-sublink" href="mail-trash.php">
        <i class="bi bi-trash"></i><span class="label">Trash</span>
      </a>
    </div>

    <!-- ✅ Time Management -->
    <button class="side-link side-toggle" type="button"
            id="tmToggle"
            aria-expanded="false"
            aria-controls="tmMenu"
            title="Time Management">
      <i class="bi bi-clock-history"></i>
      <span class="label">Time Management</span>
      <i class="bi bi-chevron-down chevron ms-auto"></i>
    </button>

    <div class="side-submenu" id="tmMenu" hidden>
      <a class="side-sublink" href="dpr.php">
        <i class="bi bi-journal-text"></i><span class="label">DPR</span>
      </a>
      <a class="side-sublink" href="dar.php">
        <i class="bi bi-clipboard-check"></i><span class="label">DAR</span>
      </a>
      <a class="side-sublink" href="ma.php">
        <i class="bi bi-calendar2-week"></i><span class="label">MA</span>
      </a>
      <a class="side-sublink" href="mpt.php">
        <i class="bi bi-list-task"></i><span class="label">MPT</span>
      </a>
      <a class="side-sublink" href="mom.php">
        <i class="bi bi-chat-left-text"></i><span class="label">MOM</span>
      </a>
      <a class="side-sublink" href="mom-short.php">
        <i class="bi bi-chat-left-text"></i><span class="label">MOM (Short-term)</span>
      </a>
      <a class="side-sublink" href="rfi.php">
        <i class="bi bi-question-circle"></i><span class="label">RFI</span>
      </a>
      <a class="side-sublink" href="checklist.php">
        <i class="bi bi-card-checklist"></i><span class="label">Checklist</span>
      </a>
    </div>

    <!-- ✅ Attendance / Leave -->
    <button class="side-link side-toggle" type="button"
            id="alToggle"
            aria-expanded="false"
            aria-controls="alMenu"
            title="Attendance / Leave">
      <i class="bi bi-person-workspace"></i>
      <span class="label">Attendance / Leave</span>
      <i class="bi bi-chevron-down chevron ms-auto"></i>
    </button>

    <div class="side-submenu" id="alMenu" hidden>

      <a class="side-sublink" href="apply-leave.php">
        <i class="bi bi-calendar-plus"></i><span class="label">Apply Leave</span>
      </a>
      <a class="side-sublink" href="my-leave-history.php">
        <i class="bi bi-clock-history"></i><span class="label">My Leave History</span>
      </a>
      <a class="side-sublink" href="attendance-regularization.php">
        <i class="bi bi-pencil-square"></i><span class="label">Attendance Regularization</span>
      </a>
      <a class="side-sublink" href="payslips.php">
        <i class="bi bi-receipt"></i><span class="label">Payslips</span>
      </a>
    </div>

    <!-- My Profile -->
    <a class="side-link" href="my-profile.php">
      <i class="bi bi-person-circle"></i><span class="label">My Profile</span>
    </a>

    <!-- Report -->
    <a class="side-link" href="report.php">
      <i class="bi bi-file-earmark-text"></i><span class="label">Report</span>
    </a>

    <!-- Logout -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i><span class="label">Logout</span>
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • v1.0</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<style>
  /* ---------- Shared submenu styles ---------- */

  .side-toggle{
    width:100%;
    background:transparent;
    border:none;
    text-align:left;
    display:flex;
    align-items:center;
    gap:.6rem;
    cursor:pointer;
  }

  .side-toggle .chevron{
    transition: transform .2s ease;
    margin-left: auto;
  }

  .side-toggle[aria-expanded="true"] .chevron{
    transform: rotate(180deg);
  }

  .side-submenu{
    margin: 6px 0 10px;
    padding-left: 38px;
    display:flex;
    flex-direction:column;
    gap:6px;
  }

  .side-sublink{
    display:flex;
    align-items:center;
    gap:.6rem;
    padding: 8px 10px;
    border-radius: 10px;
    text-decoration:none;
    color: inherit;
    font-weight: 800;
    opacity:.95;
  }

  .side-sublink:hover{
    background: rgba(0,0,0,.05);
  }

  .side-sublink.active{
    background: rgba(45,156,219,.12);
    color: var(--blue, #2d9cdb);
  }

  /* Sidebar positioning for flyouts */
  #sidebar{
    position: relative;
  }

  /* Collapsed flyout for ANY submenu */
  #sidebar.collapsed .side-submenu{
    position: absolute;
    left: calc(100% + 10px);
    width: 245px;

    padding: 10px;
    margin: 0;

    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    box-shadow: 0 18px 40px rgba(17,24,39,.15);
    z-index: 9999;
  }

  /* Individual flyout top positions */
  #sidebar.collapsed #mailMenu{ top: var(--mail-top, 60px); }
  #sidebar.collapsed #tmMenu{ top: var(--tm-top, 110px); }
  #sidebar.collapsed #alMenu{ top: var(--al-top, 160px); }

  #sidebar.collapsed .side-submenu .label{
    display: inline !important;
  }

  #sidebar.collapsed .side-sublink{
    padding: 10px 10px;
    border-radius: 12px;
  }

  /* Optional: hide chevron when sidebar is collapsed */
  #sidebar.collapsed .side-toggle .chevron{
    display: none;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';
  const sidebar = document.getElementById('sidebar');

  const mailToggle = document.getElementById('mailToggle');
  const mailMenu   = document.getElementById('mailMenu');

  const tmToggle   = document.getElementById('tmToggle');
  const tmMenu     = document.getElementById('tmMenu');

  const alToggle   = document.getElementById('alToggle');
  const alMenu     = document.getElementById('alMenu');

  // Pages list for auto-open / active highlight
  const mailPages = ['mail-inbox.php','mail-compose.php','mail-sent.php','mail-trash.php'];
  const tmPages   = ['dpr.php','dar.php','ma.php','mpt.php','mom.php','mom-short.php','rfi.php','checklist.php'];
  const alPages   = ['punchin.php','apply-leave.php','my-leave-history.php','attendance-regularization.php','payslips.php'];

  function isCollapsed(){
    return sidebar && sidebar.classList.contains('collapsed');
  }

  function setFlyoutTop(toggleEl, cssVarName){
    if (!sidebar || !toggleEl) return;
    sidebar.style.setProperty(cssVarName, toggleEl.offsetTop + 'px');
  }

  // Core open/close function (does NOT auto-close others by itself)
  function setMenu(toggleEl, menuEl, storageKey, open, cssVarName){
    if (!toggleEl || !menuEl) return;

    if (open && isCollapsed() && cssVarName) {
      setFlyoutTop(toggleEl, cssVarName);
    }

    toggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuEl.hidden = !open;

    try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch(e){}
  }

  function getMenuOpen(toggleEl){
    return toggleEl && toggleEl.getAttribute('aria-expanded') === 'true';
  }

  // ✅ NEW: accordion behavior (open one => close others)
  const menuDefs = [
    { key:'mail_open', toggle: mailToggle, menu: mailMenu, cssVar:'--mail-top' },
    { key:'tm_open',   toggle: tmToggle,   menu: tmMenu,   cssVar:'--tm-top' },
    { key:'al_open',   toggle: alToggle,   menu: alMenu,   cssVar:'--al-top' },
  ];

  function closeAllExcept(exceptKey){
    menuDefs.forEach(def => {
      if (!def.toggle || !def.menu) return;
      if (def.key === exceptKey) return;
      setMenu(def.toggle, def.menu, def.key, false, def.cssVar);
    });
  }

  function openExclusive(def){
    if (!def.toggle || !def.menu) return;
    closeAllExcept(def.key);
    setMenu(def.toggle, def.menu, def.key, true, def.cssVar);
  }

  // Highlight top-level links (excluding toggle buttons)
  const topLinks = document.querySelectorAll('.side-link:not(.side-toggle)');
  topLinks.forEach(link => link.classList.remove('active'));
  topLinks.forEach(link => {
    const href = link.getAttribute('href');
    if (href && href === currentPage) link.classList.add('active');
  });

  // Highlight all submenu links
  const subLinks = document.querySelectorAll('.side-sublink');
  subLinks.forEach(a => a.classList.remove('active'));

  let hasActiveMail = false;
  let hasActiveTm = false;
  let hasActiveAl = false;

  subLinks.forEach(a => {
    const href = a.getAttribute('href');
    if (!href) return;
    if (href === currentPage) {
      a.classList.add('active');
      if (mailPages.includes(href)) hasActiveMail = true;
      if (tmPages.includes(href)) hasActiveTm = true;
      if (alPages.includes(href)) hasActiveAl = true;
    }
  });

  // Initial state:
  // - If current page belongs to a menu => open that menu, close others.
  // - Else, restore last opened from localStorage (but keep accordion: only one open).
  let openKey = null;

  if (hasActiveMail) openKey = 'mail_open';
  else if (hasActiveTm) openKey = 'tm_open';
  else if (hasActiveAl) openKey = 'al_open';

  if (!openKey) {
    // restore one open from storage (priority order)
    try {
      if (localStorage.getItem('mail_open') === '1') openKey = 'mail_open';
      else if (localStorage.getItem('tm_open') === '1') openKey = 'tm_open';
      else if (localStorage.getItem('al_open') === '1') openKey = 'al_open';
    } catch(e){}
  }

  // Apply initial accordion state
  menuDefs.forEach(def => {
    const shouldOpen = (def.key === openKey);
    setMenu(def.toggle, def.menu, def.key, shouldOpen, def.cssVar);
  });

  // Toggle handlers (accordion)
  menuDefs.forEach(def => {
    if (!def.toggle) return;
    def.toggle.addEventListener('click', function(e){
      e.preventDefault();
      const nextOpen = !getMenuOpen(def.toggle);
      if (nextOpen) {
        openExclusive(def);          // ✅ open clicked, close others
      } else {
        setMenu(def.toggle, def.menu, def.key, false, def.cssVar);
      }
    });
  });

  // Close flyouts on outside click (collapsed mode only)
  document.addEventListener('click', function(e){
    if (!isCollapsed()) return;

    menuDefs.forEach(def => {
      if (!def.toggle || !def.menu) return;
      if (!getMenuOpen(def.toggle)) return;

      const inside = def.toggle.contains(e.target) || def.menu.contains(e.target);
      if (!inside) setMenu(def.toggle, def.menu, def.key, false, def.cssVar);
    });
  });

  // Update flyout position on resize
  window.addEventListener('resize', function(){
    if (!isCollapsed()) return;
    menuDefs.forEach(def => {
      if (!def.toggle) return;
      if (getMenuOpen(def.toggle)) setFlyoutTop(def.toggle, def.cssVar);
    });
  });

  // Update flyout position when sidebar collapsed state changes
  const observer = new MutationObserver(function() {
    if (!isCollapsed()) return;
    menuDefs.forEach(def => {
      if (!def.toggle) return;
      if (getMenuOpen(def.toggle)) setFlyoutTop(def.toggle, def.cssVar);
    });
  });

  if (sidebar) {
    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
  }

  // Confirm before logout
  const logoutLink = document.getElementById('logoutLink');
  if (logoutLink) {
    logoutLink.addEventListener('click', function(e) {
      const ok = confirm('Are you sure you want to logout?');
      if (!ok) e.preventDefault();
    });
  }
});
</script>