<?php
// api/notebooks.php
include_once 'header.php';

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_GET['user_id'] ?? null; // Simulate Auth (In real app, extract from Token)

if (!$user_id) {
    sendResponse(401, "User ID required (simulated auth).");
}

switch ($method) {
    case 'GET':
        getNotebooks($conn, $user_id);
        break;
    case 'POST':
        createNotebook($conn, $user_id);
        break;
    case 'DELETE':
        deleteNotebook($conn, $user_id);
        break;
    default:
        sendResponse(405, "Method not allowed");
}

function getNotebooks($conn, $user_id)
{
    // Optionally filter by trashed status
    $trashed = isset($_GET['trashed']) ? (int) $_GET['trashed'] : 0;

    $sql = "SELECT nb.*, COUNT(n.id) as note_count 
            FROM notebooks nb 
            LEFT JOIN notes n ON nb.id = n.notebook_id AND n.is_trashed = $trashed
            WHERE nb.user_id = $user_id AND nb.is_trashed = $trashed
            GROUP BY nb.id ORDER BY nb.updated_at DESC";

    $result = $conn->query($sql);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    sendResponse(200, "Notebooks retrieved.", $data);
}

function createNotebook($conn, $user_id)
{
    $input = getJsonInput();
    if (empty($input['name'])) {
        sendResponse(400, "Notebook name required.");
    }

    $name = $conn->real_escape_string($input['name']);
    $desc = isset($input['description']) ? $conn->real_escape_string($input['description']) : '';

    // We can't identify the username easily without a real session, passing 'API User'
    $sql = "INSERT INTO notebooks (name, description, space_name, created_by_user, user_id) 
            VALUES ('$name', '$desc', 'Personal', 'API_User', $user_id)";

    if ($conn->query($sql)) {
        sendResponse(201, "Notebook created.", ["id" => $conn->insert_id, "name" => $name]);
    } else {
        sendResponse(500, "Error creating notebook: " . $conn->error);
    }
}

function deleteNotebook($conn, $user_id)
{
    // Expect ID in URL query ?id=X
    if (!isset($_GET['id'])) {
        sendResponse(400, "Notebook ID required.");
    }
    $nb_id = (int) $_GET['id'];

    // Verify Ownership
    $check = $conn->query("SELECT id FROM notebooks WHERE id=$nb_id AND user_id=$user_id");
    if ($check->num_rows === 0) {
        sendResponse(404, "Notebook not found or access denied.");
    }

    // ADS REQUIREMENT: USE STORED PROCEDURE
    $sql = "CALL sp_delete_notebook_cascade($nb_id, $user_id)";

    if ($conn->query($sql)) {
        sendResponse(200, "Notebook deleted successfully (Cascaded).");
    } else {
        sendResponse(500, "Error deleting notebook: " . $conn->error);
    }
}
?>