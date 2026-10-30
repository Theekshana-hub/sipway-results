<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Students';
$conn = getConnection();

// ============================================
// AJAX: Next Registration Number
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'next_reg') {
    header('Content-Type: application/json');
    $batch_id = (int)($_GET['batch_id'] ?? 0);
    if (!$batch_id) {
        echo json_encode(['ok' => false, 'reg' => '']);
        exit;
    }
    $info = $conn->query("
        SELECT c.course_code, b.batch_code
        FROM batches b
        JOIN courses c ON b.course_id = c.id
        WHERE b.id = $batch_id
    ")->fetch_assoc();

    if (!$info) {
        echo json_encode(['ok' => false, 'reg' => '']);
        exit;
    }

    $courseCode = $info['course_code'];
    $batchCode  = $info['batch_code'];
    $prefix = $courseCode . '/' . $batchCode . '/';
    $like = $conn->real_escape_string($prefix . '%');

    $res = $conn->query("
        SELECT registration_number FROM students
        WHERE registration_number LIKE '$like'
        ORDER BY id DESC
        LIMIT 50
    ");

    $maxSeq = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (preg_match('/\/(\d+)$/', $r['registration_number'], $m)) {
                $seq = (int)$m[1];
                if ($seq > $maxSeq) $maxSeq = $seq;
            }
        }
    }
    $next = $maxSeq + 1;
    $reg = $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);

    echo json_encode(['ok' => true, 'reg' => $reg, 'course' => $courseCode, 'batch' => $batchCode]);
    exit;
}

// ============================================
// HELPER: Generate next reg number for a batch
// ============================================
function generateNextReg($conn, $batch_id) {
    $info = $conn->query("
        SELECT c.course_code, b.batch_code
        FROM batches b
        JOIN courses c ON b.course_id = c.id
        WHERE b.id = $batch_id
    ")->fetch_assoc();

    if (!$info) return null;

    $prefix = $info['course_code'] . '/' . $info['batch_code'] . '/';
    $like = $conn->real_escape_string($prefix . '%');

    $res = $conn->query("
        SELECT registration_number FROM students
        WHERE registration_number LIKE '$like'
        ORDER BY id DESC
        LIMIT 50
    ");

    $maxSeq = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (preg_match('/\/(\d+)$/', $r['registration_number'], $m)) {
                $seq = (int)$m[1];
                if ($seq > $maxSeq) $maxSeq = $seq;
            }
        }
    }
    return $prefix . str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
}

