<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['auditor', 'organization'])) {
    header("Location: ../dashboard.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: ../dashboard.php");
    exit;
}

$pageTitle = 'Audit Notes';
$pageDescription = 'Create and manage professional audit notes';

$msg = '';
$msgType = 'success';
$edit_mode = false;

$edit = [
    'note_id' => '',
    'assignment_id' => '',
    'note_title' => '',
    'note_description' => ''
];

$assignments = [];
$stmt = $conn->prepare("
    SELECT assignment_id, audit_title, period_from, period_to, status, priority
    FROM audit_assignments
    WHERE company_id = ?
    ORDER BY assignment_id DESC
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();

if (isset($_GET['edit'])) {
    $note_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT note_id, assignment_id, note_title, note_description
        FROM audit_notes
        WHERE note_id = ?
          AND company_id = ?
          AND auditor_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $note_id, $company_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $edit = $row;
        $edit_mode = true;
    }
    $stmt->close();
}

if (isset($_POST['save_note'])) {
    $note_id = (int)($_POST['note_id'] ?? 0);
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $note_title = trim($_POST['note_title'] ?? '');
    $note_description = trim($_POST['note_description'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif ($note_title === '' || $note_description === '') {
        $msg = 'Please fill note title and description.';
        $msgType = 'danger';
    } else {
        if ($note_id > 0) {
            $stmt = $conn->prepare("
                UPDATE audit_notes
                SET assignment_id = ?, note_title = ?, note_description = ?
                WHERE note_id = ?
                  AND company_id = ?
                  AND auditor_id = ?
            ");
            $stmt->bind_param("issiii", $assignment_id, $note_title, $note_description, $note_id, $company_id, $user_id);

            if ($stmt->execute()) {
                $msg = 'Audit note updated successfully.';
                $msgType = 'success';
                $edit_mode = false;
                $edit = [
                    'note_id' => '',
                    'assignment_id' => '',
                    'note_title' => '',
                    'note_description' => ''
                ];
            } else {
                $msg = 'Failed to update audit note.';
                $msgType = 'danger';
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO audit_notes
                (assignment_id, company_id, auditor_id, note_title, note_description)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiss", $assignment_id, $company_id, $user_id, $note_title, $note_description);

            if ($stmt->execute()) {
                $msg = 'Audit note added successfully.';
                $msgType = 'success';
            } else {
                $msg = 'Failed to add audit note.';
                $msgType = 'danger';
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } else {
        $note_id = (int)($_GET['delete'] ?? 0);

        $stmt = $conn->prepare("
        DELETE FROM audit_notes
        WHERE note_id = ?
          AND company_id = ?
          AND auditor_id = ?
    ");
        $stmt->bind_param("iii", $note_id, $company_id, $user_id);

        if ($stmt->execute()) {
            $msg = 'Audit note deleted successfully.';
            $msgType = 'success';
        } else {
            $msg = 'Failed to delete audit note.';
            $msgType = 'danger';
        }
        $stmt->close();
    }
}

$rows = [];
$stmt = $conn->prepare("
    SELECT 
        n.note_id,
        n.assignment_id,
        n.note_title,
        n.note_description,
        n.created_at,
        a.audit_title,
        a.status,
        a.priority,
        a.period_from,
        a.period_to
    FROM audit_notes n
    LEFT JOIN audit_assignments a ON a.assignment_id = n.assignment_id
    WHERE n.company_id = ?
      AND n.auditor_id = ?
    ORDER BY n.note_id DESC
");
$stmt->bind_param("ii", $company_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">Γÿ░</button>
            <div class="page-heading">
                <h1>Audit Notes</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'Auditor') ?></div>
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1))) ?></div>
                <div class="meta">
                    <strong><?= e($_SESSION['full_name'] ?? 'Auditor') ?></strong>
                    <span><?= e($_SESSION['email'] ?? '') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><?= $edit_mode ? 'Edit Audit Note' : 'Add Audit Note' ?></h3>
                <span class="badge badge-primary">Audit Working Paper</span>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="note_id" value="<?= e($edit['note_id']) ?>">

                    <div class="form-grid">


                        <div class="form-group">
                            <label class="form-label">Note Title</label>
                            <input type="text" name="note_title" class="form-control" value="<?= e($edit['note_title']) ?>" required>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Note Description</label>
                            <textarea name="note_description" class="form-control" placeholder="Enter audit findings, observations, exceptions, recommendations..." required><?= e($edit['note_description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_note" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Note' : 'Save Note' ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="audit_notes.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Saved Audit Notes</h3>
                <span class="badge badge-success"><?= count($rows) ?> Notes</span>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Assignment</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['note_id']) ?></td>
                                        <td><?= e($row['audit_title'] ?? 'General Note') ?></td>
                                        <td><?= e($row['note_title']) ?></td>
                                        <td><span class="badge badge-primary"><?= e($row['status'] ?? 'N/A') ?></span></td>
                                        <td><span class="badge badge-warning"><?= e($row['priority'] ?? 'N/A') ?></span></td>
                                        <td><?= e($row['created_at']) ?></td>
                                        <td>
                                            <a href="?edit=<?= (int)$row['note_id'] ?>" class="btn btn-light">Edit</a>
                                            <a href="?delete=<?= (int)$row['note_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>" class="btn btn-danger" onclick="return confirm('Delete this audit note?')">Delete</a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="7" style="background:#fcf9fa;">
                                            <strong>Description:</strong><br>
                                            <?= nl2br(e($row['note_description'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">No audit notes found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>