<?php
session_start();
include 'config/db.php';

// --- SECURITY: AUTHENTICATION ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// --- HELPER FUNCTIONS FOR STATS ---
function getFolderSize($dir)
{
    $size = 0;
    foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $each) {
        $size += is_file($each) ? filesize($each) : getFolderSize($each);
    }
    return $size;
}
function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getDatabaseSize($conn, $dbname)
{
    $sql = "SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = '$dbname'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['size'] ?? 0;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --- STATS LOGIC ---
$view_user_mode = false;
$upload_size = getFolderSize('uploads');
$db_size = getDatabaseSize($conn, 'quicknote_db'); // Replace with actual DB name from config if dynamic, but hardcoded here or usage of SELECT DATABASE()
$total_size = $upload_size + $db_size;

$disk_usage = formatBytes($total_size);
// Use View for Stats (ADS Requirement)
$stats_res = $conn->query("SELECT * FROM view_dashboard_stats");
$view_data = $stats_res->fetch_assoc();

$stats = [
    'users' => $view_data['total_users'],
    'notes' => $view_data['active_notes'],
    'notebooks' => $view_data['active_notebooks'],
    'storage' => formatBytes($view_data['total_storage_bytes']),
    'db_size' => formatBytes($db_size),
    'file_size' => formatBytes($view_data['total_storage_bytes'])
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | QuickNote</title>
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
            <a href="admin_dashboard.php" class="nav-link active">
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
            <a href="admin_audit.php" class="nav-link">
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
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($username); ?>.</p>
            </div>
        </div>

        <div class="stats-grid">
            <!-- Total Users -->
            <div class="stat-card">
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo $stats['users']; ?></div>
                <svg class="stat-icon" width="64" height="64" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                    </path>
                </svg>
            </div>

            <!-- Active Notes -->
            <div class="stat-card">
                <div class="stat-label">Active Notes</div>
                <div class="stat-value"><?php echo $stats['notes']; ?></div>
                <svg class="stat-icon" width="64" height="64" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
            </div>

            <!-- Total Notebooks -->
            <div class="stat-card">
                <div class="stat-label">Notebooks</div>
                <div class="stat-value"><?php echo $stats['notebooks']; ?></div>
                <svg class="stat-icon" width="64" height="64" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10">
                    </path>
                </svg>
            </div>

            <!-- Storage Used -->
            <div class="stat-card">
                <div class="stat-label">Storage Used</div>
                <div class="stat-value"><?php echo $stats['storage']; ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 5px;">Server Uploads</div>
                <svg class="stat-icon" width="64" height="64" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4">
                    </path>
                </svg>
            </div>
        </div>
    </main>
    <script src="assets/js/webgl-background.js"></script>
</body>

</html>