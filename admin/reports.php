<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Reports';
$conn = getConnection();

$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$reportType   = $_GET['type'] ?? 'all';

$coursesList = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
$coursesArr = [];
while ($c = $coursesList->fetch_assoc()) $coursesArr[] = $c;

$batchesList = $conn->query("SELECT b.id, b.batch_code, b.batch_name, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
$batchesArr = [];
while ($b = $batchesList->fetch_assoc()) $batchesArr[] = $b;

$sql = "
    SELECT s.registration_number, s.full_name, s.phone, s.address,
           b.batch_code, b.batch_name, c.course_code, c.course_name,
           r.assignment1, r.assignment2, r.final_exam, r.full_marks, r.marks_percentage, r.result_status,
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

if ($reportType === 'present') $sql .= " AND r.result_status = 'PRESENT'";
elseif ($reportType === 'absent') $sql .= " AND r.result_status = 'ABSENT'";
elseif ($reportType === 'pending_result') $sql .= " AND (r.result_status = 'PENDING' OR r.result_status IS NULL)";
elseif ($reportType === 'payment_pending') $sql .= " AND p.payment_status IN ('PENDING','NOT_PAID')";
elseif ($reportType === 'cert_ready') $sql .= " AND cert.certificate_status IN ('READY','ISSUED')";
elseif ($reportType === 'cert_pending') $sql .= " AND cert.certificate_status = 'PENDING'";

$sql .= " ORDER BY c.course_code, b.batch_code, s.registration_number";
$rows = $conn->query($sql);

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sipway_results_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reg Number','Name','Phone','Course','Batch','Asg1','Asg2','Final','Full Marks','%','Result','Payment','Certificate']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [
            $r['registration_number'], $r['full_name'], $r['phone'],
            $r['course_code'], $r['batch_code'],
            $r['assignment1'], $r['assignment2'], $r['final_exam'], $r['full_marks'], $r['marks_percentage'],
            $r['result_status'], $r['payment_status'], $r['certificate_status']
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="btn btn-success">
        <i class="bi bi-download me-1"></i> Export CSV
    </a>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Report Type</label>
            <select name="type" class="form-select" onchange="this.form.submit()">
                <option value="all" <?php echo $reportType=='all'?'selected':''; ?>>All Students</option>
                <option value="present" <?php echo $reportType=='present'?'selected':''; ?>>Present (Passed)</option>
                <option value="absent" <?php echo $reportType=='absent'?'selected':''; ?>>Absent (AB)</option>
                <option value="pending_result" <?php echo $reportType=='pending_result'?'selected':''; ?>>Results Pending</option>
                <option value="payment_pending" <?php echo $reportType=='payment_pending'?'selected':''; ?>>Payment Pending</option>
                <option value="cert_ready" <?php echo $reportType=='cert_ready'?'selected':''; ?>>Certificates Ready</option>
                <option value="cert_pending" <?php echo $reportType=='cert_pending'?'selected':''; ?>>Certificates Pending</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Course</label>
            <select name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All Courses</option>
                <?php foreach ($coursesArr as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse==$c['id']?'selected':''; ?>><?php echo sanitize($c['course_code'].' - '.$c['course_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Batch</label>
            <select name="batch_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All Batches</option>
                <?php foreach ($batchesArr as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $filterBatch==$b['id']?'selected':''; ?>><?php echo sanitize($b['course_code'].'/'.$b['batch_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="card table-card">
    <div class="card-header bg-white py-3 d-flex justify-content-between">
        <strong>Total Records: <?php echo $rows->num_rows; ?></strong>
        <span class="text-muted small">Print this page or Export CSV</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" id="reportTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reg. Number</th>
                    <th>Name</th>
                    <th>Course</th>
                    <th>Batch</th>
                    <th>Marks</th>
                    <th>Result</th>
                    <th>Payment</th>
                    <th>Certificate</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; if ($rows->num_rows > 0): while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><?php echo sanitize($row['course_code']); ?></td>
                    <td><?php echo sanitize($row['batch_code']); ?></td>
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
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No records match the filter</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i> Print Report</button>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
