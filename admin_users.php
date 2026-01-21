<?php
session_start();
include 'config/db.php';

// --- SECURITY: AUTHENTICATION ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// --- SECURITY: CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --- HELPER FUNCTIONS ---
function log_action($conn, $admin_id, $type, $target_id, $details)
{
    if ($stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action_type, target_id, details) VALUES (?, ?, ?, ?)")) {
        $stmt->bind_param("isis", $admin_id, $type, $target_id, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// --- POST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Warning: CSRF Token Validation Failed.");
    }

    if (isset($_POST['update_user_id'])) {
        $uid = intval($_POST['update_user_id']);
        $uname = $_POST['username'];
        $email = $_POST['email'];
        $role = $_POST['role'];

        // 1. Fetch OLD data
        $old_stmt = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
        $old_stmt->bind_param("i", $uid);
        $old_stmt->execute();
        $old_res = $old_stmt->get_result();
        $old_data = $old_res->fetch_assoc();
        $old_stmt->close();

        // Check for duplicates (excluding self)
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username=? OR email=?) AND id != ?");
        $stmt->bind_param("ssi", $uname, $email, $uid);
        $stmt->execute();

        if ($stmt->get_result()->num_rows == 0 && $old_data) {
            $sql = "UPDATE users SET username=?, email=?, role=?";
            $types = "sss";
            $params = [$uname, $email, $role];

            // Track Changes
            $changes = [];
            if ($old_data['username'] !== $uname)
                $changes[] = "Username: '{$old_data['username']}' -> '$uname'";
            if ($old_data['email'] !== $email)
                $changes[] = "Email: '{$old_data['email']}' -> '$email'";
            if ($old_data['role'] !== $role)
                $changes[] = "Role: '{$old_data['role']}' -> '$role'";

            if (!empty($_POST['password'])) {
                $sql .= ", password=?";
                $types .= "s";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $changes[] = "Password updated";
            }
            $sql .= " WHERE id=?";
            $types .= "i";
            $params[] = $uid;

            if (empty($changes)) {
                $log_details = "No changes made to user '$uname'.";
            } else {
                $log_details = "Updated user '$uname': " . implode(", ", $changes);
            }

            $update_stmt = $conn->prepare($sql);
            $update_stmt->bind_param($types, ...$params);
            if ($update_stmt->execute()) {
                log_action($conn, $user_id, 'UPDATE_USER', $uid, $log_details);
            }
        }
        header("Location: admin_users.php");
        exit();
    }

    if (isset($_POST['delete_user_id'])) {
        $del_id = intval($_POST['delete_user_id']);
        if ($del_id != $user_id) {
            $u_stmt = $conn->prepare("SELECT username FROM users WHERE id=?");
            $u_stmt->bind_param("i", $del_id);
            $u_stmt->execute();
            $del_username = ($u_stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown');

            // Clean Files
            $f_stmt = $conn->prepare("SELECT a.file_path FROM attachments a JOIN notes n ON a.note_id = n.id WHERE n.user_id = ?");
            $f_stmt->bind_param("i", $del_id);
            $f_stmt->execute();
            $files_res = $f_stmt->get_result();
            while ($f = $files_res->fetch_assoc())
                if (file_exists($f['file_path']))
                    unlink($f['file_path']);

            // Clean Covers
            $nb_stmt = $conn->prepare("SELECT cover_photo FROM notebooks WHERE user_id = ? AND cover_photo IS NOT NULL");
            $nb_stmt->bind_param("i", $del_id);
            $nb_stmt->execute();
            while ($nb = $nb_stmt->get_result()->fetch_assoc()) {
                if (file_exists($nb['cover_photo']))
                    unlink($nb['cover_photo']);
            }

            $conn->query("DELETE FROM notes WHERE user_id=$del_id");
            if ($conn->query("DELETE FROM users WHERE id=$del_id"))
                log_action($conn, $user_id, 'DELETE_USER', $del_id, "Deleted user '$del_username'");
        }
        header("Location: admin_users.php");
        exit();
    }

    if (isset($_POST['admin_delete_note'])) {
        $nid = intval($_POST['admin_delete_note']);
        $att = $conn->query("SELECT file_path FROM attachments WHERE note_id=$nid");
        while ($a = $att->fetch_assoc())
            if (file_exists($a['file_path']))
                unlink($a['file_path']);
        if ($conn->query("DELETE FROM notes WHERE id=$nid"))
            log_action($conn, $user_id, 'DELETE_NOTE', $nid, "Deleted note #$nid");
        header("Location: admin_users.php?view_user_id=" . $_POST['return_user_id']);
        exit();
    }
    if (isset($_POST['admin_delete_notebook'])) {
        $nbid = intval($_POST['admin_delete_notebook']);
        if ($conn->query("DELETE FROM notebooks WHERE id=$nbid"))
            log_action($conn, $user_id, 'DELETE_NOTEBOOK', $nbid, "Deleted notebook #$nbid");
        header("Location: admin_users.php?view_user_id=" . $_POST['return_user_id']);
        exit();
    }
}

