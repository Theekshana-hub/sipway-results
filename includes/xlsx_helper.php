<?php
/**
 * Shared Excel helpers for HDICRA.xlsx import
 * Used by students.php, results.php, payments.php
 */

if (!function_exists('readXlsxAllSheets')) {

function readXlsxAllSheets($path) {
    $result = [];
    if (!class_exists('ZipArchive')) return $result;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $result;

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $sx = @simplexml_load_string($ssXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $t = '';
                    if (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
                    $shared[] = $t;
                }
            }
        }
    }

    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $sheetFiles = [];
    if ($wbXml && $relsXml) {
        $rels = [];
        $rx = @simplexml_load_string($relsXml);
        if ($rx) {
            foreach ($rx->Relationship as $rel) {
                $id = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                if (strpos($target, 'worksheets/') !== false) {
                    $rels[$id] = 'xl/' . ltrim($target, '/');
                }
            }
        }
        $wx = @simplexml_load_string($wbXml);
        if ($wx) {
            $i = 0;
            foreach ($wx->sheets->sheet as $sheet) {
                $name = (string)$sheet['name'];
                $rid = (string)$sheet->attributes('r', true)['id'];
                if (!$rid) {
                    $i++;
                    $pathSheet = "xl/worksheets/sheet{$i}.xml";
                } else {
                    $pathSheet = $rels[$rid] ?? null;
                }
                if ($pathSheet) $sheetFiles[$name] = $pathSheet;
            }
        }
    }
    if (empty($sheetFiles)) {
        for ($i = 1; $i <= 20; $i++) {
            $p = "xl/worksheets/sheet{$i}.xml";
            if ($zip->getFromName($p) !== false) $sheetFiles["Sheet$i"] = $p;
        }
    }

    foreach ($sheetFiles as $sheetName => $sheetPath) {
        $xml = $zip->getFromName($sheetPath);
        if (!$xml) continue;
        $sx = @simplexml_load_string($xml);
        if (!$sx) continue;

        $grid = [];
        $maxCol = 0;
        foreach ($sx->sheetData->row as $row) {
            $rIdx = (int)$row['r'] - 1;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
                if ($col > $maxCol) $maxCol = $col;
                $val = '';
                $type = isset($c['t']) ? (string)$c['t'] : '';
                if ($type === 's') {
                    $si = (int)$c->v;
                    $val = $shared[$si] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)($c->is->t ?? '');
                } else {
                    $val = (string)($c->v ?? '');
                }
                $grid[$rIdx][$col] = $val;
            }
        }
        $rows = [];
        if (!empty($grid)) {
            ksort($grid);
            $maxR = max(array_keys($grid));
            for ($r = 0; $r <= $maxR; $r++) {
                $line = [];
                for ($c = 0; $c <= $maxCol; $c++) {
                    $line[] = isset($grid[$r][$c]) ? $grid[$r][$c] : '';
                }
                $rows[] = $line;
            }
        }
        $result[$sheetName] = $rows;
    }
    $zip->close();
    return $result;
}

function normalizeHeader($h) {
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$h)));
}

function cellVal($row, $idx) {
    if ($idx === null || $idx < 0 || !isset($row[$idx])) return '';
    $v = $row[$idx];
    if ($v === null) return '';
    return trim((string)$v);
}

function isRegNo($v) {
    return (bool)preg_match('/^[A-Za-z]{2,10}\/[A-Za-z0-9]{1,10}\/\d{1,5}$/', trim((string)$v));
}

function parseNum($v) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return null;
    return (float)$v;
}

/**
 * Detect student columns for HDICRA or simple CSV
 * Returns map with header_row, reg, name, phone, phone2, address, course, batch
 */
