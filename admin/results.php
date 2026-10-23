<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Results';
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $student_id = (int)$_POST['student_id'];
        $a1 = $_POST['assignment1'] !== '' ? (float)$_POST['assignment1'] : null;
        $a2 = $_POST['assignment2'] !== '' ? (float)$_POST['assignment2'] : null;
        $fe = $_POST['final_exam'] !== '' ? (float)$_POST['final_exam'] : null;
        $full = $_POST['full_marks'] !== '' ? (float)$_POST['full_marks'] : null;
        $pct = $_POST['marks_percentage'] !== '' ? (float)$_POST['marks_percentage'] : null;
        $status = $_POST['result_status'] ?? 'PENDING';
        $remarks = trim($_POST['remarks'] ?? '');

        // Check if result exists
        $check = $conn->query("SELECT id FROM results WHERE student_id=$student_id");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE results SET assignment1=?, assignment2=?, final_exam=?, full_marks=?, marks_percentage=?, result_status=?, remarks=?, entered_by=? WHERE student_id=?");
            $admin = getAdminUsername();
            $stmt->bind_param("ddddssssi", $a1, $a2, $fe, $full, $pct, $status, $remarks, $admin, $student_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO results (student_id, assignment1, assignment2, final_exam, full_marks, marks_percentage, result_status, remarks, entered_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $admin = getAdminUsername();
            $stmt->bind_param("iddddssss", $student_id, $a1, $a2, $fe, $full, $pct, $status, $remarks, $admin);
        }
        if ($stmt->execute()) {
            // Auto update certificate eligibility
            $pay = $conn->query("SELECT payment_status FROM payments WHERE student_id=$student_id")->fetch_assoc();
            $payOk = $pay && in_array($pay['payment_status'], ['PAID','COMPLETED','ONE_TIME','CONVA_PAY']);
            $passOk = ($status === 'PRESENT') && (($full !== null && $full >= 40) || ($pct !== null && $pct >= 40));
            if ($status === 'ABSENT' || $status === 'NOT_ELIGIBLE') {
                $conn->query("UPDATE certificates SET certificate_status='NOT_ELIGIBLE' WHERE student_id=$student_id");
            } elseif ($passOk && $payOk) {
                $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id");
            } else {
                $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id");
            }
            logActivity(getAdminUsername(), 'UPDATE_RESULT', "Student ID: $student_id");
            setFlash('success', 'Result updated successfully.');
        } else {
            setFlash('error', 'Error updating result.');
        }
        $stmt->close();
        redirect('results.php?' . http_build_query(array_filter([
            'course_id' => $_POST['redirect_course'] ?? '',
            'batch_id'  => $_POST['redirect_batch'] ?? '',
            'q'         => $_POST['redirect_q'] ?? ''
        ])));
    }
}

$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$coursesList = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
$coursesArr = [];
while ($c = $coursesList->fetch_assoc()) $coursesArr[] = $c;