// --- DATA FETCHING ---
$view_user_mode = false;
$target_user = null;
$user_notebooks = [];
$loose_notes = [];

if (isset($_GET['view_user_id'])) {
    $view_user_mode = true;
    $target_uid = intval($_GET['view_user_id']);
    $target_user = $conn->query("SELECT * FROM users WHERE id=$target_uid")->fetch_assoc();
    if ($target_user) {
        $rn = $conn->query("SELECT * FROM notebooks WHERE user_id=$target_uid ORDER BY created_at DESC");
        while ($nb = $rn->fetch_assoc()) {
            $nb['notes'] = [];
            $user_notebooks[$nb['id']] = $nb;
        }
        $rno = $conn->query("SELECT * FROM notes WHERE user_id=$target_uid ORDER BY created_at DESC");
        while ($n = $rno->fetch_assoc()) {
            if ($n['notebook_id'] && isset($user_notebooks[$n['notebook_id']]))
                $user_notebooks[$n['notebook_id']]['notes'][] = $n;
            else
                $loose_notes[] = $n;
        }
    }
} else {
    // Use View (ADS Requirement)
    $users_res = $conn->query("SELECT * FROM view_user_activity ORDER BY created_at DESC");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>User Database | QuickNote Admin</title>
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
            <a href="admin_users.php" class="nav-link active">
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
        <?php if (!$view_user_mode): ?>
            <!-- LIST VIEW -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">User Database</h1>
                    <p class="page-subtitle">Manage registered users and their account permissions.</p>
                </div>
                <div class="search-container">
                    <svg class="search-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" id="userSearchInput" class="search-input" placeholder="Search by name or email...">
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User Profile</th>
                            <th>Role</th>
                            <th>Storage</th>
                            <th>Joined Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $users_res->fetch_assoc()): ?>
                            <tr class="user-row"
                                data-search="<?php echo strtolower($row['username'] . ' ' . $row['email']); ?>">
                                <td style="color: var(--text-muted); font-weight: 600;">#<?php echo $row['id']; ?></td>
                                <td>
                                    <div class="user-cell">
                                        <span class="user-name"><?php echo htmlspecialchars($row['username']); ?></span>
                                        <span class="user-email"><?php echo htmlspecialchars($row['email']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['role'] == 'admin') ? 'badge-admin' : 'badge-user'; ?>">
                                        <span class="badge-dot"></span>
                                        <?php echo strtoupper($row['role']); ?>
                                    </span>
                                </td>
                                <td style="font-family: monospace; color: var(--accent-primary);">
                                    <?php echo $row['storage_used'] ?? '0 B'; ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <!-- View -->
                                        <a href="admin_users.php?view_user_id=<?php echo $row['id']; ?>" class="action-btn"
                                            data-tooltip="View Details">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                                </path>
                                            </svg>
                                        </a>
                                        <!-- Edit -->
                                        <button class="action-btn btn-edit"
                                            onclick="openEdit(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                            data-tooltip="Edit User">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                </path>
                                            </svg>
                                        </button>
                                        <!-- Delete -->
                                        <?php if ($row['role'] != 'admin'): ?>
                                            <form method="POST" style="display:inline;"
                                                onsubmit="return confirm('CRITICAL: Delete this user and all data?');">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="delete_user_id" value="<?php echo $row['id']; ?>">
                                                <button class="action-btn btn-delete" data-tooltip="Delete User">
                                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                        </path>
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- DETAIL VIEW -->
            <div class="page-header">
                <div>
                    <a href="admin_users.php"
                        style="color:var(--text-muted); display:inline-flex; align-items:center; gap:8px; margin-bottom:15px; font-size:0.9rem;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Users
                    </a>
                    <h1 class="page-title">User Details</h1>
                </div>
            </div>

            <?php if (!$target_user): ?>
                <div style="text-align:center; color: var(--text-muted); padding: 50px;">User not found.</div>
            <?php else: ?>
                <div
                    style="background: var(--bg-card); border:1px solid var(--glass-border); padding:30px; border-radius:20px; margin-bottom:40px; display:flex; gap:20px; align-items:center;">
                    <div class="profile-avatar" style="width:80px; height:80px; font-size:2rem; border-radius:20px;">
                        <?php echo strtoupper(substr($target_user['username'], 0, 1)); ?>
                    </div>
                    <div>
                        <h2 style="margin-bottom:5px;"><?php echo htmlspecialchars($target_user['username']); ?></h2>
                        <div style="color:var(--accent-primary); font-family:'Space Mono', monospace;">
                            <?php echo htmlspecialchars($target_user['email']); ?>
                        </div>
                    </div>
                </div>

                <!-- NOTEBOOKS SECTION -->
                <h3 style="margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px;">Notebooks
                </h3>
                <div class="table-container" style="margin-bottom: 50px;">
                    <?php if (empty($user_notebooks)): ?>
                        <div style="padding: 20px; text-align: center; color: var(--text-muted);">No notebooks found.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Notebook Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_notebooks as $nb): ?>
                                    <tr style="background:rgba(255,255,255,0.01);">
                                        <td>
                                            <span class="badge" style="background:rgba(255,255,255,0.1); color:#fff;">NOTEBOOK
                                                #<?php echo $nb['id']; ?></span>
                                        </td>
                                        <td style="font-weight:600; font-size:1.1rem; color:var(--text-primary);">
                                            <?php echo htmlspecialchars($nb['name']); ?>
                                        </td>
                                        <td>
                                            <?php echo $nb['is_trashed'] ? '<span style="color:var(--danger);">Trashed</span>' : '<span style="color:var(--success);">Active</span>'; ?>
                                        </td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Delete Notebook?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="admin_delete_notebook" value="<?php echo $nb['id']; ?>">
                                                <input type="hidden" name="return_user_id" value="<?php echo $target_uid; ?>">
                                                <button class="btn btn-danger"
                                                    style="padding:6px 12px; font-size:0.8rem;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <!-- NOTES INSIDE NOTEBOOK -->
                                    <?php if (!empty($nb['notes'])):
                                        foreach ($nb['notes'] as $n): ?>
                                            <tr>
                                                <td style="text-align:right; color:var(--text-muted); padding-left:40px; font-size:0.85rem;">
                                                    ↳ Note #<?php echo $n['id']; ?>
                                                </td>
                                                <td style="color:var(--text-secondary);">
                                                    <?php echo (!empty($n['title'])) ? htmlspecialchars($n['title']) : '<i>Untitled</i>'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $n['is_trashed'] ? '<span style="color:var(--danger);">Trashed</span>' : '<span style="color:var(--text-muted);">Active</span>'; ?>
                                                </td>
                                                <td>
                                                    <div class="action-group">
                                                        <button class="btn btn-ghost" style="padding:4px 10px; font-size:0.8rem;"
                                                            onclick="viewContent('<?php echo htmlspecialchars(addslashes($n['title'])); ?>', '<?php echo htmlspecialchars(addslashes(strip_tags($n['content']))); ?>')">Peek</button>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete Note?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="admin_delete_note" value="<?php echo $n['id']; ?>">
                                                            <input type="hidden" name="return_user_id" value="<?php echo $target_uid; ?>">
                                                            <button class="btn btn-danger"
                                                                style="padding:4px 10px; font-size:0.8rem;">Del</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- LOOSE NOTES SECTION -->
                <h3 style="margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px;">Loose Notes
                </h3>
                <div class="table-container">
                    <?php if (empty($loose_notes)): ?>
                        <div style="padding: 20px; text-align: center; color: var(--text-muted);">No loose notes found.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loose_notes as $n): ?>
                                    <tr>
                                        <td><span style="color:var(--text-muted); font-size:0.85rem;">[ LOOSE NOTE ]
                                                #<?php echo $n['id']; ?></span></td>
                                        <td style="color:var(--text-secondary);">
                                            <?php echo (!empty($n['title'])) ? htmlspecialchars($n['title']) : '<i>Untitled</i>'; ?>
                                        </td>
                                        <td><?php echo $n['is_trashed'] ? '<span style="color:var(--danger);">Trashed</span>' : '<span style="color:var(--success);">Active</span>'; ?>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-ghost" style="padding:4px 10px; font-size:0.8rem;"
                                                    onclick="viewContent('<?php echo htmlspecialchars(addslashes($n['title'])); ?>', '<?php echo htmlspecialchars(addslashes(strip_tags($n['content']))); ?>')">Peek</button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete Note?');">
                                                    <input type="hidden" name="csrf_token"
                                                        value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="admin_delete_note" value="<?php echo $n['id']; ?>">
                                                    <input type="hidden" name="return_user_id" value="<?php echo $target_uid; ?>">
                                                    <button class="btn btn-danger"
                                                        style="padding:4px 10px; font-size:0.8rem;">Del</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- EDIT MODAL -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit User</h2>
                <p style="color:var(--text-muted);">Update user profile details and permissions.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="update_user_id" id="edit_id">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" id="edit_role" class="form-control" style="background:#111; color:white;">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="color:var(--accent-primary);">New Password (Optional)</label>
                    <input type="password" name="password" class="form-control"
                        placeholder="Leave blank to keep current">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:30px;">
                    <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('editModal').classList.remove('active')">Cancel</button>
                    <button class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PEEK MODAL -->
    <div id="peekModal" class="modal" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal-content">
            <h2 id="peekTitle" class="modal-title" style="color:var(--accent-primary);"></h2>
            <div style="width:100%; height:1px; background:var(--glass-border); margin:15px 0;"></div>
            <div id="peekContent"
                style="color:var(--text-secondary); line-height:1.6; max-height:60vh; overflow-y:auto;"></div>
            <div style="margin-top:20px; text-align:right;">
                <button class="btn btn-ghost"
                    onclick="document.getElementById('peekModal').classList.remove('active')">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openEdit(user) {
            document.getElementById('edit_id').value = user.id;
            document.getElementById('edit_name').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('editModal').classList.add('active');
        }

        function viewContent(title, content) {
            document.getElementById('peekTitle').textContent = title;
            document.getElementById('peekContent').textContent = content || "No content preview available.";
            document.getElementById('peekModal').classList.add('active');
        }

        const search = document.getElementById('userSearchInput');
        if (search) {
            search.addEventListener('keyup', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.user-row').forEach(row => {
                    row.style.display = row.dataset.search.includes(term) ? '' : 'none';
                });
            });
        }
    </script>
    <script src="assets/js/webgl-background.js"></script>
</body>

</html>