function detectStudentColumns($rows) {
    $map = [
        'reg' => null, 'name' => null, 'phone' => null, 'phone2' => null,
        'address' => null, 'course' => null, 'batch' => null, 'header_row' => null
    ];

    for ($r = 0; $r < min(30, count($rows)); $r++) {
        $row = $rows[$r];
        $found = false;
        for ($c = 0; $c < count($row); $c++) {
            $h = normalizeHeader($row[$c]);
            if ($h === '') continue;

            if ($h === 'reg no' || $h === 'registration number' || strpos($h, 'reg no') === 0) {
                $map['reg'] = $c; $found = true;
            }
            if ($h === 'student id number' && $map['reg'] === null) {
                $map['reg'] = $c; $found = true;
            }
            if ($h === 'registration_number') { $map['reg'] = $c; $found = true; }
            if ($h === 'name' || $h === 'full name' || $h === 'full_name') $map['name'] = $c;
            if (strpos($h, 'whatsapp') !== false || $h === 'phone') $map['phone'] = $c;
            if ($h === 'phone2' || $h === 'phone 2') $map['phone2'] = $c;
            if ($h === 'address') $map['address'] = $c;
            if ($h === 'course_code' || $h === 'course code') $map['course'] = $c;
            if ($h === 'batch_code' || $h === 'batch code') $map['batch'] = $c;
            if ($h === 'emali' || $h === 'email') { /* skip */ }
        }
        // HDICRA: need Reg No + Name
        if ($found && $map['name'] !== null) {
            $map['header_row'] = $r;
            return $map;
        }
        // Simple CSV: course + batch + name
        if ($map['course'] !== null && $map['batch'] !== null && $map['name'] !== null) {
            $map['header_row'] = $r;
            return $map;
        }
    }

    // Fallback: scan for ICRA/Bxx/ style reg in data rows
    for ($r = 0; $r < min(25, count($rows)); $r++) {
        for ($c = 0; $c < min(5, count($rows[$r])); $c++) {
            if (isRegNo($rows[$r][$c])) {
                $map['reg'] = $c;
                $map['name'] = ($c === 0) ? 2 : (($c === 1) ? 2 : 2);
                $map['phone'] = 4;
                $map['header_row'] = max(0, $r - 1);
                return $map;
            }
        }
    }
    return $map;
}

function detectHdicraColumns($rows) {
    $map = [
        'reg' => null, 'name' => null, 'phone' => null,
        'a1' => null, 'a2' => null, 'a3' => null, 'exam' => null,
        'header_row' => null
    ];
    for ($r = 0; $r < min(25, count($rows)); $r++) {
        $row = $rows[$r];
        $foundReg = false;
        for ($c = 0; $c < count($row); $c++) {
            $h = normalizeHeader($row[$c]);
            if ($h === '') continue;
            if ($h === 'reg no' || $h === 'registration number' || strpos($h, 'reg no') === 0) {
                $map['reg'] = $c; $foundReg = true;
            }
            if ($h === 'student id number' && $map['reg'] === null) {
                $map['reg'] = $c; $foundReg = true;
            }
            if ($h === 'name' || $h === 'full name') $map['name'] = $c;
            if (strpos($h, 'whatsapp') !== false || $h === 'phone') $map['phone'] = $c;
            if (strpos($h, '1st assignment') !== false || strpos($h, '01st assig') !== false) $map['a1'] = $c;
            if (strpos($h, '2nd assignment') !== false || strpos($h, '02nd assig') !== false) $map['a2'] = $c;
            if (strpos($h, '3rd assignment') !== false) $map['a3'] = $c;
            if (strpos($h, 'final exam') !== false) $map['exam'] = $c;
        }
        if ($foundReg && ($map['name'] !== null || $map['a1'] !== null)) {
            $map['header_row'] = $r;
            break;
        }
    }
    return $map;
}