// ============================================
// BULK EXCEL / CSV UPLOAD HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $admin = getAdminUsername();
    $uploaded = 0;
    $skipped  = 0;
    $errors   = [];

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please select a valid Excel/CSV file.');
        redirect('students.php');
    }

    $file    = $_FILES['excel_file'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpPath = $file['tmp_name'];

    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        setFlash('error', 'Only .xlsx, .xls or .csv files are allowed.');
        redirect('students.php');
    }

    $rows = [];

    // ---------- Read file ----------
    if ($ext === 'csv') {
        if (($handle = fopen($tmpPath, 'r')) !== false) {
            $header = fgetcsv($handle);
            $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
            while (($data = fgetcsv($handle)) !== false) {
                if (count(array_filter($data)) === 0) continue;
                $rows[] = array_combine($header, $data);
            }
            fclose($handle);
        }
    } else {
        require_once __DIR__ . '/../vendor/autoload.php';

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $data  = $sheet->toArray(null, true, true, true);

            $headerRow = array_shift($data);
            $header = [];
            foreach ($headerRow as $col => $val) {
                $header[$col] = strtolower(trim((string)$val));
            }

            foreach ($data as $row) {
                if (count(array_filter($row)) === 0) continue;
                $combined = [];
                foreach ($header as $col => $key) {
                    $combined[$key] = isset($row[$col]) ? trim((string)$row[$col]) : '';
                }
                $rows[] = $combined;
            }
        } catch (Exception $e) {
            setFlash('error', 'Failed to read Excel file: ' . $e->getMessage());
            redirect('students.php');
        }
    }

    // ---------- Process each row ----------
    foreach ($rows as $index => $row) {
        $lineNo = $index + 2;

        $reg        = trim($row['registration_number'] ?? $row['reg_number'] ?? $row['reg'] ?? '');
        $courseCode = trim($row['course_code'] ?? $row['course'] ?? '');
        $batchCode  = trim($row['batch_code'] ?? $row['batch'] ?? '');
        $name       = trim($row['full_name'] ?? $row['name'] ?? '');
        $phone      = trim($row['phone'] ?? '');
        $phone2     = trim($row['phone2'] ?? $row['phone_2'] ?? '');
        $addr       = trim($row['address'] ?? '');

        // Validation
        if ($name === '' || $courseCode === '' || $batchCode === '') {
            $errors[] = "Row $lineNo: Missing required fields (course_code / batch_code / full_name)";
            $skipped++;
            continue;
        }

        // Find batch_id
        $batchStmt = $conn->prepare("
            SELECT b.id 
            FROM batches b 
            JOIN courses c ON b.course_id = c.id 
            WHERE c.course_code = ? AND b.batch_code = ? AND b.is_active = 1
            LIMIT 1
        ");
        $batchStmt->bind_param("ss", $courseCode, $batchCode);
        $batchStmt->execute();
        $batchRow = $batchStmt->get_result()->fetch_assoc();
        $batchStmt->close();

        if (!$batchRow) {
            $errors[] = "Row $lineNo: Course/Batch not found → $courseCode / $batchCode";
            $skipped++;
            continue;
        }
        $batch_id = (int)$batchRow['id'];

        // Registration number
        if ($reg === '') {
            $reg = generateNextReg($conn, $batch_id);
            if (!$reg) {
                $errors[] = "Row $lineNo: Could not generate registration number";
                $skipped++;
                continue;
            }
        }

        // Check duplicate
        $check = $conn->prepare("SELECT id FROM students WHERE registration_number = ?");
        $check->bind_param("s", $reg);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            $errors[] = "Row $lineNo: Registration Number already exists → $reg";
            $skipped++;
            continue;
        }
        $check->close();

        // Insert student
        try {
            $stmt = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, phone2, address, batch_id) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("sssssi", $reg, $name, $phone, $phone2, $addr, $batch_id);
            if ($stmt->execute()) {
                $sid = $stmt->insert_id;
                $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
                $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
                $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");
                $uploaded++;
            } else {
                $errors[] = "Row $lineNo ($reg): " . $stmt->error;
                $skipped++;
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $errors[] = "Row $lineNo ($reg): " . $e->getMessage();
            $skipped++;
        }
    }

    logActivity($admin, 'BULK_UPLOAD_STUDENTS', "Uploaded: $uploaded | Skipped: $skipped");

    $msg = "Bulk upload finished. New students: $uploaded | Skipped: $skipped";
    if (!empty($errors)) {
        $msg .= " | Errors: " . count($errors);
        $_SESSION['upload_errors'] = array_slice($errors, 0, 15);
    }
    setFlash('success', $msg);
    redirect('students.php');
}

