<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_helper.php';
require_once __DIR__ . '/../includes/payment_import.php';   // shared payment importer (also used by results.php)
requireLogin();
$pageTitle = 'Payments';
$conn = getConnection();

// make sure columns + payment_status enum are OK
ensurePaymentsSchema($conn);
$hasExtra    = payColExists($conn, 'reg_fee') && payColExists($conn, 'course_paid') && payColExists($conn, 'course_due') && payColExists($conn, 'balance');
$hasInstJson = payColExists($conn, 'installments_json');

/* =====================================================================
   1. EXCEL / CSV UPLOAD  (same importer that results.php uses)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $admin = getAdminUsername();
    $notes = [];

    if (!$hasExtra || !$hasInstJson) {
        setFlash('error', 'payments table is missing columns (reg_fee, course_paid, course_due, balance, installments_json). Please add them in phpMyAdmin.');
        redirect('payments.php');
    }
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please select a valid Excel file. Error code: ' . ($_FILES['excel_file']['error'] ?? 'none'));
        redirect('payments.php');
    }
    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'])) {
        setFlash('error', 'Only .xlsx or .csv allowed. Your file: .' . $ext);
        redirect('payments.php');
    }

    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $records = payCsvRecords($handle);
        fclose($handle);
        if ($records === null)  { setFlash('error', 'Empty CSV'); redirect('payments.php'); }
        if ($records === false) { setFlash('error', 'CSV must have registration_number column'); redirect('payments.php'); }
        $stats = paySaveRecords($conn, $records, $admin, $notes, 'CSV');
        logActivity($admin, 'PAYMENT_CSV_UPLOAD', json_encode($stats));
        $label = 'CSV imported';
    } else {
        $stats = payImportFromXlsx($conn, $file['tmp_name'], $admin, $notes);
        logActivity($admin, 'PAYMENT_UPLOAD', json_encode($stats));
        $label = 'Payments imported';
    }

    setFlash('success', "$label. Updated: {$stats['students']} | Completed: {$stats['completed']} | One time: {$stats['one_time']} | Partial: {$stats['partial']} | Not paid: {$stats['none']} | Not found: {$stats['notfound']} | No data: {$stats['nodata']} | Failed: {$stats['failed']}");
    if ($notes) $_SESSION['upload_errors'] = array_slice($notes, 0, 60);
    redirect('payments.php');
}

/* =====================================================================
   2. SINGLE UPDATE (modal)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $status     = payStatusSafe($_POST['payment_status'] ?? 'PENDING');
    $reg_fee    = (isset($_POST['reg_fee']) && $_POST['reg_fee'] !== '') ? (float)$_POST['reg_fee'] : 0.0;
    $course_due = (isset($_POST['course_due']) && $_POST['course_due'] !== '') ? (float)$_POST['course_due'] : 0.0;
    $pdate      = (!empty($_POST['payment_date'])) ? $_POST['payment_date'] : null;   // NULL, not '' (strict mode safe)
    $notes      = trim($_POST['notes'] ?? '');
    $admin      = getAdminUsername();

    $instAmounts = [];
    $course_paid = 0.0;
    if (!empty($_POST['inst']) && is_array($_POST['inst'])) {
        foreach ($_POST['inst'] as $n => $val) {
            $n = (int)$n;
            if ($n < 1 || $n > 6) continue;
            $val = trim((string)$val);
            if ($val === '') continue;
            $clean = str_replace(',', '', $val);
            if (strtoupper($val) === 'PAID') {
                $instAmounts[$n] = 'PAID';
            } elseif (is_numeric($clean)) {
                $instAmounts[$n] = (float)$clean;
                $course_paid += (float)$clean;
            }
        }
    }
    ksort($instAmounts);
    $instJson = empty($instAmounts) ? '{}' : json_encode($instAmounts, JSON_FORCE_OBJECT);
    $balance  = in_array($status, ['COMPLETED', 'ONE_TIME', 'PAID', 'CONVA_PAY']) && $course_due > 0 && $course_paid >= $course_due
                ? 0.0 : max(0, $course_due - $course_paid);
    $amount   = $reg_fee + $course_paid;

    if ($student_id <= 0) {
        setFlash('error', 'Invalid student');
    } elseif (!$hasExtra || !$hasInstJson) {
        setFlash('error', 'payments table columns are missing.');
    } else {
        $r = savePaymentRow($conn, $student_id, $status, $amount, $pdate, $notes, $admin,
                            $reg_fee, $course_paid, $course_due, $balance, $instJson);
        if ($r === true) {
            refreshCertificate($conn, $student_id, $status);
            logActivity($admin, 'UPDATE_PAYMENT', "Student:$student_id Status:$status");
            setFlash('success', 'Payment updated. Status: ' . $status);
        } else {
            setFlash('error', 'Error: ' . $r);
        }
    }
    redirect('payments.php?' . http_build_query(array_filter([
        'status'   => $_POST['redirect_status'] ?? '',
        'batch_id' => $_POST['redirect_batch'] ?? '',
        'q'        => $_POST['redirect_q'] ?? ''
    ])));
}

/* =====================================================================
   3. LIST
   ===================================================================== */
