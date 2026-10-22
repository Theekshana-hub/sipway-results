<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Batches';
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $course_id = (int)$_POST['course_id'];
        $code = trim($_POST['batch_code'] ?? '');
        $name = trim($_POST['batch_name'] ?? '');
        if ($course_id && $code && $name) {
            $stmt = $conn->prepare("INSERT INTO batches (course_id, batch_code, batch_name) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $course_id, $code, $name);
            if ($stmt->execute()) {
                logActivity(getAdminUsername(), 'ADD_BATCH', $code);
                setFlash('success', 'Batch added successfully.');
            } else {
                setFlash('error', 'Error: ' . $conn->error);
            }
            $stmt->close();
        }
        redirect('batches.php');
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $course_id = (int)$_POST['course_id'];
        $code = trim($_POST['batch_code'] ?? '');
        $name = trim($_POST['batch_name'] ?? '');
        $stmt = $conn->prepare("UPDATE batches SET course_id=?, batch_code=?, batch_name=? WHERE id=?");
        $stmt->bind_param("issi", $course_id, $code, $name, $id);
        if ($stmt->execute()) {
            setFlash('success', 'Batch updated.');
        }
        $stmt->close();
        redirect('batches.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM batches WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            setFlash('success', 'Batch deleted.');
        }
        $stmt->close();
        redirect('batches.php');
    }
}

$coursesList = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
$coursesArr = [];
while ($c = $coursesList->fetch_assoc()) $coursesArr[] = $c;

$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$sql = "
    SELECT b.*, c.course_code, c.course_name,
           (SELECT COUNT(*) FROM students s WHERE s.batch_id=b.id AND s.is_active=1) AS student_count
    FROM batches b
    JOIN courses c ON b.course_id = c.id
";
if ($filterCourse) $sql .= " WHERE b.course_id = $filterCourse";
$sql .= " ORDER BY c.course_name, b.batch_code";
$batches = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-end align-items-center mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i> Add Batch
    </button>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Filter by Course</label>
            <select name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All Courses</option>
                <?php foreach ($coursesArr as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse == $c['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($c['course_code'] . ' - ' . $c['course_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Batch Code</th>
                    <th>Batch Name</th>
                    <th>Course</th>
                    <th>Students</th>
                    <th width="140">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; while ($row = $batches->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><code><?php echo sanitize($row['batch_code']); ?></code></td>
                    <td><?php echo sanitize($row['batch_name']); ?></td>
                    <td>
                        <span class="badge bg-primary"><?php echo sanitize($row['course_code']); ?></span>
                        <?php echo sanitize($row['course_name']); ?>
                    </td>
                    <td><span class="badge bg-secondary"><?php echo $row['student_count']; ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this batch?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Batch</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Course</label>
                                        <select name="course_id" class="form-select" required>
                                            <?php foreach ($coursesArr as $c): ?>
                                            <option value="<?php echo $c['id']; ?>" <?php echo $row['course_id']==$c['id']?'selected':''; ?>>
                                                <?php echo sanitize($c['course_code'].' - '.$c['course_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Batch Code</label>
                                        <input type="text" name="batch_code" class="form-control" value="<?php echo sanitize($row['batch_code']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Batch Name</label>
                                        <input type="text" name="batch_name" class="form-control" value="<?php echo sanitize($row['batch_name']); ?>" required>
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

<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Batch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Course *</label>
                        <select name="course_id" class="form-select" required>
                            <option value="">Select Course</option>
                            <?php foreach ($coursesArr as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['course_code'].' - '.$c['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Batch Code *</label>
                        <input type="text" name="batch_code" class="form-control" placeholder="e.g. B10, B15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Batch Name *</label>
                        <input type="text" name="batch_name" class="form-control" placeholder="e.g. Russian Language Batch 10" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