// ============================================
// SINGLE ADD / EDIT / DELETE (your existing code)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $reg   = trim($_POST['registration_number'] ?? '');
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $phone2= trim($_POST['phone2'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $batch = (int)($_POST['batch_id'] ?? 0);

        if ($reg && $name && $batch) {
            $check = $conn->prepare("SELECT id FROM students WHERE registration_number = ?");
            $check->bind_param("s", $reg);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $check->close();
                setFlash('error', 'Registration Number "' . $reg . '" already exists. Please use a different number.');
                redirect('students.php');
            }
            $check->close();

            try {
                $stmt = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, phone2, address, batch_id) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("sssssi", $reg, $name, $phone, $phone2, $addr, $batch);
                if ($stmt->execute()) {
                    $sid = $stmt->insert_id;
                    $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
                    $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
                    $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");
                    logActivity(getAdminUsername(), 'ADD_STUDENT', $reg);
                    setFlash('success', 'Student added successfully. Reg: ' . $reg);
                } else {
                    setFlash('error', 'Error adding student: ' . $stmt->error);
                }
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    setFlash('error', 'Registration Number "' . $reg . '" already exists. Please use a different number.');
                } else {
                    setFlash('error', 'Database error: ' . $e->getMessage());
                }
            }
        } else {
            setFlash('error', 'Please fill Registration Number, Full Name and Batch.');
        }
        redirect('students.php');
    }

    if ($action === 'edit') {
        $id    = (int)$_POST['id'];
        $reg   = trim($_POST['registration_number'] ?? '');
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $phone2= trim($_POST['phone2'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $batch = (int)$_POST['batch_id'];

        $check = $conn->prepare("SELECT id FROM students WHERE registration_number = ? AND id != ?");
        $check->bind_param("si", $reg, $id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            setFlash('error', 'Registration Number "' . $reg . '" already exists for another student.');
            redirect('students.php');
        }
        $check->close();

        try {
            $stmt = $conn->prepare("UPDATE students SET registration_number=?, full_name=?, phone=?, phone2=?, address=?, batch_id=? WHERE id=?");
            $stmt->bind_param("sssssii", $reg, $name, $phone, $phone2, $addr, $batch, $id);
            if ($stmt->execute()) {
                setFlash('success', 'Student updated.');
            } else {
                setFlash('error', 'Error updating student.');
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                setFlash('error', 'Registration Number "' . $reg . '" already exists.');
            } else {
                setFlash('error', 'Database error: ' . $e->getMessage());
            }
        }
        redirect('students.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("DELETE FROM students WHERE id=?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                setFlash('success', 'Student deleted.');
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            setFlash('error', 'Cannot delete student: ' . $e->getMessage());
        }
        redirect('students.php');
    }
}

// ============================================
// FILTERS + DATA FETCH
// ============================================
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search       = trim($_GET['q'] ?? '');

$coursesList = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
$coursesArr = [];
while ($c = $coursesList->fetch_assoc()) $coursesArr[] = $c;

$batchesList = $conn->query("SELECT b.id, b.course_id, b.batch_code, b.batch_name, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id WHERE b.is_active=1 ORDER BY c.course_code, b.batch_code");
$batchesArr = [];
while ($b = $batchesList->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.*, b.batch_code, b.batch_name, c.course_code, c.course_name,
           r.result_status, r.marks_percentage, r.full_marks,
           p.payment_status, cert.certificate_status
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN results r ON s.id = r.student_id
    LEFT JOIN payments p ON s.id = p.student_id
    LEFT JOIN certificates cert ON s.id = cert.student_id
    WHERE s.is_active = 1
";
if ($filterCourse) $sql .= " AND c.id = $filterCourse";
if ($filterBatch)  $sql .= " AND b.id = $filterBatch";
if ($search) {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%' OR s.phone LIKE '%$s%')";
}
$sql .= " ORDER BY s.id DESC";
$students = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ==================== PAGE HEADER ==================== -->
<div class="page-header d-flex justify-content-end align-items-center mb-3 gap-2">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadModal">
        <i class="bi bi-file-earmark-excel me-1"></i> Bulk Upload
    </button>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i> Add Student
    </button>
</div>

<!-- ==================== UPLOAD MODAL ==================== -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> Bulk Upload Students</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload">
                    <div class="mb-3">
                        <label class="form-label">Select Excel / CSV File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                        <small class="text-muted">Supported: .xlsx, .xls, .csv • Max recommended: 300 rows</small>
                    </div>
                    <div class="alert alert-info mb-0">
                        <strong>Required columns:</strong><br>
                        <code>course_code</code>, <code>batch_code</code>, <code>full_name</code><br>
                        <code>registration_number</code> is optional (auto-generated if empty)
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="Students_Bulk_Upload_Sample.xlsx" class="btn btn-outline-success me-auto" download>
                        <i class="bi bi-download"></i> Sample Excel
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-upload"></i> Upload & Process
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['upload_errors'])): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <strong>Some rows had issues:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($_SESSION['upload_errors'] as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['upload_errors']); endif; ?>

