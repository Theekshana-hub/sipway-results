<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Courses';
$conn = getConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $code = trim($_POST['course_code'] ?? '');
        $name = trim($_POST['course_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($code && $name) {
            $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $code, $name, $desc);
            if ($stmt->execute()) {
                logActivity(getAdminUsername(), 'ADD_COURSE', $code . ' - ' . $name);
                setFlash('success', 'Course added successfully.');
            } else {
                setFlash('error', 'Error: ' . $conn->error);
            }
            $stmt->close();
        }
        redirect('courses.php');
    }

    if ($action === 'edit') {
        $id   = (int)$_POST['id'];
        $code = trim($_POST['course_code'] ?? '');
        $name = trim($_POST['course_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $stmt = $conn->prepare("UPDATE courses SET course_code=?, course_name=?, description=? WHERE id=?");
        $stmt->bind_param("sssi", $code, $name, $desc, $id);
        if ($stmt->execute()) {
            logActivity(getAdminUsername(), 'EDIT_COURSE', "ID:$id $code");
            setFlash('success', 'Course updated successfully.');
        } else {
            setFlash('error', 'Error updating course.');
        }
        $stmt->close();
        redirect('courses.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM courses WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            logActivity(getAdminUsername(), 'DELETE_COURSE', "ID:$id");
            setFlash('success', 'Course deleted.');
        }
        $stmt->close();
        redirect('courses.php');
    }
}

$courses = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM batches b WHERE b.course_id = c.id) AS batch_count,
           (SELECT COUNT(*) FROM students s JOIN batches b ON s.batch_id=b.id WHERE b.course_id=c.id AND s.is_active=1) AS student_count
    FROM courses c
    ORDER BY c.course_name
");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-end align-items-center mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i> Add Course
    </button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="dataTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Course Name</th>
                    <th>Batches</th>
                    <th>Students</th>
                    <th>Status</th>
                    <th width="140">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; while ($row = $courses->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><code><?php echo sanitize($row['course_code']); ?></code></td>
                    <td>
                        <strong><?php echo sanitize($row['course_name']); ?></strong>
                        <?php if ($row['description']): ?>
                        <br><small class="text-muted"><?php echo sanitize($row['description']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?php echo $row['batch_count']; ?></span></td>
                    <td><span class="badge bg-primary"><?php echo $row['student_count']; ?></span></td>
                    <td><?php echo $row['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course? All related batches & students will be deleted.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Course</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Course Code</label>
                                        <input type="text" name="course_code" class="form-control" value="<?php echo sanitize($row['course_code']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Course Name</label>
                                        <input type="text" name="course_name" class="form-control" value="<?php echo sanitize($row['course_name']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="2"><?php echo sanitize($row['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Update</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Course Code *</label>
                        <input type="text" name="course_code" class="form-control" placeholder="e.g. RL, DBM, GRA" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Course Name *</label>
                        <input type="text" name="course_name" class="form-control" placeholder="Full course name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
