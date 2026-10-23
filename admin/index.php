<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Dashboard';

$conn = getConnection();

// Stats
$totalStudents = $conn->query("SELECT COUNT(*) AS c FROM students WHERE is_active=1")->fetch_assoc()['c'];
$totalCourses  = $conn->query("SELECT COUNT(*) AS c FROM courses WHERE is_active=1")->fetch_assoc()['c'];
$totalBatches  = $conn->query("SELECT COUNT(*) AS c FROM batches WHERE is_active=1")->fetch_assoc()['c'];

$pendingResults = $conn->query("SELECT COUNT(*) AS c FROM results WHERE result_status='PENDING'")->fetch_assoc()['c'];
$absentCount    = $conn->query("SELECT COUNT(*) AS c FROM results WHERE result_status='ABSENT'")->fetch_assoc()['c'];
$presentCount   = $conn->query("SELECT COUNT(*) AS c FROM results WHERE result_status='PRESENT'")->fetch_assoc()['c'];

$pendingPay  = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE payment_status IN ('PENDING','NOT_PAID')")->fetch_assoc()['c'];
$paidCount   = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE payment_status IN ('PAID','COMPLETED','ONE_TIME')")->fetch_assoc()['c'];

$certReady   = $conn->query("SELECT COUNT(*) AS c FROM certificates WHERE certificate_status IN ('READY','ISSUED')")->fetch_assoc()['c'];
$certPending = $conn->query("SELECT COUNT(*) AS c FROM certificates WHERE certificate_status='PENDING'")->fetch_assoc()['c'];

// Recent students
$recent = $conn->query("
    SELECT s.registration_number, s.full_name, c.course_name, b.batch_code,
           r.result_status, r.marks_percentage, r.full_marks, p.payment_status
    FROM students s
    JOIN batches b ON s.batch_id = b.id
    JOIN courses c ON b.course_id = c.id
    LEFT JOIN results r ON s.id = r.student_id
    LEFT JOIN payments p ON s.id = p.student_id
    WHERE s.is_active = 1
    ORDER BY s.id DESC
    LIMIT 10
");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <p class="text-muted mb-0">Welcome back, <strong><?php echo getAdminUsername(); ?></strong> — here is your overview.</p>
</div>

<!-- Stats Row 1 -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-red-soft"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="text-muted small">Total Students</div>
                    <div class="fs-4 fw-bold text-dark"><?php echo $totalStudents; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info-soft"><i class="bi bi-book-fill"></i></div>
                <div>
                    <div class="text-muted small">Courses</div>
                    <div class="fs-4 fw-bold" style="color:#e31c23"><?php echo $totalCourses; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-soft"><i class="bi bi-collection-fill"></i></div>
                <div>
                    <div class="text-muted small">Batches</div>
                    <div class="fs-4 fw-bold text-success"><?php echo $totalBatches; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning-soft"><i class="bi bi-clipboard-data-fill"></i></div>
                <div>
                    <div class="text-muted small">Results Pending</div>
                    <div class="fs-4 fw-bold text-warning"><?php echo $pendingResults; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row 2 -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-soft"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="text-muted small">Present</div>
                    <div class="fs-4 fw-bold text-success"><?php echo $presentCount; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger-soft"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                    <div class="text-muted small">Absent</div>
                    <div class="fs-4 fw-bold text-danger"><?php echo $absentCount; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger-soft"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="text-muted small">Payment Pending</div>
                    <div class="fs-4 fw-bold text-danger"><?php echo $pendingPay; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info-soft"><i class="bi bi-award-fill"></i></div>
                <div>
                    <div class="text-muted small">Certificates Ready</div>
                    <div class="fs-4 fw-bold" style="color:#e31c23"><?php echo $certReady; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Students -->
<div class="card table-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-semibold"><i class="bi bi-people me-2 text-dark"></i>Recent Students</h5>
        <a href="students.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reg. Number</th>
                    <th>Name</th>
                    <th>Course / Batch</th>
                    <th>Marks</th>
                    <th>Result</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recent && $recent->num_rows > 0): ?>
                    <?php while ($row = $recent->fetch_assoc()): ?>
                    <tr>
                        <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                        <td><?php echo sanitize($row['full_name']); ?></td>
                        <td>
                            <small class="text-muted"><?php echo sanitize($row['course_name']); ?></small><br>
                            <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span>
                        </td>
                        <td>
                            <?php
                            if ($row['full_marks'] !== null) echo $row['full_marks'];
                            elseif ($row['marks_percentage'] !== null) echo $row['marks_percentage'] . '%';
                            else echo '—';
                            ?>
                        </td>
                        <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : '—'; ?></td>
                        <td><?php echo $row['payment_status'] ? statusBadge($row['payment_status']) : '—'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No students found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
