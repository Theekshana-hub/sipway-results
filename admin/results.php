<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Results';
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $student_id = (int)$_POST['student_id'];

        $assignments = [];
        if (!empty($_POST['assignments']) && is_array($_POST['assignments'])) {
            foreach ($_POST['assignments'] as $val) {
                if ($val !== '' && is_numeric($val)) {
                    $assignments[] = (float)$val;
                }
            }
        }

        $exams = [];
        if (!empty($_POST['exams']) && is_array($_POST['exams'])) {
            foreach ($_POST['exams'] as $val) {
                if ($val !== '' && is_numeric($val)) {
                    $exams[] = (float)$val;
                }
            }
        }

        $full = array_sum($assignments) + array_sum($exams);
        if ($full == 0) $full = null;

        $allMarks = array_merge($assignments, $exams);
        $pct = null;
        if (isset($_POST['marks_percentage']) && $_POST['marks_percentage'] !== '') {
            $pct = (float)$_POST['marks_percentage'];
        } elseif (count($allMarks) > 0) {
            $pct = array_sum($allMarks) / count($allMarks);
        }

        $status  = $_POST['result_status'] ?? 'PENDING';
        $remarks = trim($_POST['remarks'] ?? '');
        $admin   = getAdminUsername();

        $assignments_json = json_encode($assignments);
        $exams_json       = json_encode($exams);

        $a1 = isset($assignments[0]) ? (string)$assignments[0] : null;
        $a2 = isset($assignments[1]) ? (string)$assignments[1] : null;
        $a3 = isset($assignments[2]) ? (string)$assignments[2] : null;
        $fe = isset($exams[0])       ? (string)$exams[0]       : null;

        $full_s = $full !== null ? (string)$full : null;
        $pct_s  = $pct  !== null ? (string)$pct  : null;

        $check = $conn->query("SELECT id FROM results WHERE student_id = $student_id");

        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE results SET 
                assignment1 = ?, assignment2 = ?, assignment3 = ?, final_exam = ?,
                full_marks = ?, marks_percentage = ?, result_status = ?, remarks = ?,
                entered_by = ?, assignments_json = ?, exams_json = ?
                WHERE student_id = ?");

            if (!$stmt) {
                setFlash('error', 'Prepare failed: ' . $conn->error);
                redirect('results.php');
            }

            $stmt->bind_param(
                "sssssssssssi",
                $a1, $a2, $a3, $fe,
                $full_s, $pct_s,
                $status, $remarks, $admin,
                $assignments_json, $exams_json,
                $student_id
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO results 
                (student_id, assignment1, assignment2, assignment3, final_exam,
                 full_marks, marks_percentage, result_status, remarks, entered_by,
                 assignments_json, exams_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

            if (!$stmt) {
                setFlash('error', 'Prepare failed: ' . $conn->error);
                redirect('results.php');
            }

            $stmt->bind_param(
                "isssssssssss",
                $student_id,
                $a1, $a2, $a3, $fe,
                $full_s, $pct_s,
                $status, $remarks, $admin,
                $assignments_json, $exams_json
            );
        }

        if ($stmt->execute()) {
            $payRow = $conn->query("SELECT payment_status FROM payments WHERE student_id = $student_id");
            $pay = $payRow ? $payRow->fetch_assoc() : null;
            $payOk = $pay && in_array($pay['payment_status'], ['PAID','COMPLETED','ONE_TIME','CONVA_PAY']);
            $passOk = ($status === 'PRESENT') && (($full !== null && $full >= 40) || ($pct !== null && $pct >= 40));

            if ($status === 'ABSENT' || $status === 'NOT_ELIGIBLE') {
                $conn->query("UPDATE certificates SET certificate_status = 'NOT_ELIGIBLE' WHERE student_id = $student_id");
            } elseif ($passOk && $payOk) {
                $conn->query("UPDATE certificates SET certificate_status = 'READY' WHERE student_id = $student_id");
            } else {
                $conn->query("UPDATE certificates SET certificate_status = 'PENDING' WHERE student_id = $student_id");
            }

            logActivity($admin, 'UPDATE_RESULT', "Student ID: $student_id | Asg: " . count($assignments) . " | Exam: " . count($exams));
            setFlash('success', 'Result updated successfully. (Assignments: ' . count($assignments) . ', Exams: ' . count($exams) . ')');
        } else {
            setFlash('error', 'Save failed: ' . $stmt->error);
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
$filterBatch  = isset($_GET['batch_id'])  ? (int)$_GET['batch_id']  : 0;
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
           r.id AS result_id, r.assignment1, r.assignment2, r.assignment3, 
           r.final_exam, r.full_marks, r.marks_percentage, r.result_status, r.remarks,
           r.assignments_json, r.exams_json
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

$allRows = [];
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $allRows[] = $r;
    }
}

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
                    <th>Assignments</th>
                    <th>Exams</th>
                    <th>Full Marks</th>
                    <th>%</th>
                    <th>Status</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($allRows) > 0): foreach ($allRows as $row): 
                    $asgList = [];
                    $examList = [];

                    if (!empty($row['assignments_json'])) {
                        $decoded = json_decode($row['assignments_json'], true);
                        if (is_array($decoded)) $asgList = $decoded;
                    } else {
                        if ($row['assignment1'] !== null && $row['assignment1'] !== '') $asgList[] = $row['assignment1'];
                        if ($row['assignment2'] !== null && $row['assignment2'] !== '') $asgList[] = $row['assignment2'];
                        if ($row['assignment3'] !== null && $row['assignment3'] !== '') $asgList[] = $row['assignment3'];
                    }

                    if (!empty($row['exams_json'])) {
                        $decoded = json_decode($row['exams_json'], true);
                        if (is_array($decoded)) $examList = $decoded;
                    } else {
                        if ($row['final_exam'] !== null && $row['final_exam'] !== '') $examList[] = $row['final_exam'];
                    }
                ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td>
                        <small><?php echo sanitize($row['course_code']); ?></small>
                        <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span>
                    </td>
                    <td>
                        <?php if (count($asgList) > 0): ?>
                            <?php foreach ($asgList as $i => $m): ?>
                                <span class="badge bg-info text-dark me-1">A<?php echo $i+1; ?>: <?php echo $m; ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (count($examList) > 0): ?>
                            <?php foreach ($examList as $i => $m): ?>
                                <span class="badge bg-warning text-dark me-1">E<?php echo $i+1; ?>: <?php echo $m; ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo $row['full_marks'] !== null ? $row['full_marks'] : '—'; ?></strong></td>
                    <td><?php echo $row['marks_percentage'] !== null ? number_format((float)$row['marks_percentage'], 2) . '%' : '—'; ?></td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : statusBadge('PENDING'); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editResult<?php echo $row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No records found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($allRows as $row):
    $asgList = [];
    $examList = [];

    if (!empty($row['assignments_json'])) {
        $decoded = json_decode($row['assignments_json'], true);
        if (is_array($decoded)) $asgList = $decoded;
    } else {
        if ($row['assignment1'] !== null && $row['assignment1'] !== '') $asgList[] = $row['assignment1'];
        if ($row['assignment2'] !== null && $row['assignment2'] !== '') $asgList[] = $row['assignment2'];
        if ($row['assignment3'] !== null && $row['assignment3'] !== '') $asgList[] = $row['assignment3'];
    }

    if (!empty($row['exams_json'])) {
        $decoded = json_decode($row['exams_json'], true);
        if (is_array($decoded)) $examList = $decoded;
    } else {
        if ($row['final_exam'] !== null && $row['final_exam'] !== '') $examList[] = $row['final_exam'];
    }
