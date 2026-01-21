<?php
// setup_advanced_db.php (Final Fix)
// Directly defines SQL commands in PHP to avoid parsing errors.

include 'config/db.php';
$conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

echo "<h1>Applying Advanced SQL Features (Direct Mode)</h1>";

// 1. Preparation
$sql_prep = "
SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE (table_name = 'attachments')
      AND (table_schema = DATABASE())
      AND (column_name = 'file_size')
  ) > 0,
  'SELECT 1',
  'ALTER TABLE attachments ADD COLUMN file_size INT DEFAULT 0'
) INTO @stmt_sql;
PREPARE alterIfNotExists FROM @stmt_sql;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
";

// 2. Views
$sql_view_stats = "
CREATE OR REPLACE VIEW view_dashboard_stats AS
SELECT
    (SELECT COUNT(*) FROM users) AS total_users,
    (SELECT COUNT(*) FROM notes WHERE is_trashed = 0) AS active_notes,
    (SELECT COUNT(*) FROM notebooks WHERE is_trashed = 0) AS active_notebooks,
    (SELECT IFNULL(SUM(file_size), 0) FROM attachments) AS total_storage_bytes;
";

$sql_func_storage = "
CREATE FUNCTION fn_get_user_storage_usage(p_user_id INT) 
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    DECLARE v_bytes BIGINT;
    DECLARE v_result VARCHAR(50);
    
    SELECT IFNULL(SUM(file_size), 0) INTO v_bytes
    FROM attachments a
    JOIN notes n ON a.note_id = n.id
    WHERE n.user_id = p_user_id;
    
    IF v_bytes < 1024 THEN
        SET v_result = CONCAT(v_bytes, ' B');
    ELSEIF v_bytes < 1048576 THEN
        SET v_result = CONCAT(ROUND(v_bytes / 1024, 2), ' KB');
    ELSE
        SET v_result = CONCAT(ROUND(v_bytes / 1048576, 2), ' MB');
    END IF;
    
    RETURN v_result;
END
";

$sql_view_activity = "
CREATE OR REPLACE VIEW view_user_activity AS
SELECT 
    u.id, 
    u.username, 
    u.email, 
    u.role,
    u.created_at,
    COUNT(n.id) as note_count,
    (SELECT COUNT(*) FROM notebooks nb WHERE nb.user_id = u.id) as notebook_count,
    fn_get_user_storage_usage(u.id) as storage_used
FROM users u
LEFT JOIN notes n ON u.id = n.user_id
GROUP BY u.id;
";

// 3. Procedures
$sql_proc_create = "
CREATE PROCEDURE sp_create_note(
    IN p_user_id INT,
    IN p_notebook_id INT,
    IN p_title VARCHAR(255),
    IN p_title_style TEXT,
    IN p_content_style TEXT,
    IN p_content LONGTEXT,
    IN p_tags TEXT, 
    OUT p_new_note_id INT
)
BEGIN
    DECLARE v_notebook_id INT;
    
    IF p_notebook_id = 0 THEN 
        SET v_notebook_id = NULL; 
    ELSE 
        SET v_notebook_id = p_notebook_id; 
    END IF;

    INSERT INTO notes (user_id, notebook_id, title, title_style, content_style, content, created_at, updated_at) 
    VALUES (p_user_id, v_notebook_id, p_title, p_title_style, p_content_style, p_content, NOW(), NOW());
    
    SET p_new_note_id = LAST_INSERT_ID();
END
";

$sql_proc_delete = "
CREATE PROCEDURE sp_delete_notebook_cascade(IN p_notebook_id INT, IN p_user_id INT)
BEGIN
    INSERT INTO admin_logs (admin_id, action_type, target_id, details)
    VALUES (p_user_id, 'DELETE_NOTEBOOK_PROC', p_notebook_id, CONCAT('Notebook deleted via Procedure by User ', p_user_id));

    DELETE FROM notes WHERE notebook_id = p_notebook_id AND user_id = p_user_id;
    DELETE FROM notebooks WHERE id = p_notebook_id AND user_id = p_user_id;
END
";

// 4. Triggers
$sql_trig_user = "
CREATE TRIGGER tr_after_user_delete
AFTER DELETE ON users
FOR EACH ROW
BEGIN
    INSERT INTO admin_logs (admin_id, action_type, target_id, details)
    VALUES (OLD.id, 'USER_DELETED_TRIGGER', OLD.id, CONCAT('User ', OLD.username, ' was deleted. Trigger fired.'));
END
";

$sql_trig_notes = "
CREATE TRIGGER tr_soft_delete_notes
AFTER UPDATE ON notebooks
FOR EACH ROW
BEGIN
    IF NEW.is_trashed = 1 AND OLD.is_trashed = 0 THEN
        UPDATE notes SET is_trashed = 1, trashed_at = NOW() 
        WHERE notebook_id = NEW.id;
    END IF;
    
    IF NEW.is_trashed = 0 AND OLD.is_trashed = 1 THEN
        UPDATE notes SET is_trashed = 0, trashed_at = NULL 
        WHERE notebook_id = NEW.id;
    END IF;
END
";

// EXECUTION ARRAY
// Note: We use DROP IF EXISTS separately
$commands = [
    "Drop Func" => "DROP FUNCTION IF EXISTS fn_get_user_storage_usage",
    "Prep" => $sql_prep,
    "View Stats" => $sql_view_stats,
    "Create Func" => $sql_func_storage,
    "View Activity" => $sql_view_activity,
    "Drop Proc 1" => "DROP PROCEDURE IF EXISTS sp_create_note",
    "Create Proc 1" => $sql_proc_create,
    "Drop Proc 2" => "DROP PROCEDURE IF EXISTS sp_delete_notebook_cascade",
    "Create Proc 2" => $sql_proc_delete,
    "Drop Trig 1" => "DROP TRIGGER IF EXISTS tr_after_user_delete",
    "Create Trig 1" => $sql_trig_user,
    "Drop Trig 2" => "DROP TRIGGER IF EXISTS tr_soft_delete_notes",
    "Create Trig 2" => $sql_trig_notes
];

foreach ($commands as $name => $sql) {
    echo "<hr><strong>$name:</strong> <pre>" . htmlspecialchars(substr($sql, 0, 100)) . "...</pre>";
    try {
        if (!$conn->query($sql)) {
            echo "<p style='color:red'><strong>Error:</strong> " . $conn->error . "</p>";
        } else {
            echo "<p style='color:green'>Success.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'><strong>Exception:</strong> " . $e->getMessage() . "</p>";
    }
}

echo "<h2>Done.</h2>";
?>