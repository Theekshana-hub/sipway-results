<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_helper.php';
requireLogin();
$pageTitle = 'Payments';
$conn = getConnection();

// ---- make sure the extra amount columns exist (auto-added once) ----
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

// ============================================================
// SETTINGS  (change here if you want different status names)
// ============================================================
const PAY_FULL_STATUS     = 'COMPLETED';   // all course fee paid
const PAY_ONETIME_STATUS  = 'ONE_TIME';    // paid in one-time offer (Offer column says "one time")
const PAY_PARTIAL_STATUS  = 'PENDING';     // some installments paid, balance remaining
const PAY_NONE_STATUS     = 'NOT_PAID';    // only registration fee paid / no course fee yet
const DEFAULT_FEE_OLD     = 30000;         // Batch 01-03 course fee (when Excel cell is empty)
const DEFAULT_FEE_NEW     = 32000;         // Batch 04+  course fee (when Excel cell is empty)
const MAX_SINGLE_PAYMENT  = 40000;         // numbers above this in fee cells are ignored (they are dates / junk)

// ============================================================
// HELPERS
// ============================================================

/** Certificate status follows result + payment */
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
    if (is_numeric($v)) {                                   // Excel serial date
        $f = (float)$v;
        return ($f > 30000 && $f < 80000) ? gmdate('Y-m-d', (int)(($f - 25569) * 86400)) : null;
    }
    $t = strtotime(str_replace('/', '-', $v));
    return ($t && $t > strtotime('2020-01-01') && $t < strtotime('2035-01-01')) ? date('Y-m-d', $t) : null;
}

/** A real money amount (numeric, >0, not a date serial) or null */
function payNum($v) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f > 0 && $f <= MAX_SINGLE_PAYMENT) ? $f : null;
}

function colLetterP($i) {   // 0-based index -> A, B, ... AA
    $s = '';
    $i++;
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m - 1, 26); }
    return $s;
}

function moneyFmt($n) { return number_format((float)$n, 0, '.', ','); }

/**
 * Find the header (2 rows) of a batch sheet and the payment column groups.
 * Layout in HDICRA.xlsx:
 *   Registration Fee : Amount | Bank | Ref No | Date | Coordinator | Payment Month | Remark
 *   Course Fee / Offer
 *   Installments     : <Fee col> | Bank | Ref No | Date | Coordinator | Payment Month   (x6)
 */
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

    $groups = [];   // columns whose NEXT column is "Bank"
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
        if ($h === 'offer' && $offerCol === null)       $offerCol = $i;
    }

    return ['header_row' => $hr, 'regfee' => $regFee, 'inst' => $groups, 'course' => $courseCol, 'offer' => $offerCol, 'rowB' => $rowB];
}

/** Read the existing manual part of notes (the part after " ## ") */
function manualNotesPart($existing) {
    $existing = (string)$existing;
    if ($existing === '') return '';
    if (strpos($existing, '[Excel]') === 0) {
        $p = strpos($existing, ' ## ');
        return $p === false ? '' : substr($existing, $p + 4);
    }
    return $existing;   // a note somebody typed by hand
}