function ensureCourseAndBatch($conn, $regNo) {
    if (!preg_match('/^([A-Za-z]+)\/([A-Za-z0-9]+)\/(\d+)/', $regNo, $m)) {
        return null;
    }
    $courseCode = strtoupper($m[1]);
    $batchCode = strtoupper($m[2]);

    $course = $conn->query("SELECT id FROM courses WHERE course_code='".$conn->real_escape_string($courseCode)."' LIMIT 1")->fetch_assoc();
    if (!$course) {
        $conn->query("INSERT INTO courses (course_code, course_name, is_active) VALUES ('".$conn->real_escape_string($courseCode)."', '".$conn->real_escape_string($courseCode)." Course', 1)");
        $courseId = (int)$conn->insert_id;
    } else {
        $courseId = (int)$course['id'];
    }

    $batch = $conn->query("SELECT id FROM batches WHERE course_id=$courseId AND batch_code='".$conn->real_escape_string($batchCode)."' LIMIT 1")->fetch_assoc();
    if (!$batch) {
        $conn->query("INSERT INTO batches (course_id, batch_code, batch_name, is_active) VALUES ($courseId, '".$conn->real_escape_string($batchCode)."', '".$conn->real_escape_string($courseCode.' '.$batchCode)."', 1)");
        $batchId = (int)$conn->insert_id;
    } else {
        $batchId = (int)$batch['id'];
    }
    return $batchId;
}

/**
 * Import one student. Returns: 'new' | 'exists' | error string
 */
function importOneStudent($conn, $reg, $name, $phone = '', $course = '', $batch = '', $phone2 = '', $addr = '') {
    $reg = trim($reg);
    $name = trim($name);
    if ($name === '') return 'Missing name';

    // Resolve batch from reg number or course/batch fields
    $batchId = null;
    if (isRegNo($reg)) {
        $batchId = ensureCourseAndBatch($conn, $reg);
    } elseif ($course !== '' && $batch !== '') {
        $courseCode = strtoupper(trim($course));
        $batchCode = strtoupper(trim($batch));
        $c = $conn->query("SELECT id FROM courses WHERE course_code='".$conn->real_escape_string($courseCode)."' LIMIT 1")->fetch_assoc();
        if (!$c) {
            $conn->query("INSERT INTO courses (course_code, course_name, is_active) VALUES ('".$conn->real_escape_string($courseCode)."', '".$conn->real_escape_string($courseCode)." Course', 1)");
            $courseId = (int)$conn->insert_id;
        } else {
            $courseId = (int)$c['id'];
        }
        $b = $conn->query("SELECT id FROM batches WHERE course_id=$courseId AND batch_code='".$conn->real_escape_string($batchCode)."' LIMIT 1")->fetch_assoc();
        if (!$b) {
            $conn->query("INSERT INTO batches (course_id, batch_code, batch_name, is_active) VALUES ($courseId, '".$conn->real_escape_string($batchCode)."', '".$conn->real_escape_string($courseCode.' '.$batchCode)."', 1)");
            $batchId = (int)$conn->insert_id;
        } else {
            $batchId = (int)$b['id'];
        }
        // auto reg if empty
        if ($reg === '') {
            $prefix = $courseCode . '/' . $batchCode . '/';
            $like = $conn->real_escape_string($prefix . '%');
            $res = $conn->query("SELECT registration_number FROM students WHERE registration_number LIKE '$like' ORDER BY id DESC LIMIT 50");
            $maxSeq = 0;
            if ($res) while ($r = $res->fetch_assoc()) {
                if (preg_match('/\/(\d+)$/', $r['registration_number'], $m)) {
                    $maxSeq = max($maxSeq, (int)$m[1]);
                }
            }
            $reg = $prefix . str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
        }
    }

    if (!$batchId) return 'Cannot resolve course/batch for ' . $reg;
    if ($reg === '') return 'Missing registration number';

    // Check duplicate
    $chk = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
    $chk->bind_param("s", $reg);
    $chk->execute();
    $ex = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($ex) return 'exists';

    // Clean phone
    if (is_numeric($phone) && strpos((string)$phone, '.') !== false) $phone = (string)(int)(float)$phone;
    if (is_numeric($phone2) && strpos((string)$phone2, '.') !== false) $phone2 = (string)(int)(float)$phone2;

    $stmt = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, phone2, address, batch_id, is_active) VALUES (?,?,?,?,?,?,1)");
    $stmt->bind_param("sssssi", $reg, $name, $phone, $phone2, $addr, $batchId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return $err;
    }
    $sid = (int)$stmt->insert_id;
    $stmt->close();

    $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
    $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
    $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");

    return 'new';
}

} // end function_exists guard