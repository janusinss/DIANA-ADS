<?php
// seeder.php
// Generates dummy data for QuickNote to meet ADS requirements (>2000 records)
// OPTIMIZED for Performance: Uses Transactions and increased Time Limit

// 1. Performance Settings
set_time_limit(0); // Disable execution time limit
ini_set('memory_limit', '256M'); // Increase memory limit
ob_implicit_flush(true);
if (ob_get_level())
    ob_end_flush();

include 'config/db.php';

// Configuration
$TOTAL_USERS = 1000;
$TOTAL_NOTEBOOKS = 1500;
$TOTAL_NOTES = 2200;

echo "<h1>Starting Data Seeder (Optimized)</h1>";
echo "<pre>";

// --- HELPER: Random Strings ---
function randStr($length = 10)
{
    return substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
}
function randText($words = 20)
{
    $lipsum = "lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat";
    $arr = explode(" ", $lipsum);
    $text = [];
    $count = count($arr);
    for ($i = 0; $i < $words; $i++) {
        $text[] = $arr[rand(0, $count - 1)];
    }
    return ucfirst(implode(" ", $text)) . ".";
}

// 1. Create Users
echo "Creating Users... ";
$conn->begin_transaction(); // START TRANSACTION

$user_ids = [];
$res = $conn->query("SELECT id FROM users");
while ($r = $res->fetch_assoc())
    $user_ids[] = $r['id'];

try {
    // Only create what we need
    $needed = max(0, $TOTAL_USERS - count($user_ids));

    if ($needed > 0) {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $pass = password_hash("password", PASSWORD_DEFAULT);

        for ($i = 0; $i < $needed; $i++) {
            $uname = "User_" . randStr(8) . "_" . $i;
            $email = strtolower($uname) . "@example.com";
            $stmt->bind_param("sss", $uname, $email, $pass);
            $stmt->execute();
            $user_ids[] = $conn->insert_id;

            if ($i % 200 == 0)
                echo ".";
        }
        $stmt->close();
    }

    $conn->commit(); // COMMIT TRANSACTION
    echo " Done. Total Users: " . count($user_ids) . "\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED: " . $e->getMessage();
}


// 2. Create Notebooks
echo "Creating Notebooks... ";
$conn->begin_transaction();

$notebook_ids = [];
$res = $conn->query("SELECT id FROM notebooks");
while ($r = $res->fetch_assoc())
    $notebook_ids[] = (int) $r['id'];

try {
    $current_nb_count = count($notebook_ids);
    $needed = max(0, $TOTAL_NOTEBOOKS - $current_nb_count);

    if ($needed > 0 && !empty($user_ids)) {
        $stmt = $conn->prepare("INSERT INTO notebooks (name, user_id, created_by_user) VALUES (?, ?, 'seeder')");

        for ($i = 0; $i < $needed; $i++) {
            $uid = $user_ids[array_rand($user_ids)];
            $name = "Notebook " . randStr(5);
            $stmt->bind_param("si", $name, $uid);
            $stmt->execute();
            $notebook_ids[] = $conn->insert_id;

            if ($i % 200 == 0)
                echo ".";
        }
        $stmt->close();
    }

    $conn->commit();
    echo " Done. Total Notebooks: " . count($notebook_ids) . "\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED: " . $e->getMessage();
}


// 3. Create Notes
echo "Creating Notes... ";
$conn->begin_transaction();

try {
    $current_notes = $conn->query("SELECT COUNT(*) as c FROM notes")->fetch_assoc()['c'];
    $needed = max(0, $TOTAL_NOTES - $current_notes);

    if ($needed > 0 && !empty($user_ids)) {
        // Use simpler INSERT for speed, skip Stored Procedure overhead for bulk seeding
        $stmt = $conn->prepare("INSERT INTO notes (user_id, notebook_id, title, content, created_at) VALUES (?, ?, ?, ?, NOW())");

        for ($i = 0; $i < $needed; $i++) {
            $uid = $user_ids[array_rand($user_ids)];

            // 80% chance to be in a notebook
            $nb_id = null;
            if (rand(1, 100) < 80 && !empty($notebook_ids)) {
                $nb_id = $notebook_ids[array_rand($notebook_ids)];
            }

            $title = "Note " . randStr(8);
            $content = "<p>" . randText(rand(20, 100)) . "</p>";

            $stmt->bind_param("iiss", $uid, $nb_id, $title, $content);
            $stmt->execute();

            if ($i % 200 == 0)
                echo ".";
        }
        $stmt->close();
    }

    $conn->commit();
    echo " Done.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "FAILED: " . $e->getMessage();
}

echo "<h2>Seeding Complete!</h2>";
echo "</pre>";
echo "<a href='dashboard.php'>Go to Dashboard</a>";
?>