// ============================================================
// EXCEL / CSV UPLOAD  →  PAYMENTS
// ============================================================
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
                 . " | Reg fee col: " . ($lay['regfee'] !== null ? colLetterP($lay['regfee']) : '—')
                 . " | Installment cols: " . implode(', ', $letters)
                 . " | Course fee col: " . ($lay['course'] !== null ? colLetterP($lay['course']) : '—')
                 . " | Offer col: " . ($lay['offer'] !== null ? colLetterP($lay['offer']) : '—');

        for ($r = $lay['header_row'] + 2; $r < count($rows); $r++) {
            $row = $rows[$r];

            // Reg No is in col A (Batch 06-08) or col B (others)
            $reg = '';
            foreach ([0, 1, 2] as $ci) {
                $v = cellVal($row, $ci);
                if (isRegNo($v)) { $reg = $v; break; }
            }
            if ($reg === '') continue;

            // ---- student ----
            $st = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
            $st->bind_param("s", $reg);
            $st->execute();
            $stu = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$stu) {
                $stats['notfound']++;
                if (count($notes) < 45) $notes[] = "$sheetName Row " . ($r + 1) . ": student not found → $reg (upload students first)";
                continue;
            }
            $sid = (int)$stu['id'];

            // ---- registration fee ----
            $regFee = 0.0; $dates = [];
            if ($lay['regfee'] !== null) {
                $a = payNum(cellVal($row, $lay['regfee']));
                if ($a !== null) {
                    $regFee = $a;
                    $d = parsePayDate(cellVal($row, $lay['regfee'] + 3));
                    if ($d) $dates[] = $d;
                }
            }

            // ---- installments ----
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
                    $paidMarkers++;            // cell just says "PAID" (one-time payers)
                }
            }

            // ---- how much should the student pay? ----
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
            if ($coursePaid == 0 && $paidMarkers > 0) $coursePaid = $due;   // only "PAID" text

            if ($regFee == 0 && $coursePaid == 0) {   // nothing paid yet
                $stats['nodata']++;
                continue;
            }

            // ---- status ----
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

            $total    = $regFee + $coursePaid;
            $lastDate = $dates ? max($dates) : null;
            $balance  = max(0, $due - $coursePaid);

            $parts = [];
            if ($regFee > 0) $parts[] = 'Reg fee ' . moneyFmt($regFee);
            $parts[] = 'Course ' . moneyFmt($coursePaid) . ' / ' . moneyFmt($due) . ($plan ? " ($plan)" : '');
            if ($balance > 0) $parts[] = 'Balance ' . moneyFmt($balance);
            if ($inst) $parts[] = 'Installments: ' . implode(', ', $inst);
            $excelNote = '[Excel] ' . implode(' | ', $parts);

            // ---- save ----
            $ex = $conn->query("SELECT payment_status, notes FROM payments WHERE student_id=$sid LIMIT 1")->fetch_assoc();
            if ($ex && $ex['payment_status'] === 'CONVA_PAY') $status = 'CONVA_PAY';   // keep manual Conva Pay
            $manual = manualNotesPart($ex['notes'] ?? '');
            $finalNotes = $manual !== '' ? $excelNote . ' ## ' . $manual : $excelNote;
            $total = (float)$total;

            $regF = (float)$regFee; $cPaid = (float)$coursePaid; $cDue = (float)$due; $bal = (float)$balance;
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
                if (count($notes) < 45) $notes[] = "$reg: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    $sum = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS t FROM payments WHERE amount IS NOT NULL AND amount > 0")->fetch_assoc();
    $stats['saved_rows'] = (int)$sum['n'];
    logActivity($admin, 'PAYMENT_UPLOAD', json_encode($stats));
    setFlash('success',
        "Payments imported. Students updated: {$stats['students']} | Completed: {$stats['completed']} | One time: {$stats['one_time']} | "
        . "Partial: {$stats['partial']} | Only reg fee: {$stats['none']} | No payments in Excel: {$stats['nodata']} | Student not found: {$stats['notfound']} | Payments with amount in system: {$sum['n']} (Rs " . number_format((float)$sum['t'], 0) . ")");
    if ($notes) $_SESSION['upload_errors'] = array_slice($notes, 0, 45);
    redirect('payments.php');
}

// ============================================================
// SINGLE UPDATE
// ============================================================
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
            refreshCertificate($conn, $student_id, $status);
            logActivity($admin, 'UPDATE_PAYMENT', "Student:$student_id Status:$status");
            setFlash('success', 'Payment updated.');
        }
        $stmt->close();
        redirect('payments.php');
    }
}

// ============================================================
// LIST
// ============================================================
$filterStatus = $_GET['status'] ?? '';
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search = trim($_GET['q'] ?? '');

$batchesArr = [];
$q = $conn->query("SELECT b.id, b.batch_code, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
while ($b = $q->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.id AS student_id, s.registration_number, s.full_name, s.phone,
           b.batch_code, c.course_code,
           p.payment_status, p.amount, p.payment_date, p.notes" . ($hasExtra ? ", p.reg_fee, p.course_paid, p.course_due, p.balance" : ", NULL AS reg_fee, NULL AS course_paid, NULL AS course_due, NULL AS balance") . "
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

<!-- Upload modal -->
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
                    <label class="form-label">HDICRA.xlsx (all Batch sheets)</label>
                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    <div class="alert alert-info small mt-3 mb-0">
                        <strong>How it works</strong>
                        <ul class="mb-2 ps-3">
                            <li>Students are matched by <strong>Reg No</strong> (upload students first).</li>
                            <li>Reads <strong>Registration Fee</strong> + all <strong>installment fee columns</strong> (1st … 6th) of every sheet.</li>
                            <li><strong>Amount</strong> = total received (reg fee + installments). <strong>Date</strong> = last payment date.
                                <strong>Notes</strong> = breakdown + balance (kept separate from your own notes).</li>
                            <li>Course fee comes from the <em>Course Fee</em> / <em>Offer</em> columns (e.g. "25000 (one time)").</li>
                        </ul>
                        <strong>Status rules</strong>
                        <table class="table table-sm table-bordered mb-0 bg-white">
                            <tr><td>Paid full course fee</td><td><span class="badge bg-success">COMPLETED</span></td></tr>
                            <tr><td>Paid in the one-time offer</td><td><span class="badge bg-success">ONE TIME</span></td></tr>
                            <tr><td>Some installments paid, balance left</td><td><span class="badge bg-warning text-dark">PENDING</span></td></tr>
                            <tr><td>Only registration fee paid</td><td><span class="badge bg-danger">NOT PAID</span></td></tr>
                            <tr><td>Nothing in Excel</td><td>unchanged</td></tr>
                        </table>
                        <div class="mt-2">Students marked <strong>CONVA PAY</strong> manually keep that status.</div>
                    </div>
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
                                        <textarea name="notes" class="form-control" rows="3"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
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
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="text-center text-muted py-5">No records</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>