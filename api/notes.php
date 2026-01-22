<?php
// api/notes.php
include_once 'header.php';

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    sendResponse(401, "User ID required (simulated auth).");
}

switch ($method) {
    case 'GET':
        getNotes($conn, $user_id);
        break;
    case 'POST':
        createNote($conn, $user_id);
        break;
    case 'PUT':
        updateNote($conn, $user_id);
        break;
    case 'DELETE':
        deleteNote($conn, $user_id);
        break;
    default:
        sendResponse(405, "Method not allowed");
}

function getNotes($conn, $user_id)
{
    $trashed = isset($_GET['trashed']) ? (int) $_GET['trashed'] : 0;
    $notebook_id = isset($_GET['notebook_id']) ? (int) $_GET['notebook_id'] : null;
    $search = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : null;

    $sql = "SELECT n.id, n.title, n.content, n.created_at, n.updated_at, nb.name as notebook_name 
            FROM notes n 
            LEFT JOIN notebooks nb ON n.notebook_id = nb.id 
            WHERE n.user_id = $user_id AND n.is_trashed = $trashed";

    if ($notebook_id) {
        $sql .= " AND n.notebook_id = $notebook_id";
    }
    if ($search) {
        $sql .= " AND (n.title LIKE '%$search%' OR n.content LIKE '%$search%')";
    }

    $sql .= " ORDER BY n.updated_at DESC LIMIT 100";

    $result = $conn->query($sql);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Strip tags for preview if needed, or send raw
        $row['preview'] = substr(strip_tags($row['content']), 0, 100);
        $data[] = $row;
    }
    sendResponse(200, "Notes retrieved.", $data);
}

function createNote($conn, $user_id)
{
    $input = getJsonInput();

    $title = isset($input['title']) ? $conn->real_escape_string($input['title']) : 'Untitled';
    $content = isset($input['content']) ? $conn->real_escape_string($input['content']) : '';
    $notebook_id = isset($input['notebook_id']) ? (int) $input['notebook_id'] : 0;

    // Style defaults
    $t_style = '';
    $c_style = '';

    // ADS REQUIRED: CALL STORED PROCEDURE
    $sql = "CALL sp_create_note($user_id, $notebook_id, '$title', '$t_style', '$c_style', '$content', '', @new_id)";

    if ($conn->query($sql)) {
        // Fetch the output ID
        $res = $conn->query("SELECT @new_id as id");
        $row = $res->fetch_assoc();
        sendResponse(201, "Note created.", ["id" => $row['id']]);
    } else {
        sendResponse(500, "Error creating note: " . $conn->error);
    }
}

function updateNote($conn, $user_id)
{
    $input = getJsonInput();
    if (!isset($input['id'])) {
        sendResponse(400, "Note ID required.");
    }

    $id = (int) $input['id'];
    $title = $conn->real_escape_string($input['title']);
    $content = $conn->real_escape_string($input['content']);

    // Check ownership
    $check = $conn->query("SELECT id FROM notes WHERE id=$id AND user_id=$user_id");
    if ($check->num_rows === 0) {
        sendResponse(404, "Note not found.");
    }

    $sql = "UPDATE notes SET title='$title', content='$content', updated_at=NOW() WHERE id=$id";
    if ($conn->query($sql)) {
        sendResponse(200, "Note updated.");
    } else {
        sendResponse(500, "Update failed: " . $conn->error);
    }
}

function deleteNote($conn, $user_id)
{
    if (!isset($_GET['id'])) {
        sendResponse(400, "Note ID required.");
    }
    $id = (int) $_GET['id'];
    $permanent = isset($_GET['permanent']) ? true : false;

    // Check ownership
    $check = $conn->query("SELECT id FROM notes WHERE id=$id AND user_id=$user_id");
    if ($check->num_rows === 0) {
        sendResponse(404, "Note not found.");
    }

    if ($permanent) {
        $sql = "DELETE FROM notes WHERE id=$id"; // Trigger tr_log_note_delete
        $msg = "Note permanently deleted.";
    } else {
        $sql = "UPDATE notes SET is_trashed=1, trashed_at=NOW() WHERE id=$id"; // Trigger tr_log_note_update
        $msg = "Note moved to trash.";
    }

    if ($conn->query($sql)) {
        sendResponse(200, $msg);
    } else {
        sendResponse(500, "Delete failed: " . $conn->error);
    }
}
?>