$batchesList = $conn->query("SELECT b.id, b.batch_code, b.batch_name, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
$batchesArr = [];
while ($b = $batchesList->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.id AS student_id, s.registration_number, s.full_name,
           b.batch_code, c.course_code, c.course_name,
           r.id AS result_id, r.assignment1, r.assignment2, r.final_exam, r.full_marks, r.marks_percentage, r.result_status, r.remarks
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN results r ON s.id = r.student_id
    WHERE s.is_active = 1
";
if ($filterCourse) $sql .= " AND c.id = $filterCourse";
if ($filterBatch)  $sql .= " AND b.id = $filterBatch";
if ($filterStatus) $sql .= " AND r.result_status = '".$conn->real_escape_string($filterStatus)."'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%')";
}
$sql .= " ORDER BY c.course_code, b.batch_code, s.registration_number";
$rows = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Course</label>
            <select name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($coursesArr as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse==$c['id']?'selected':''; ?>><?php echo sanitize($c['course_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Batch</label>
            <select name="batch_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($batchesArr as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $filterBatch==$b['id']?'selected':''; ?>><?php echo sanitize($b['course_code'].'/'.$b['batch_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="PRESENT" <?php echo $filterStatus=='PRESENT'?'selected':''; ?>>Present</option>
                <option value="ABSENT" <?php echo $filterStatus=='ABSENT'?'selected':''; ?>>Absent</option>
                <option value="PENDING" <?php echo $filterStatus=='PENDING'?'selected':''; ?>>Pending</option>
                <option value="NOT_ELIGIBLE" <?php echo $filterStatus=='NOT_ELIGIBLE'?'selected':''; ?>>Not Eligible</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Reg / Name">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reg. Number</th>
                    <th>Name</th>
                    <th>Course/Batch</th>
                    <th>Asg 1</th>
                    <th>Asg 2</th>
                    <th>Final</th>
                    <th>Full</th>
                    <th>%</th>
                    <th>Status</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows->num_rows > 0): while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php echo $row['assignment1'] !== null ? $row['assignment1'] : '—'; ?></td>
                    <td><?php echo $row['assignment2'] !== null ? $row['assignment2'] : '—'; ?></td>
                    <td><?php echo $row['final_exam'] !== null ? $row['final_exam'] : '—'; ?></td>
                    <td><strong><?php echo $row['full_marks'] !== null ? $row['full_marks'] : '—'; ?></strong></td>
                    <td><?php echo $row['marks_percentage'] !== null ? $row['marks_percentage'].'%' : '—'; ?></td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : statusBadge('PENDING'); ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editResult<?php echo $row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>

                <!-- Edit Result Modal -->
                <div class="modal fade" id="editResult<?php echo $row['student_id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Update Result — <?php echo sanitize($row['registration_number']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                    <input type="hidden" name="redirect_course" value="<?php echo $filterCourse; ?>">
                                    <input type="hidden" name="redirect_batch" value="<?php echo $filterBatch; ?>">
                                    <input type="hidden" name="redirect_q" value="<?php echo sanitize($search); ?>">

                                    <p class="text-muted mb-3"><?php echo sanitize($row['full_name']); ?> | <?php echo sanitize($row['course_code'].'/'.$row['batch_code']); ?></p>

                                    <div class="row g-3">
                                        <div class="col-4">
                                            <label class="form-label">Assignment 1</label>
                                            <input type="number" step="0.01" name="assignment1" id="assignment1" class="form-control" value="<?php echo $row['assignment1'] ?? ''; ?>">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label">Assignment 2</label>
                                            <input type="number" step="0.01" name="assignment2" id="assignment2" class="form-control" value="<?php echo $row['assignment2'] ?? ''; ?>">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label">Final Exam</label>
                                            <input type="number" step="0.01" name="final_exam" id="final_exam" class="form-control" value="<?php echo $row['final_exam'] ?? ''; ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Full Marks</label>
                                            <input type="number" step="0.01" name="full_marks" id="full_marks" class="form-control" value="<?php echo $row['full_marks'] ?? ''; ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Percentage (%)</label>
                                            <input type="number" step="0.01" name="marks_percentage" class="form-control" value="<?php echo $row['marks_percentage'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Result Status *</label>
                                            <select name="result_status" class="form-select" required>
                                                <option value="PENDING" <?php echo ($row['result_status']??'')=='PENDING'?'selected':''; ?>>Pending</option>
                                                <option value="PRESENT" <?php echo ($row['result_status']??'')=='PRESENT'?'selected':''; ?>>Present</option>
                                                <option value="ABSENT" <?php echo ($row['result_status']??'')=='ABSENT'?'selected':''; ?>>Absent (AB)</option>
                                                <option value="NOT_ELIGIBLE" <?php echo ($row['result_status']??'')=='NOT_ELIGIBLE'?'selected':''; ?>>Not Eligible (NO)</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" rows="2"><?php echo sanitize($row['remarks'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save Result</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; else: ?>
                <tr><td colspan="10" class="text-center text-muted py-5">No records found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