<!-- ==================== FILTER BAR ==================== -->
<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Course</label>
            <select name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All Courses</option>
                <?php foreach ($coursesArr as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse==$c['id']?'selected':''; ?>>
                    <?php echo sanitize($c['course_code'].' - '.$c['course_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Batch</label>
            <select name="batch_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All Batches</option>
                <?php foreach ($batchesArr as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $filterBatch==$b['id']?'selected':''; ?>>
                    <?php echo sanitize($b['course_code'].'/'.$b['batch_code'].' - '.$b['batch_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" placeholder="Reg No, Name, Phone..." value="<?php echo sanitize($search); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
        </div>
    </form>
</div>

<!-- ==================== TABLE ==================== -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="dataTable">
            <thead>
                <tr>
                    <th>Reg. Number</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Course / Batch</th>
                    <th>Marks</th>
                    <th>Result</th>
                    <th>Payment</th>
                    <th>Certificate</th>
                    <th width="110">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($students && $students->num_rows > 0): ?>
                <?php while ($row = $students->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><?php echo sanitize($row['phone'] ?: '—'); ?></td>
                    <td>
                        <small><?php echo sanitize($row['course_code']); ?></small>
                        <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span>
                    </td>
                    <td>
                        <?php
                        if ($row['full_marks'] !== null) echo $row['full_marks'];
                        elseif ($row['marks_percentage'] !== null) echo $row['marks_percentage'].'%';
                        else echo '—';
                        ?>
                    </td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : '—'; ?></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : '—'; ?></td>
                    <td><?php echo $row['certificate_status'] ? statusBadge($row['certificate_status']) : '—'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Student</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Registration Number *</label>
                                            <input type="text" name="registration_number" class="form-control" value="<?php echo sanitize($row['registration_number']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Batch *</label>
                                            <select name="batch_id" class="form-select" required>
                                                <?php foreach ($batchesArr as $b): ?>
                                                <option value="<?php echo $b['id']; ?>" <?php echo $row['batch_id']==$b['id']?'selected':''; ?>>
                                                    <?php echo sanitize($b['course_code'].'/'.$b['batch_code'].' - '.$b['batch_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" name="full_name" class="form-control" value="<?php echo sanitize($row['full_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Phone</label>
                                            <input type="text" name="phone" class="form-control" value="<?php echo sanitize($row['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Phone 2</label>
                                            <input type="text" name="phone2" class="form-control" value="<?php echo sanitize($row['phone2'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Address</label>
                                            <textarea name="address" class="form-control" rows="2"><?php echo sanitize($row['address'] ?? ''); ?></textarea>
                                        </div>
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
                <?php else: ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No students found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== ADD MODAL ==================== -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addStudentForm">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Course *</label>
                            <select id="addCourse" class="form-select" required>
                                <option value="">Select Course first</option>
                                <?php foreach ($coursesArr as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo sanitize($c['course_code'].' - '.$c['course_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Batch *</label>
                            <select name="batch_id" id="addBatch" class="form-select" required disabled>
                                <option value="">Select Course first</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Registration Number *</label>
                            <div class="input-group">
                                <input type="text" name="registration_number" id="addRegNo" class="form-control" placeholder="Auto after batch select" required>
                                <button type="button" class="btn btn-outline-secondary" id="btnRegenReg" title="Generate again">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                            <small class="text-muted">Format: COURSE/BATCH/001 — auto generated from Course + Batch</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone 2</label>
                            <input type="text" name="phone2" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allBatches = <?php echo json_encode(array_map(function($b) {
    return [
        'id' => (int)$b['id'],
        'course_id' => (int)$b['course_id'],
        'batch_code' => $b['batch_code'],
        'batch_name' => $b['batch_name'],
        'course_code' => $b['course_code'],
        'label' => $b['course_code'] . '/' . $b['batch_code'] . ' - ' . $b['batch_name']
    ];
}, $batchesArr), JSON_UNESCAPED_UNICODE); ?>;

const addCourse = document.getElementById('addCourse');
const addBatch  = document.getElementById('addBatch');
const addRegNo  = document.getElementById('addRegNo');
const btnRegen  = document.getElementById('btnRegenReg');

function loadBatchesForCourse(courseId) {
    addBatch.innerHTML = '<option value="">Select Batch</option>';
    addBatch.disabled = true;
    addRegNo.value = '';

    if (!courseId) return;

    const filtered = allBatches.filter(b => b.course_id === parseInt(courseId));
    if (filtered.length === 0) {
        addBatch.innerHTML = '<option value="">No batches for this course</option>';
        return;
    }

    filtered.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.label;
        addBatch.appendChild(opt);
    });
    addBatch.disabled = false;
}

function fetchNextReg(batchId) {
    if (!batchId) {
        addRegNo.value = '';
        return;
    }
    addRegNo.value = 'Loading...';
    fetch('students.php?ajax=next_reg&batch_id=' + batchId)
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                addRegNo.value = data.reg;
            } else {
                addRegNo.value = '';
                alert('Could not generate registration number');
            }
        })
        .catch(() => {
            addRegNo.value = '';
            alert('Error generating registration number');
        });
}

if (addCourse) {
    addCourse.addEventListener('change', function () {
        loadBatchesForCourse(this.value);
    });
}
if (addBatch) {
    addBatch.addEventListener('change', function () {
        fetchNextReg(this.value);
    });
}
if (btnRegen) {
    btnRegen.addEventListener('click', function () {
        if (addBatch.value) fetchNextReg(addBatch.value);
        else alert('Please select Course and Batch first');
    });
}

const addModal = document.getElementById('addModal');
if (addModal) {
    addModal.addEventListener('show.bs.modal', function () {
        if (addCourse) addCourse.value = '';
        loadBatchesForCourse('');
        addRegNo.value = '';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>