<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Certificates';
$conn = getConnection();

/**
 * Auto-generate certificate number
 * Format: CERT/{COURSE_CODE}/{BATCH_CODE}/{YEAR}/{SEQ}
 * Example: CERT/RL/B10/2026/0001
 */
function generateCertificateNumber($conn, $student_id) {
    $student_id = (int)$student_id;
    $info = $conn->query("
        SELECT c.course_code, b.batch_code
        FROM students s
        JOIN batches b ON s.batch_id = b.id
        JOIN courses c ON b.course_id = c.id
        WHERE s.id = $student_id
    ")->fetch_assoc();

    $courseCode = preg_replace('/[^A-Za-z0-9]/', '', $info['course_code'] ?? 'GEN');
    $batchCode  = preg_replace('/[^A-Za-z0-9]/', '', $info['batch_code'] ?? '00');
    $year       = date('Y');

    $prefix = "CERT/{$courseCode}/{$batchCode}/{$year}/";
    $like = $conn->real_escape_string($prefix . '%');

    $res = $conn->query("
        SELECT certificate_number FROM certificates
        WHERE certificate_number LIKE '$like'
        ORDER BY id DESC
        LIMIT 1
    ");

    $nextSeq = 1;
    if ($res && $res->num_rows > 0) {
        $last = $res->fetch_assoc()['certificate_number'];
        if (preg_match('/(\d{4})$/', $last, $m)) {
            $nextSeq = (int)$m[1] + 1;
        }
    }

    return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $student_id = (int)$_POST['student_id'];
        $status = $_POST['certificate_status'] ?? 'PENDING';
        $cnum = trim($_POST['certificate_number'] ?? '');
        $idate = trim($_POST['issue_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $admin = getAdminUsername();
        $auto_generate = !empty($_POST['auto_generate']);

        // Auto-generate number
        if ($auto_generate || ($cnum === '' && in_array($status, ['READY', 'ISSUED']))) {
            $cnum = generateCertificateNumber($conn, $student_id);
        }

        // Issue date: if ISSUED and empty → today
        if ($status === 'ISSUED' && $idate === '') {
            $idate = date('Y-m-d');
        }
        if ($idate === '') {
            $idate = null;
        }

        // Ensure certificate row exists
        $check = $conn->query("SELECT id, certificate_number FROM certificates WHERE student_id = $student_id");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE certificates SET certificate_status = ?, certificate_number = ?, issue_date = ?, notes = ?, updated_by = ? WHERE student_id = ?");
            $stmt->bind_param("sssssi", $status, $cnum, $idate, $notes, $admin, $student_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO certificates (student_id, certificate_status, certificate_number, issue_date, notes, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $student_id, $status, $cnum, $idate, $notes, $admin);
        }

        if ($stmt->execute()) {
            logActivity($admin, 'UPDATE_CERTIFICATE', "Student:$student_id Status:$status CertNo:$cnum");
            $msg = 'Certificate updated successfully.';
            if ($cnum !== '') {
                $msg .= ' Cert No: ' . $cnum;
            }
            setFlash('success', $msg);
        } else {
            setFlash('error', 'Error: ' . $stmt->error);
        }
        $stmt->close();

        $qs = [];
        if (!empty($_POST['redirect_status'])) $qs['status'] = $_POST['redirect_status'];
        if (!empty($_POST['redirect_q'])) $qs['q'] = $_POST['redirect_q'];
        if (!empty($_POST['redirect_course'])) $qs['course_id'] = $_POST['redirect_course'];
        if (!empty($_POST['redirect_batch'])) $qs['batch_id'] = $_POST['redirect_batch'];
        redirect('certificates.php' . ($qs ? '?' . http_build_query($qs) : ''));
    }

    // Bulk auto-generate
    if ($action === 'bulk_generate') {
        $admin = getAdminUsername();
        $list = $conn->query("
            SELECT student_id FROM certificates
            WHERE certificate_status IN ('READY', 'ISSUED')
              AND (certificate_number IS NULL OR certificate_number = '')
        ");
        $count = 0;
        if ($list) {
            while ($r = $list->fetch_assoc()) {
                $sid = (int)$r['student_id'];
                $cnum = generateCertificateNumber($conn, $sid);
                $cnumEsc = $conn->real_escape_string($cnum);
                $conn->query("UPDATE certificates SET certificate_number = '$cnumEsc', updated_by = '$admin' WHERE student_id = $sid");
                $count++;
            }
        }
        // Also for students who have READY via results but no certificate row yet
        $list2 = $conn->query("
            SELECT s.id AS student_id
            FROM students s
            LEFT JOIN certificates cert ON s.id = cert.student_id
            LEFT JOIN results r ON s.id = r.student_id
            LEFT JOIN payments p ON s.id = p.student_id
            WHERE s.is_active = 1
              AND cert.id IS NULL
              AND r.result_status = 'PRESENT'
              AND p.payment_status IN ('PAID','COMPLETED','ONE_TIME','CONVA_PAY')
        ");
        if ($list2) {
            while ($r = $list2->fetch_assoc()) {
                $sid = (int)$r['student_id'];
                $cnum = generateCertificateNumber($conn, $sid);
                $cnumEsc = $conn->real_escape_string($cnum);
                $conn->query("INSERT INTO certificates (student_id, certificate_status, certificate_number, updated_by) VALUES ($sid, 'READY', '$cnumEsc', '$admin')");
                $count++;
            }
        }
        logActivity($admin, 'BULK_GENERATE_CERT', "Generated $count numbers");
        setFlash('success', "Auto-generated certificate numbers for $count student(s).");
        redirect('certificates.php');
    }
}

$filterStatus = $_GET['status'] ?? '';
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search = trim($_GET['q'] ?? '');

$coursesList = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
$coursesArr = [];
while ($c = $coursesList->fetch_assoc()) $coursesArr[] = $c;

$batchesList = $conn->query("SELECT b.id, b.batch_code, b.batch_name, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
$batchesArr = [];
while ($b = $batchesList->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.id AS student_id, s.registration_number, s.full_name,
           b.batch_code, c.course_code, c.course_name,
           r.result_status, r.full_marks, r.marks_percentage,
           p.payment_status,
           cert.certificate_status, cert.certificate_number, cert.issue_date, cert.notes
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN results r ON s.id = r.student_id
    LEFT JOIN payments p ON s.id = p.student_id
    LEFT JOIN certificates cert ON s.id = cert.student_id
    WHERE s.is_active = 1
";
if ($filterStatus !== '') {
    $sql .= " AND cert.certificate_status = '" . $conn->real_escape_string($filterStatus) . "'";
}
if ($filterCourse) $sql .= " AND c.id = $filterCourse";
if ($filterBatch)  $sql .= " AND b.id = $filterBatch";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%' OR IFNULL(cert.certificate_number,'') LIKE '%$s%')";
}
$sql .= " ORDER BY s.id DESC";
$rows = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
    <form method="POST" class="d-inline" onsubmit="return confirm('Generate certificate numbers for all READY/ISSUED students without a number?');">
        <input type="hidden" name="action" value="bulk_generate">
        <button type="submit" class="btn btn-outline-primary">
            <i class="bi bi-magic me-1"></i> Auto-Generate Missing Numbers
        </button>
    </form>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['READY','ISSUED','PENDING','NOT_ELIGIBLE'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $filterStatus===$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Reg No / Name / Cert No">
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
                    <th>Result</th>
                    <th>Payment</th>
                    <th>Certificate</th>
                    <th>Certificate Number</th>
                    <th>Issue Date</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td>
                        <small><?php echo sanitize($row['course_code']); ?></small>
                        <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span>
                    </td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : '—'; ?></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : '—'; ?></td>
                    <td><?php echo $row['certificate_status'] ? statusBadge($row['certificate_status']) : statusBadge('PENDING'); ?></td>
                    <td>
                        <?php if (!empty($row['certificate_number'])): ?>
                            <strong style="color:#e31c23; font-family:monospace; font-size:0.9rem;">
                                <?php echo sanitize($row['certificate_number']); ?>
                            </strong>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo !empty($row['issue_date']) ? sanitize($row['issue_date']) : '—'; ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#certModal<?php echo (int)$row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>

                <!-- Modal: must be outside <tr> for valid HTML - placed after table is better but works in most browsers -->
                <div class="modal fade" id="certModal<?php echo (int)$row['student_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <div class="modal-header">
                                    <h5 class="modal-title">Certificate — <?php echo sanitize($row['registration_number']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$row['student_id']; ?>">
                                    <input type="hidden" name="redirect_status" value="<?php echo sanitize($filterStatus); ?>">
                                    <input type="hidden" name="redirect_q" value="<?php echo sanitize($search); ?>">
                                    <input type="hidden" name="redirect_course" value="<?php echo $filterCourse; ?>">
                                    <input type="hidden" name="redirect_batch" value="<?php echo $filterBatch; ?>">

                                    <p class="text-muted mb-3">
                                        <?php echo sanitize($row['full_name']); ?> |
                                        <?php echo sanitize($row['course_code'] . ' / ' . $row['batch_code']); ?>
                                    </p>

                                    <div class="mb-3">
                                        <label class="form-label">Certificate Status *</label>
                                        <select name="certificate_status" class="form-select" required>
                                            <?php foreach (['PENDING','READY','ISSUED','NOT_ELIGIBLE'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo (($row['certificate_status'] ?? '') === $st) ? 'selected' : ''; ?>>
                                                <?php echo str_replace('_', ' ', $st); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Certificate Number</label>
                                        <input type="text" name="certificate_number" class="form-control"
                                               value="<?php echo sanitize($row['certificate_number'] ?? ''); ?>"
                                               placeholder="Empty = auto generate when Auto is checked">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="auto_generate" value="1"
                                                   id="autoGen<?php echo (int)$row['student_id']; ?>"
                                                   <?php echo empty($row['certificate_number']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="autoGen<?php echo (int)$row['student_id']; ?>">
                                                <strong>Auto-generate number</strong>
                                                <small class="text-muted">(Format: CERT/<?php echo sanitize($row['course_code']); ?>/<?php echo sanitize($row['batch_code']); ?>/<?php echo date('Y'); ?>/0001)</small>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Issue Date</label>
                                        <input type="date" name="issue_date" class="form-control"
                                               value="<?php echo sanitize($row['issue_date'] ?? ''); ?>">
                                        <small class="text-muted">If status is ISSUED and date is empty → today's date is set automatically</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="2"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save Certificate</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">No records found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>