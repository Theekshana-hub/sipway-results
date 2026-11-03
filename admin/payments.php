<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_helper.php';
requireLogin();
$pageTitle = 'Payments';
$conn = getConnection();

// Extra amount columns
$hasExtra = false;
try {
    $chk = $conn->query("SHOW COLUMNS FROM payments LIKE 'reg_fee'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE payments
            ADD COLUMN reg_fee DECIMAL(10,2) NULL,
            ADD COLUMN course_paid DECIMAL(10,2) NULL,
            ADD COLUMN course_due DECIMAL(10,2) NULL,
            ADD COLUMN balance DECIMAL(10,2) NULL");
    }
    $chk = $conn->query("SHOW COLUMNS FROM payments LIKE 'reg_fee'");
    $hasExtra = ($chk && $chk->num_rows > 0);
} catch (Throwable $e) { $hasExtra = false; }

const PAY_FULL_STATUS     = 'COMPLETED';
const PAY_ONETIME_STATUS  = 'ONE_TIME';
const PAY_PARTIAL_STATUS  = 'PENDING';
const PAY_NONE_STATUS     = 'NOT_PAID';
const DEFAULT_FEE_OLD     = 30000;
const DEFAULT_FEE_NEW     = 32000;
const MAX_SINGLE_PAYMENT  = 40000;

function refreshCertificate($conn, $student_id, $payStatus) {
    $student_id = (int)$student_id;
    $res = $conn->query("SELECT result_status, full_marks, marks_percentage FROM results WHERE student_id=$student_id")->fetch_assoc();
    $payOk = in_array($payStatus, ['PAID', 'COMPLETED', 'ONE_TIME', 'CONVA_PAY']);
    $passOk = $res && $res['result_status'] === 'PRESENT' && (
        ($res['full_marks'] !== null && $res['full_marks'] >= 40) ||
        ($res['marks_percentage'] !== null && $res['marks_percentage'] >= 40)
    );
    if ($payOk && $passOk) {
        $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
    } elseif (!$payOk) {
        $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
    }
}

function parsePayDate($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $f = (float)$v;
        return ($f > 30000 && $f < 80000) ? gmdate('Y-m-d', (int)(($f - 25569) * 86400)) : null;
    }
    $t = strtotime(str_replace('/', '-', $v));
    return ($t && $t > strtotime('2020-01-01') && $t < strtotime('2035-01-01')) ? date('Y-m-d', $t) : null;
}

function payNum($v) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f > 0 && $f <= MAX_SINGLE_PAYMENT) ? $f : null;
}

function colLetterP($i) {
    $s = ''; $i++;
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m - 1, 26); }
    return $s;
}

function moneyFmt($n) { return number_format((float)$n, 0, '.', ','); }

function detectPaymentLayout($rows) {
    $hr = null;
    for ($r = 0; $r < min(30, count($rows)); $r++) {
        for ($c = 0; $c < min(12, count($rows[$r])); $c++) {
            $h = normalizeHeader($rows[$r][$c]);
            if ($h === 'reg no' || $h === 'student id number' || strpos($h, 'reg no') === 0) { $hr = $r; break 2; }
        }
    }
    if ($hr === null || !isset($rows[$hr + 1])) return null;

    $rowA = $rows[$hr];
    $rowB = $rows[$hr + 1];
    $stop = count($rowB);
    foreach ($rowB as $i => $v) {
        if (strpos(normalizeHeader($v), 'batch transfer') !== false) { $stop = $i; break; }
    }
    $groups = [];
    for ($i = 0; $i < $stop; $i++) {
        if (isset($rowB[$i + 1]) && normalizeHeader($rowB[$i + 1]) === 'bank') $groups[] = $i;
    }
    if (count($groups) < 2) return null;

    $regFee = null;
    if (normalizeHeader($rowB[$groups[0]]) === 'amount') $regFee = array_shift($groups);

    $courseCol = null; $offerCol = null;
    foreach ($rowA as $i => $v) {
        $h = normalizeHeader($v);
        if ($h === 'course fee' && $courseCol === null) $courseCol = $i;
        if ($h === 'offer' && $offerCol === null) $offerCol = $i;
    }
    return ['header_row' => $hr, 'regfee' => $regFee, 'inst' => $groups, 'course' => $courseCol, 'offer' => $offerCol, 'rowB' => $rowB];
}

