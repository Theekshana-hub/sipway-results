<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Payments';
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $student_id = (int)$_POST['student_id'];
        $status = $_POST['payment_status'] ?? 'PENDING';
        $amount = $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
        $pdate  = $_POST['payment_date'] !== '' ? $_POST['payment_date'] : null;
        $notes  = trim($_POST['notes'] ?? '');
        $admin  = getAdminUsername();

        $check = $conn->query("SELECT id FROM payments WHERE student_id=$student_id");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=? WHERE student_id=?");
            $stmt->bind_param("sdsssi", $status, $amount, $pdate, $notes, $admin, $student_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("isdsss", $student_id, $status, $amount, $pdate, $notes, $admin);
        }
        if ($stmt->execute()) {
            // Update certificate eligibility
            $res = $conn->query("SELECT result_status, full_marks, marks_percentage FROM results WHERE student_id=$student_id")->fetch_assoc();
            $payOk = in_array($status, ['PAID','COMPLETED','ONE_TIME','CONVA_PAY']);
            $passOk = $res && $res['result_status']==='PRESENT' && (
                ($res['full_marks'] !== null && $res['full_marks'] >= 40) ||
                ($res['marks_percentage'] !== null && $res['marks_percentage'] >= 40)
            );
            if ($payOk && $passOk) {
                $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id");
            } elseif (!$payOk) {
                $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
            }
            logActivity($admin, 'UPDATE_PAYMENT', "Student:$student_id Status:$status");
            setFlash('success', 'Payment updated.');
        }
        $stmt->close();
        redirect('payments.php');
    }
}

$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT s.id AS student_id, s.registration_number, s.full_name, s.phone,
           b.batch_code, c.course_code,
           p.payment_status, p.amount, p.payment_date, p.notes
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN payments p ON s.id = p.student_id
    WHERE s.is_active = 1
";
if ($filterStatus) $sql .= " AND p.payment_status = '".$conn->real_escape_string($filterStatus)."'";
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
                <?php foreach (['PAID','COMPLETED','ONE_TIME','CONVA_PAY','PENDING','NOT_PAID'] as $st): ?>
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
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows->num_rows > 0): while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php echo sanitize($row['phone'] ?: '—'); ?></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : statusBadge('PENDING'); ?></td>
                    <td><?php echo $row['amount'] !== null ? number_format($row['amount'], 2) : '—'; ?></td>
                    <td><?php echo $row['payment_date'] ?: '—'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#payModal<?php echo $row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>

                <div class="modal fade" id="payModal<?php echo $row['student_id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Update Payment — <?php echo sanitize($row['registration_number']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                    <p class="text-muted"><?php echo sanitize($row['full_name']); ?></p>
                                    <div class="mb-3">
                                        <label class="form-label">Payment Status *</label>
                                        <select name="payment_status" class="form-select" required>
                                            <?php foreach (['PENDING','PAID','COMPLETED','ONE_TIME','CONVA_PAY','NOT_PAID'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo ($row['payment_status']??'')==$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label class="form-label">Amount</label>
                                            <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo $row['amount'] ?? ''; ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Payment Date</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo $row['payment_date'] ?? ''; ?>">
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
