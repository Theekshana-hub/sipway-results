<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Certificates';
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $student_id = (int)$_POST['student_id'];
        $status = $_POST['certificate_status'] ?? 'PENDING';
        $cnum = trim($_POST['certificate_number'] ?? '');
        $idate = $_POST['issue_date'] !== '' ? $_POST['issue_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $admin = getAdminUsername();

        $check = $conn->query("SELECT id FROM certificates WHERE student_id=$student_id");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE certificates SET certificate_status=?, certificate_number=?, issue_date=?, notes=?, updated_by=? WHERE student_id=?");
            $stmt->bind_param("sssssi", $status, $cnum, $idate, $notes, $admin, $student_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO certificates (student_id, certificate_status, certificate_number, issue_date, notes, updated_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("isssss", $student_id, $status, $cnum, $idate, $notes, $admin);
        }
        if ($stmt->execute()) {
            logActivity($admin, 'UPDATE_CERTIFICATE', "Student:$student_id Status:$status");
            setFlash('success', 'Certificate status updated.');
        }
        $stmt->close();
        redirect('certificates.php');
    }
}

$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

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
if ($filterStatus) $sql .= " AND cert.certificate_status = '".$conn->real_escape_string($filterStatus)."'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%')";
}
$sql .= " ORDER BY s.id DESC";
$rows = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['READY','ISSUED','PENDING','NOT_ELIGIBLE'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $filterStatus==$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Reg No / Name">
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
                    <th>Cert. No</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows->num_rows > 0): while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : '—'; ?></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : '—'; ?></td>
                    <td><?php echo $row['certificate_status'] ? statusBadge($row['certificate_status']) : statusBadge('PENDING'); ?></td>
                    <td><?php echo sanitize($row['certificate_number'] ?: '—'); ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#certModal<?php echo $row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>

                <div class="modal fade" id="certModal<?php echo $row['student_id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Certificate — <?php echo sanitize($row['registration_number']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                    <p class="text-muted"><?php echo sanitize($row['full_name']); ?> | <?php echo sanitize($row['course_name']); ?></p>
                                    <div class="mb-3">
                                        <label class="form-label">Certificate Status *</label>
                                        <select name="certificate_status" class="form-select" required>
                                            <?php foreach (['PENDING','READY','ISSUED','NOT_ELIGIBLE'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo ($row['certificate_status']??'')==$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label class="form-label">Certificate Number</label>
                                            <input type="text" name="certificate_number" class="form-control" value="<?php echo sanitize($row['certificate_number'] ?? ''); ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Issue Date</label>
                                            <input type="date" name="issue_date" class="form-control" value="<?php echo $row['issue_date'] ?? ''; ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3 mt-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="2"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No records</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