function manualNotesPart($existing) {
    $existing = (string)$existing;
    if ($existing === '') return '';
    if (strpos($existing, '[Excel]') === 0) {
        $p = strpos($existing, ' ## ');
        return $p === false ? '' : substr($existing, $p + 4);
    }
    return $existing;
}

// ========== EXCEL UPLOAD ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $admin = getAdminUsername();
    $stats = ['sheets' => 0, 'students' => 0, 'completed' => 0, 'one_time' => 0, 'partial' => 0, 'none' => 0, 'notfound' => 0, 'nodata' => 0, 'failed' => 0];
    $notes = [];

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please select a valid Excel file.');
        redirect('payments.php');
    }
    $file = $_FILES['excel_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        setFlash('error', 'Only the HDICRA .xlsx file is supported here.');
        redirect('payments.php');
    }
    $allSheets = readXlsxAllSheets($file['tmp_name']);
    if (empty($allSheets)) {
        setFlash('error', 'Could not read the Excel file.');
        redirect('payments.php');
    }

    foreach ($allSheets as $sheetName => $rows) {
        if (empty($rows)) continue;
        $lay = detectPaymentLayout($rows);
        if ($lay === null) {
            $notes[] = "$sheetName: payment columns not found — sheet skipped";
            continue;
        }
        $stats['sheets']++;
        $letters = array_map('colLetterP', $lay['inst']);
        $notes[] = "$sheetName → header row " . ($lay['header_row'] + 1)
                 . " | Reg fee: " . ($lay['regfee'] !== null ? colLetterP($lay['regfee']) : '—')
                 . " | Installments: " . implode(', ', $letters);

        for ($r = $lay['header_row'] + 2; $r < count($rows); $r++) {
            $row = $rows[$r];
            $reg = '';
            foreach ([0, 1, 2] as $ci) {
                $v = cellVal($row, $ci);
                if (isRegNo($v)) { $reg = $v; break; }
            }
            if ($reg === '') continue;

            $st = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
            $st->bind_param("s", $reg);
            $st->execute();
            $stu = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$stu) {
                $stats['notfound']++;
                if (count($notes) < 45) $notes[] = "$sheetName: student not found → $reg";
                continue;
            }
            $sid = (int)$stu['id'];

            $regFee = 0.0; $dates = [];
            if ($lay['regfee'] !== null) {
                $a = payNum(cellVal($row, $lay['regfee']));
                if ($a !== null) {
                    $regFee = $a;
                    $d = parsePayDate(cellVal($row, $lay['regfee'] + 3));
                    if ($d) $dates[] = $d;
                }
            }

            $inst = []; $instSum = 0.0; $paidMarkers = 0;
            foreach ($lay['inst'] as $k => $ci) {
                $raw = cellVal($row, $ci);
                $a = payNum($raw);
                if ($a !== null) {
                    $inst[] = '#' . ($k + 1) . ' ' . moneyFmt($a);
                    $instSum += $a;
                    $d = parsePayDate(cellVal($row, $ci + 3));
                    if ($d) $dates[] = $d;
                } elseif (strtolower(trim($raw)) === 'paid') {
                    $paidMarkers++;
                }
            }

            $batchNo = preg_match('/^[A-Za-z]+\/[A-Za-z]*0*(\d+)\//', $reg, $bm) ? (int)$bm[1] : 99;
            $due = ($batchNo <= 3) ? DEFAULT_FEE_OLD : DEFAULT_FEE_NEW;
            if ($lay['course'] !== null) {
                $cf = payNum(cellVal($row, $lay['course']));
                if ($cf !== null && $cf >= 5000) $due = $cf;
            }
            $plan = '';
            if ($lay['offer'] !== null) {
                $off = strtolower(cellVal($row, $lay['offer']));
                if ($off !== '' && !is_numeric($off)) {
                    if (preg_match('/one\s*time/', $off)) {
                        $plan = 'One time';
                        $due = preg_match('/(\d{4,6})/', $off, $nm) ? (float)$nm[1] : 25000;
                    } elseif (preg_match('/\btow\b|two\s*time/', $off)) {
                        $plan = 'Two time';
                        $due = 28000;
                    } elseif (preg_match('/offer\s*\(?\s*(\d{4,6})/', $off, $nm)) {
                        $plan = 'Offer';
                        $due = (float)$nm[1];
                    }
                }
            }

            $coursePaid = $instSum;
            if ($coursePaid == 0 && $paidMarkers > 0) $coursePaid = $due;
            if ($regFee == 0 && $coursePaid == 0) { $stats['nodata']++; continue; }

            if ($coursePaid + 0.01 >= $due) {
                $status = ($plan === 'One time') ? PAY_ONETIME_STATUS : PAY_FULL_STATUS;
                $stats[$plan === 'One time' ? 'one_time' : 'completed']++;
            } elseif ($coursePaid > 0) {
                $status = PAY_PARTIAL_STATUS;
                $stats['partial']++;
            } else {
                $status = PAY_NONE_STATUS;
                $stats['none']++;
            }

            $total = $regFee + $coursePaid;
            $lastDate = $dates ? max($dates) : null;
            $balance = max(0, $due - $coursePaid);

            $parts = [];
            if ($regFee > 0) $parts[] = 'Reg fee ' . moneyFmt($regFee);
            $parts[] = 'Course ' . moneyFmt($coursePaid) . ' / ' . moneyFmt($due) . ($plan ? " ($plan)" : '');
            if ($balance > 0) $parts[] = 'Balance ' . moneyFmt($balance);
            if ($inst) $parts[] = 'Installments: ' . implode(', ', $inst);
            $excelNote = '[Excel] ' . implode(' | ', $parts);

            $ex = $conn->query("SELECT payment_status, notes FROM payments WHERE student_id=$sid LIMIT 1")->fetch_assoc();
            if ($ex && $ex['payment_status'] === 'CONVA_PAY') $status = 'CONVA_PAY';
            $manual = manualNotesPart($ex['notes'] ?? '');
            $finalNotes = $manual !== '' ? $excelNote . ' ## ' . $manual : $excelNote;

            $regF = (float)$regFee; $cPaid = (float)$coursePaid; $cDue = (float)$due; $bal = (float)$balance; $total = (float)$total;
            if ($ex) {
                if ($hasExtra) {
                    $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=?, reg_fee=?, course_paid=?, course_due=?, balance=? WHERE student_id=?");
                    $stmt->bind_param("sdsssddddi", $status, $total, $lastDate, $finalNotes, $admin, $regF, $cPaid, $cDue, $bal, $sid);
                } else {
                    $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=? WHERE student_id=?");
                    $stmt->bind_param("sdsssi", $status, $total, $lastDate, $finalNotes, $admin, $sid);
                }
            } else {
                if ($hasExtra) {
                    $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by, reg_fee, course_paid, course_due, balance) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("isdsssdddd", $sid, $status, $total, $lastDate, $finalNotes, $admin, $regF, $cPaid, $cDue, $bal);
                } else {
                    $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by) VALUES (?,?,?,?,?,?)");
                    $stmt->bind_param("isdsss", $sid, $status, $total, $lastDate, $finalNotes, $admin);
                }
            }
            if ($stmt->execute()) {
                $stats['students']++;
                refreshCertificate($conn, $sid, $status);
            } else {
                $stats['failed']++;
            }
            $stmt->close();
        }
    }

    logActivity($admin, 'PAYMENT_UPLOAD', json_encode($stats));
    setFlash('success', "Payments imported. Updated: {$stats['students']} | Completed: {$stats['completed']} | One time: {$stats['one_time']} | Partial: {$stats['partial']} | Not found: {$stats['notfound']}");
    if ($notes) $_SESSION['upload_errors'] = array_slice($notes, 0, 45);
    redirect('payments.php');
}