$filterStatus = $_GET['status'] ?? '';
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search = trim($_GET['q'] ?? '');

$batchesArr = [];
$q = $conn->query("SELECT b.id, b.batch_code, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
while ($b = $q->fetch_assoc()) $batchesArr[] = $b;

$sql = "SELECT s.id AS student_id, s.registration_number, s.full_name, s.phone,
        b.batch_code, c.course_code,
        p.payment_status, p.amount, p.payment_date, p.notes" .
        ($hasExtra ? ", p.reg_fee, p.course_paid, p.course_due, p.balance" : ", NULL AS reg_fee, NULL AS course_paid, NULL AS course_due, NULL AS balance") .
        ($hasInstJson ? ", p.installments_json" : ", NULL AS installments_json") . "
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN payments p ON s.id = p.student_id
    WHERE s.is_active = 1";
if ($filterBatch) $sql .= " AND b.id = $filterBatch";
if ($filterStatus) $sql .= " AND p.payment_status = '" . $conn->real_escape_string($filterStatus) . "'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%')";
}
$sql .= " ORDER BY c.course_code, b.batch_code, s.registration_number";
$list = [];
$resList = $conn->query($sql);
if ($resList) while ($x = $resList->fetch_assoc()) $list[] = $x;

$sumTotal = 0; $sumBalance = 0; $sumReg = 0; $sumCourse = 0;
foreach ($list as $x) {
    $sumTotal   += (float)($x['amount'] ?? 0);
    $sumReg     += (float)($x['reg_fee'] ?? 0);
    $sumCourse  += (float)($x['course_paid'] ?? 0);
    $sumBalance += (float)($x['balance'] ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#payUploadModal">
        <i class="bi bi-file-earmark-excel me-1"></i> Upload Payments Excel
    </button>
</div>

<?php if (!empty($_SESSION['upload_errors'])): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <strong>Import details:</strong>
    <ul class="mb-0 mt-1 small">
        <?php foreach ($_SESSION['upload_errors'] as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['upload_errors']); endif; ?>

<div class="modal fade" id="payUploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> Upload Payments from HDICRA.xlsx</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">HDICRA.xlsx</label>
                    <input type="file" name="excel_file" class="form-control" accept=".xlsx,.csv" required>
                    <small class="text-muted">Reads Registration Fee + 1st…6th Installment of every Batch sheet (Batch 1–8). The same data is also saved automatically when you upload HDICRA.xlsx in <strong>Results</strong>.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload"></i> Upload &amp; Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Total received</div><div class="fs-5 fw-bold text-success">Rs <?php echo number_format($sumTotal, 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Registration fees</div><div class="fs-5 fw-bold">Rs <?php echo number_format($sumReg, 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Course fees paid</div><div class="fs-5 fw-bold">Rs <?php echo number_format($sumCourse, 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Balance to collect</div><div class="fs-5 fw-bold text-danger">Rs <?php echo number_format($sumBalance, 0); ?></div></div></div></div>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Batch</label>
            <select name="batch_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($batchesArr as $b): ?>
                <option value="<?php echo (int)$b['id']; ?>" <?php echo $filterBatch == $b['id'] ? 'selected' : ''; ?>><?php echo sanitize($b['course_code'] . '/' . $b['batch_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['PAID','COMPLETED','ONE_TIME','CONVA_PAY','PENDING','NOT_PAID'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $filterStatus == $st ? 'selected' : ''; ?>><?php echo str_replace('_', ' ', $st); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
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
                    <th>Status</th>
                    <th class="text-end">Reg Fee</th>
                    <th>Installments</th>
                    <th class="text-end">Course Paid</th>
                    <th class="text-end">Balance</th>
                    <th class="text-end">Total</th>
                    <th>Date</th>
                    <th width="70">Edit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($list) > 0): foreach ($list as $row):
                $insts = parseInstJson($row['installments_json'] ?? '');
                ksort($insts);
            ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?><br><small class="text-muted"><?php echo sanitize($row['phone'] ?: ''); ?></small></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : statusBadge('PENDING'); ?></td>
                    <td class="text-end"><?php echo ($row['reg_fee'] !== null && (float)$row['reg_fee'] > 0) ? number_format((float)$row['reg_fee'], 0) : '—'; ?></td>
                    <td>
                        <?php if ($insts): foreach ($insts as $n => $a): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1">#<?php echo (int)$n; ?>: <?php echo is_numeric($a) ? number_format((float)$a, 0) : sanitize((string)$a); ?></span>
                        <?php endforeach; else: echo '<span class="text-muted">—</span>'; endif; ?>
                    </td>
                    <td class="text-end"><?php
                        if ($row['course_paid'] !== null) {
                            echo number_format((float)$row['course_paid'], 0);
                            if ($row['course_due'] !== null) echo '<small class="text-muted"> / ' . number_format((float)$row['course_due'], 0) . '</small>';
                        } else echo '—';
                    ?></td>
                    <td class="text-end"><?php
                        if ($row['balance'] !== null) {
                            echo (float)$row['balance'] > 0
                                ? '<span class="text-danger">' . number_format((float)$row['balance'], 0) . '</span>'
                                : '<span class="text-success">0</span>';
                        } else echo '—';
                    ?></td>
                    <td class="text-end"><strong><?php echo ($row['amount'] !== null && (float)$row['amount'] > 0) ? number_format((float)$row['amount'], 0) : '—'; ?></strong></td>
                    <td><?php echo $row['payment_date'] ?: '—'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#payModal<?php echo (int)$row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="text-center text-muted py-5">No records</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* Modals are rendered OUTSIDE the table (valid HTML) */ ?>
<?php foreach ($list as $row):
    $insts = parseInstJson($row['installments_json'] ?? '');
?>
<div class="modal fade" id="payModal<?php echo (int)$row['student_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" class="pay-edit-form">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Payment — <?php echo sanitize($row['registration_number']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="student_id" value="<?php echo (int)$row['student_id']; ?>">
                    <input type="hidden" name="redirect_status" value="<?php echo sanitize($filterStatus); ?>">
                    <input type="hidden" name="redirect_batch" value="<?php echo $filterBatch; ?>">
                    <input type="hidden" name="redirect_q" value="<?php echo sanitize($search); ?>">
                    <p class="text-muted mb-3"><?php echo sanitize($row['full_name']); ?> | <?php echo sanitize($row['course_code'] . '/' . $row['batch_code']); ?></p>

                    <div class="mb-3">
                        <label class="form-label">Payment Status *</label>
                        <select name="payment_status" class="form-select" required>
                            <?php foreach (['PENDING','PAID','COMPLETED','ONE_TIME','CONVA_PAY','NOT_PAID'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo ($row['payment_status'] ?? '') == $st ? 'selected' : ''; ?>><?php echo str_replace('_', ' ', $st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Registration Fee</label>
                            <input type="number" step="0.01" name="reg_fee" class="form-control calc-field" value="<?php echo htmlspecialchars((string)($row['reg_fee'] ?? '')); ?>" placeholder="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Course Due (full fee)</label>
                            <input type="number" step="0.01" name="course_due" class="form-control calc-field" value="<?php echo htmlspecialchars((string)($row['course_due'] ?? '')); ?>" placeholder="30000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo htmlspecialchars((string)($row['payment_date'] ?? '')); ?>">
                        </div>
                    </div>

                    <div class="mb-2"><label class="form-label fw-semibold mb-1">Installments (1st – 6th)</label></div>
                    <div class="row g-2 mb-3">
                        <?php for ($i = 1; $i <= 6; $i++):
                            $val = $insts[$i] ?? $insts[(string)$i] ?? '';
                            if (!($val === 'PAID' || is_numeric($val))) $val = '';
                            if (is_numeric($val)) $val = (float)$val == (int)$val ? (int)$val : (float)$val;
                        ?>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text">#<?php echo $i; ?></span>
                                <input type="text" name="inst[<?php echo $i; ?>]" class="form-control calc-field inst-input"
                                       value="<?php echo htmlspecialchars((string)$val); ?>" placeholder="Amount or PAID">
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Course Paid (auto sum)</label>
                            <input type="number" step="0.01" class="form-control course-paid-field" value="<?php echo htmlspecialchars((string)($row['course_paid'] ?? '')); ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Balance (auto)</label>
                            <input type="number" step="0.01" class="form-control balance-field" value="<?php echo htmlspecialchars((string)($row['balance'] ?? '')); ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total (Reg + Course)</label>
                            <input type="number" step="0.01" class="form-control total-field" value="<?php echo htmlspecialchars((string)($row['amount'] ?? '')); ?>" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('.pay-edit-form').forEach(function (form) {
    function recalc() {
        let coursePaid = 0;
        form.querySelectorAll('.inst-input').forEach(function (inp) {
            const v = inp.value.replace(/,/g, '').trim();
            if (v !== '' && !isNaN(parseFloat(v)) && isFinite(v)) coursePaid += parseFloat(v);
        });
        const reg = parseFloat(form.querySelector('[name=reg_fee]').value) || 0;
        const due = parseFloat(form.querySelector('[name=course_due]').value) || 0;
        form.querySelector('.course-paid-field').value = coursePaid.toFixed(2);
        form.querySelector('.balance-field').value = Math.max(0, due - coursePaid).toFixed(2);
        form.querySelector('.total-field').value = (reg + coursePaid).toFixed(2);
    }
    form.querySelectorAll('.calc-field').forEach(function (inp) { inp.addEventListener('input', recalc); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>