?>
<div class="modal fade" id="editResult<?php echo $row['student_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" class="result-form">
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

                    <p class="text-muted mb-3">
                        <?php echo sanitize($row['full_name']); ?> | 
                        <?php echo sanitize($row['course_code'].'/'.$row['batch_code']); ?>
                    </p>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">Assignments</label>
                            <button type="button" class="btn btn-sm btn-outline-success add-assignment">
                                <i class="bi bi-plus"></i> Add Assignment
                            </button>
                        </div>
                        <div class="assignments-container row g-2">
                            <?php 
                            $asgCount = max(count($asgList), 1);
                            for ($i = 0; $i < $asgCount; $i++): 
                                $val = $asgList[$i] ?? '';
                            ?>
                            <div class="col-md-4 assignment-item">
                                <div class="input-group">
                                    <span class="input-group-text">A<?php echo $i+1; ?></span>
                                    <input type="number" step="0.01" name="assignments[]" class="form-control mark-input" value="<?php echo $val; ?>" placeholder="Marks">
                                    <button type="button" class="btn btn-outline-danger remove-item" <?php echo $asgCount <= 1 ? 'disabled' : ''; ?>>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">Exams</label>
                            <button type="button" class="btn btn-sm btn-outline-success add-exam">
                                <i class="bi bi-plus"></i> Add Exam
                            </button>
                        </div>
                        <div class="exams-container row g-2">
                            <?php 
                            $examCount = max(count($examList), 1);
                            for ($i = 0; $i < $examCount; $i++): 
                                $val = $examList[$i] ?? '';
                            ?>
                            <div class="col-md-4 exam-item">
                                <div class="input-group">
                                    <span class="input-group-text">E<?php echo $i+1; ?></span>
                                    <input type="number" step="0.01" name="exams[]" class="form-control mark-input" value="<?php echo $val; ?>" placeholder="Marks">
                                    <button type="button" class="btn btn-outline-danger remove-item" <?php echo $examCount <= 1 ? 'disabled' : ''; ?>>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Full Marks <small class="text-muted">(Auto)</small></label>
                            <input type="number" step="0.01" name="full_marks" class="form-control full-marks-input" value="<?php echo $row['full_marks'] ?? ''; ?>" readonly style="background:#f8f9fa;font-weight:bold;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Percentage (%) <small class="text-muted">(Auto)</small></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="marks_percentage" class="form-control percentage-input" value="<?php echo $row['marks_percentage'] ?? ''; ?>" style="background:#f8f9fa;font-weight:bold;">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
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
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function calculateMarks(form) {
        let total = 0;
        let count = 0;

        form.querySelectorAll('.mark-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                total += val;
                count++;
            }
        });

        const fullInput = form.querySelector('.full-marks-input');
        const pctInput  = form.querySelector('.percentage-input');

        if (fullInput) {
            fullInput.value = total > 0 ? total.toFixed(2) : '';
        }

        if (pctInput) {
            pctInput.value = count > 0 ? (total / count).toFixed(2) : '';
        }
    }

    document.body.addEventListener('input', function (e) {
        if (e.target.classList.contains('mark-input')) {
            const form = e.target.closest('form');
            if (form) calculateMarks(form);
        }
    });

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.add-assignment');
        if (!btn) return;
        e.preventDefault();

        const container = btn.closest('.mb-4').querySelector('.assignments-container');
        const count = container.querySelectorAll('.assignment-item').length + 1;

        const div = document.createElement('div');
        div.className = 'col-md-4 assignment-item';
        div.innerHTML = `
            <div class="input-group">
                <span class="input-group-text">A${count}</span>
                <input type="number" step="0.01" name="assignments[]" class="form-control mark-input" placeholder="Marks">
                <button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button>
            </div>`;
        container.appendChild(div);
        updateRemoveButtons(container);
        calculateMarks(btn.closest('form'));
    });

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.add-exam');
        if (!btn) return;
        e.preventDefault();

        const container = btn.closest('.mb-4').querySelector('.exams-container');
        const count = container.querySelectorAll('.exam-item').length + 1;

        const div = document.createElement('div');
        div.className = 'col-md-4 exam-item';
        div.innerHTML = `
            <div class="input-group">
                <span class="input-group-text">E${count}</span>
                <input type="number" step="0.01" name="exams[]" class="form-control mark-input" placeholder="Marks">
                <button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button>
            </div>`;
        container.appendChild(div);
        updateRemoveButtons(container);
        calculateMarks(btn.closest('form'));
    });

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-item');
        if (!btn) return;
        e.preventDefault();

        const item = btn.closest('.assignment-item, .exam-item');
        const container = item.parentElement;
        const form = item.closest('form');

        item.remove();
        renumberItems(container);
        updateRemoveButtons(container);
        if (form) calculateMarks(form);
    });

    function renumberItems(container) {
        const prefix = container.classList.contains('assignments-container') ? 'A' : 'E';
        container.querySelectorAll('.assignment-item, .exam-item').forEach((item, i) => {
            item.querySelector('.input-group-text').textContent = prefix + (i + 1);
        });
    }

    function updateRemoveButtons(container) {
        const items = container.querySelectorAll('.assignment-item, .exam-item');
        items.forEach(item => {
            item.querySelector('.remove-item').disabled = items.length <= 1;
        });
    }

    document.querySelectorAll('.result-form').forEach(form => calculateMarks(form));
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>