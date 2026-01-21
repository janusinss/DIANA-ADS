<?php
session_start();
include 'config/db.php';

// --- SECURITY: AUTHENTICATION ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --- DATA FETCHING ---
$logs_res = $conn->query("SELECT l.*, u.username as admin_name FROM admin_logs l LEFT JOIN users u ON l.admin_id = u.id ORDER BY l.created_at DESC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Audit Trails | QuickNote Admin</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2300d26a'%3E%3Ccircle cx='12' cy='12' r='12'/%3E%3C/svg%3E">
</head>

<body>
    <!-- SHADER BG -->
    <canvas id="glCanvas"></canvas>

    <!-- SIDEBAR -->
    <aside>
        <div class="brand">
            <div class="brand-dot"></div>
            QuickNote
        </div>

        <div class="nav-label">Main Menu</div>

        <nav>
            <a href="admin_dashboard.php" class="nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                    </path>
                </svg>
                Dashboard
            </a>
            <a href="admin_users.php" class="nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                    </path>
                </svg>
                User Database
            </a>
            <a href="admin_audit.php" class="nav-link active">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Audit Trails
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="dashboard.php" class="nav-link"
                style="margin-bottom: 20px; justify-content: center; background: rgba(255,255,255,0.03);">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                    style="width:20px; height:20px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Return to App
            </a>
            <div class="admin-profile">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($username, 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($username); ?></div>
                    <div class="profile-role">Super Admin</div>
                </div>
                <a href="logout.php" class="logout-btn" title="Log Out">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                        </path>
                    </svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main>
        <div class="page-header">
            <div>
                <h1 class="page-title">Audit Logs</h1>
                <p class="page-subtitle">Recent administrative actions and security events.</p>
            </div>
        </div>

        <div class="table-container">
            <?php if ($logs_res && $logs_res->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 20%;">Time</th>
                            <th style="width: 25%;">User</th>
                            <th style="width: 25%;">Action</th>
                            <th style="width: 30%;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($log = $logs_res->fetch_assoc()):
                            // Determine icon and class based on action
                            $action_class = 'action-default';
                            $icon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';

                            $type = strtolower($log['action_type']);
                            if (strpos($type, 'login') !== false) {
                                $action_class = 'action-login';
                                $icon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>';
                            } elseif (strpos($type, 'delete') !== false || strpos($type, 'remove') !== false) {
                                $action_class = 'action-delete';
                                $icon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
                            } elseif (strpos($type, 'create') !== false || strpos($type, 'add') !== false || strpos($type, 'register') !== false) {
                                $action_class = 'action-create';
                                $icon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>';
                            } elseif (strpos($type, 'update') !== false || strpos($type, 'edit') !== false) {
                                $action_class = 'action-update';
                                $icon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>';
                            }
                            ?>
                            <tr class="audit-row">
                                <td>
                                    <div class="audit-time">
                                        <?php echo date('H:i', strtotime($log['created_at'])); ?> <span
                                            style="opacity:0.5; font-size:0.8em; margin-left:4px;"><?php echo date('M d', strtotime($log['created_at'])); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="audit-admin">
                                        <div class="audit-avatar">
                                            <?php echo strtoupper(substr($log['admin_name'], 0, 1)); ?>
                                        </div>
                                        <span style="font-weight: 500; color: var(--text-primary);">
                                            <?php echo htmlspecialchars($log['admin_name']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="audit-action <?php echo $action_class; ?>">
                                        <div class="audit-action-icon">
                                            <?php echo $icon; ?>
                                        </div>
                                        <span><?php echo $log['action_type']; ?></span>
                                    </div>
                                </td>
                                <td style="color:var(--text-secondary); line-height: 1.4;">
                                    <?php echo htmlspecialchars($log['details']); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                            </path>
                        </svg>
                    </div>
                    <h3 class="empty-title">Unknown Void</h3>
                    <p class="empty-subtitle">It seems quiet here. No administrative actions have been recorded yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script src="assets/js/webgl-background.js"></script>
</body>

</html>