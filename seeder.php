<?php
// seeder.php
// Generates dummy data for QuickNote to meet ADS requirements (>2000 records)

include 'config/db.php';

// Configuration
$TOTAL_USERS = 50;
$TOTAL_NOTEBOOKS = 150;
$TOTAL_NOTES = 2200; // Target > 2000

echo "<h1>Starting Data Seeder...</h1>";
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
    for ($i = 0; $i < $words; $i++) {
        $text[] = $arr[array_rand($arr)];
    }
    return ucfirst(implode(" ", $text)) . ".";
}

// 1. Create Users
echo "Creating Users... ";
$user_ids = [];
// Get existing
$res = $conn->query("SELECT id FROM users");
while ($r = $res->fetch_assoc())
    $user_ids[] = $r['id'];

for ($i = 0; $i < $TOTAL_USERS; $i++) {
    $uname = "User_" . randStr(6);
    $email = strtolower($uname) . "@example.com";
    $pass = password_hash("password", PASSWORD_DEFAULT);

    // Check duplicate
    $check = $conn->query("SELECT id FROM users WHERE email='$email'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO users (username, email, password) VALUES ('$uname', '$email', '$pass')");
        $user_ids[] = $conn->insert_id;
    }
}
echo "Done. Total Users: " . count($user_ids) . "\n";

// 2. Create Notebooks
echo "Creating Notebooks... ";
$notebook_ids = [];
// Get existing
$res = $conn->query("SELECT id FROM notebooks");
while ($r = $res->fetch_assoc())
    $notebook_ids[] = $r['id'];

for ($i = 0; $i < $TOTAL_NOTEBOOKS; $i++) {
    $uid = $user_ids[array_rand($user_ids)];
    $name = "Notebook " . randStr(5);
    $conn->query("INSERT INTO notebooks (name, user_id, created_by_user) VALUES ('$name', $uid, 'seeder')");
    $notebook_ids[] = $conn->insert_id;
}
echo "Done. Total Notebooks: " . count($notebook_ids) . "\n";

// 3. Create Notes
echo "Creating Notes (Target: $TOTAL_NOTES)... ";
$current_notes = $conn->query("SELECT COUNT(*) as c FROM notes")->fetch_assoc()['c'];
$needed = max(0, $TOTAL_NOTES - $current_notes);

$batch_size = 100;
for ($i = 0; $i < $needed; $i++) {
    $uid = $user_ids[array_rand($user_ids)];

    // 80% chance to be in a notebook
    $nb_id = (rand(1, 100) < 80) ? $notebook_ids[array_rand($notebook_ids)] : "NULL";

    $title = "Note " . randStr(8);
    $content = "<p>" . randText(rand(20, 100)) . "</p>";

    $sql = "INSERT INTO notes (user_id, notebook_id, title, content, created_at) VALUES ($uid, $nb_id, '$title', '$content', NOW() - INTERVAL " . rand(0, 365) . " DAY)";

    if (!$conn->query($sql)) {
        echo "Error: " . $conn->error . "\n";
    }

    if ($i % 200 == 0)
        echo ".";
}
echo "\nDone. \n";

echo "<h2>Seeding Complete!</h2>";
echo "</pre>";
echo "<a href='dashboard.php'>Go to Dashboard</a>";
?>