// ========== SINGLE UPDATE (all fields editable) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $student_id = (int)$_POST['student_id'];
    $status = $_POST['payment_status'] ?? 'PENDING';
    $reg_fee = $_POST['reg_fee'] !== '' ? (float)$_POST['reg_fee'] : null;
    $course_paid = $_POST['course_paid'] !== '' ? (float)$_POST['course_paid'] : null;
    $course_due = $_POST['course_due'] !== '' ? (float)$_POST['course_due'] : null;
    $balance = $_POST['balance'] !== '' ? (float)$_POST['balance'] : null;
    $amount = $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
    $pdate = $_POST['payment_date'] !== '' ? $_POST['payment_date'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $admin = getAdminUsername();

    // Auto calc total if empty but parts given
    if ($amount === null && ($reg_fee !== null || $course_paid !== null)) {
        $amount = (float)($reg_fee ?? 0) + (float)($course_paid ?? 0);
    }
    // Auto calc balance if empty
    if ($balance === null && $course_due !== null && $course_paid !== null) {
        $balance = max(0, $course_due - $course_paid);
    }

    $check = $conn->query("SELECT id FROM payments WHERE student_id=$student_id");
    if ($check && $check->num_rows > 0) {
        if ($hasExtra) {
            $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=?, reg_fee=?, course_paid=?, course_due=?, balance=? WHERE student_id=?");
            $stmt->bind_param("sdsssddddi", $status, $amount, $pdate, $notes, $admin, $reg_fee, $course_paid, $course_due, $balance, $student_id);
        } else {
            $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=? WHERE student_id=?");
            $stmt->bind_param("sdsssi", $status, $amount, $pdate, $notes, $admin, $student_id);
        }
    } else {
        if ($hasExtra) {
            $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by, reg_fee, course_paid, course_due, balance) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("isdsssdddd", $student_id, $status, $amount, $pdate, $notes, $admin, $reg_fee, $course_paid, $course_due, $balance);
        } else {
            $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("isdsss", $student_id, $status, $amount, $pdate, $notes, $admin);
        }
    }
    if ($stmt->execute()) {
        refreshCertificate($conn, $student_id, $status);
        logActivity($admin, 'UPDATE_PAYMENT', "Student:$student_id Status:$status");
        setFlash('success', 'Payment updated successfully.');
    } else {
        setFlash('error', 'Error: ' . $stmt->error);
    }
    $stmt->close();
    redirect('payments.php?' . http_build_query(array_filter([
        'status' => $_POST['redirect_status'] ?? '',
        'batch_id' => $_POST['redirect_batch'] ?? '',
        'q' => $_POST['redirect_q'] ?? ''
    ])));
}

// ========== LIST ==========
$filterStatus = $_GET['status'] ?? '';
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search = trim($_GET['q'] ?? '');

$batchesArr = [];
$q = $conn->query("SELECT b.id, b.batch_code, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
while ($b = $q->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.id AS student_id, s.registration_number, s.full_name, s.phone,
           b.batch_code, c.course_code,
           p.payment_status, p.amount, p.payment_date, p.notes" .
           ($hasExtra ? ", p.reg_fee, p.course_paid, p.course_due, p.balance" : ", NULL AS reg_fee, NULL AS course_paid, NULL AS course_due, NULL AS balance") . "
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN payments p ON s.id = p.student_id
    WHERE s.is_active = 1
";
if ($filterBatch)  $sql .= " AND b.id = $filterBatch";
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
                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    <small class="text-muted">Students must be uploaded first. Reads Registration Fee + all installments.</small>
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
                <option value="<?php echo $st; ?>" <?php echo $filterStatus==$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
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
                    <th class="text-end">Course Paid</th>
                    <th class="text-end">Balance</th>
                    <th class="text-end">Total</th>
                    <th>Last Payment</th>
                    <th width="80">Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($list) > 0): foreach ($list as $row): ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?><br><small class="text-muted"><?php echo sanitize($row['phone'] ?: ''); ?></small></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : statusBadge('PENDING'); ?></td>
                    <td class="text-end"><?php echo $row['reg_fee'] !== null ? number_format((float)$row['reg_fee'], 0) : '—'; ?></td>
                    <td class="text-end"><?php echo $row['course_paid'] !== null ? number_format((float)$row['course_paid'], 0) . '<small class="text-muted"> / ' . number_format((float)$row['course_due'], 0) . '</small>' : '—'; ?></td>
                    <td class="text-end"><?php
                        if ($row['balance'] !== null) {
                            echo (float)$row['balance'] > 0 ? '<span class="text-danger">' . number_format((float)$row['balance'], 0) . '</span>' : '<span class="text-success">0</span>';
                        } else echo '—';
                    ?></td>
                    <td class="text-end"><strong><?php echo ($row['amount'] !== null && (float)$row['amount'] > 0) ? number_format((float)$row['amount'], 0) : '—'; ?></strong></td>
                    <td><?php echo $row['payment_date'] ?: '—'; ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#payModal<?php echo $row['student_id']; ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>

                <div class="modal fade" id="payModal<?php echo $row['student_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" class="pay-edit-form">
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Payment — <?php echo sanitize($row['registration_number']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                    <input type="hidden" name="redirect_status" value="<?php echo sanitize($filterStatus); ?>">
                                    <input type="hidden" name="redirect_batch" value="<?php echo $filterBatch; ?>">
                                    <input type="hidden" name="redirect_q" value="<?php echo sanitize($search); ?>">
                                    <p class="text-muted mb-3"><?php echo sanitize($row['full_name']); ?> | <?php echo sanitize($row['course_code'].'/'.$row['batch_code']); ?></p>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Status *</label>
                                        <select name="payment_status" class="form-select" required>
                                            <?php foreach (['PENDING','PAID','COMPLETED','ONE_TIME','CONVA_PAY','NOT_PAID'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo ($row['payment_status']??'')==$st?'selected':''; ?>><?php echo str_replace('_',' ',$st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Registration Fee</label>
                                            <input type="number" step="0.01" name="reg_fee" class="form-control calc-field" value="<?php echo $row['reg_fee'] ?? ''; ?>" placeholder="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Course Paid</label>
                                            <input type="number" step="0.01" name="course_paid" class="form-control calc-field" value="<?php echo $row['course_paid'] ?? ''; ?>" placeholder="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Course Due (full fee)</label>
                                            <input type="number" step="0.01" name="course_due" class="form-control calc-field" value="<?php echo $row['course_due'] ?? ''; ?>" placeholder="30000">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Balance</label>
                                            <input type="number" step="0.01" name="balance" class="form-control balance-field" value="<?php echo $row['balance'] ?? ''; ?>" placeholder="auto">
                                            <small class="text-muted">Auto = Due − Paid</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Total Amount</label>
                                            <input type="number" step="0.01" name="amount" class="form-control total-field" value="<?php echo $row['amount'] ?? ''; ?>" placeholder="auto">
                                            <small class="text-muted">Auto = Reg + Course Paid</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Last Payment Date</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo $row['payment_date'] ?? ''; ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="3"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
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
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="text-center text-muted py-5">No records</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.querySelectorAll('.pay-edit-form').forEach(function(form) {
    function recalc() {
        const reg = parseFloat(form.querySelector('[name=reg_fee]').value) || 0;
        const paid = parseFloat(form.querySelector('[name=course_paid]').value) || 0;
        const due = parseFloat(form.querySelector('[name=course_due]').value) || 0;
        const totalField = form.querySelector('.total-field');
        const balField = form.querySelector('.balance-field');
        if (totalField && !totalField.dataset.manual) {
            totalField.value = (reg + paid).toFixed(2);
        }
        if (balField && !balField.dataset.manual && due > 0) {
            balField.value = Math.max(0, due - paid).toFixed(2);
        }
    }
    form.querySelectorAll('.calc-field').forEach(function(inp) {
        inp.addEventListener('input', recalc);
    });
    const totalField = form.querySelector('.total-field');
    const balField = form.querySelector('.balance-field');
    if (totalField) totalField.addEventListener('input', function(){ this.dataset.manual = '1'; });
    if (balField) balField.addEventListener('input', function(){ this.dataset.manual = '1'; });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>