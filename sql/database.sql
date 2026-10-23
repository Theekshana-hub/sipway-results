-- =====================================================
-- Sipway Campus - Student Exam Results Management System
-- Database Schema
-- =====================================================

CREATE DATABASE IF NOT EXISTS sipway_results
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE sipway_results;

-- -----------------------------------------------------
-- Table: courses
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    course_name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: batches
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    batch_code VARCHAR(30) NOT NULL,
    batch_name VARCHAR(100) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_batch (course_id, batch_code)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: students
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    phone2 VARCHAR(30) NULL,
    address TEXT NULL,
    batch_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    INDEX idx_reg (registration_number),
    INDEX idx_name (full_name),
    INDEX idx_phone (phone)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: results
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    assignment1 DECIMAL(5,2) NULL DEFAULT NULL COMMENT '1st Assignment marks',
    assignment2 DECIMAL(5,2) NULL DEFAULT NULL COMMENT '2nd Assignment marks',
    final_exam DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Final exam marks',
    full_marks DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Total / Full marks',
    marks_percentage DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Percentage if used',
    result_status ENUM('PRESENT','ABSENT','PENDING','NOT_ELIGIBLE') DEFAULT 'PENDING',
    remarks TEXT NULL,
    exam_date DATE NULL,
    entered_by VARCHAR(50) DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_result (student_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: payments
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    payment_status ENUM('PAID','ONE_TIME','COMPLETED','PENDING','NOT_PAID','CONVA_PAY') DEFAULT 'PENDING',
    amount DECIMAL(10,2) NULL,
    payment_date DATE NULL,
    notes TEXT NULL,
    updated_by VARCHAR(50) DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_payment (student_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: certificates
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    certificate_status ENUM('ISSUED','PENDING','NOT_ELIGIBLE','READY') DEFAULT 'PENDING',
    issue_date DATE NULL,
    certificate_number VARCHAR(50) NULL,
    notes TEXT NULL,
    updated_by VARCHAR(50) DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_cert (student_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: activity_log (simple audit)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================
-- SAMPLE DATA
-- =====================================================

INSERT INTO courses (course_code, course_name, description) VALUES
('RL', 'Certificate in Russian Language', 'Russian Language Certificate Course'),
('DBM', 'Diploma in Business Management', 'Business Management Diploma'),
('DHRM', 'Diploma in Human Resource Management', 'HRM Diploma Programme'),
('GRA', 'Diploma in Graphic Design', 'Graphic Design Diploma'),
('IAE', 'Certificate in Import & Export Procedures', 'Import Export Certificate'),
('TX', 'Management Course (Manjula Sir)', 'Management Course by Manjula Sir');

INSERT INTO batches (course_id, batch_code, batch_name) VALUES
(1, 'B10', 'Russian Language Batch 10'),
(2, 'B15', 'DBM Batch 15'),
(2, 'B16', 'DBM Batch 16'),
(3, 'B25', 'DHRM Batch 25'),
(3, 'B26', 'DHRM Batch 26'),
(3, 'B30', 'DHRM Batch 30'),
(3, 'B31', 'DHRM Batch 31'),
(3, 'B32', 'DHRM Batch 32'),
(4, 'B33', 'Graphic Design Batch 33'),
(4, 'B35', 'Graphic Design Batch 35'),
(4, 'B36', 'Graphic Design Batch 36'),
(4, 'B41', 'Graphic Design Batch 41'),
(4, 'B42', 'Graphic Design Batch 42'),
(5, 'B21', 'Import & Export Batch 21'),
(5, 'B23', 'Import & Export Batch 23'),
(5, 'B24', 'Import & Export Batch 24'),
(5, 'B25', 'Import & Export Batch 25'),
(6, 'B09', 'Management Batch 09');

-- Sample students (Russian Language Batch 10)
INSERT INTO students (registration_number, full_name, phone, batch_id) VALUES
('RL/B10/001', 'Gayani Dilrukshi', NULL, 1),
('RL/B10/002', 'Indika Prasanna Kumara', NULL, 1),
('RL/B10/003', 'T.Malmi Pabasara Ranasinghe', NULL, 1),
('RL/B10/004', 'Amandi Nadeesha Matharaarachchi', NULL, 1),
('RL/B10/005', 'Malith Krishantha Waththuhewa', NULL, 1),
('RL/B10/006', 'K.P.I.S.De Silva', NULL, 1),
('RL/B10/007', 'G.L.Dhanushka Pushpa Kumara De Silva', NULL, 1),
('RL/B10/008', 'H.M.Kaushadi Avishka Herath', NULL, 1);

-- Sample results for Russian batch
INSERT INTO results (student_id, marks_percentage, result_status) VALUES
(1, NULL, 'ABSENT'),
(2, NULL, 'ABSENT'),
(3, 80.00, 'PRESENT'),
(4, NULL, 'ABSENT'),
(5, NULL, 'ABSENT'),
(6, 95.00, 'PRESENT'),
(7, NULL, 'ABSENT'),
(8, NULL, 'ABSENT');

-- Sample payments
INSERT INTO payments (student_id, payment_status) VALUES
(1, 'PENDING'),
(2, 'PENDING'),
(3, 'COMPLETED'),
(4, 'PENDING'),
(5, 'PENDING'),
(6, 'COMPLETED'),
(7, 'PENDING'),
(8, 'PENDING');

-- Sample certificates
INSERT INTO certificates (student_id, certificate_status) VALUES
(1, 'NOT_ELIGIBLE'),
(2, 'NOT_ELIGIBLE'),
(3, 'READY'),
(4, 'NOT_ELIGIBLE'),
(5, 'NOT_ELIGIBLE'),
(6, 'READY'),
(7, 'NOT_ELIGIBLE'),
(8, 'NOT_ELIGIBLE');

-- More sample students - DBM
INSERT INTO students (registration_number, full_name, phone, batch_id) VALUES
('DBM/B15/001', 'D M Rajitha Dananjani Kalhari Dissanayake', '773780829', 2),
('DBM/B15/002', 'Kuda Thanthrige Supun Harshana Senarathne', '710835086', 2),
('DBM/B16/001', 'Dewathanthri Arachchige Prabodha Madhushanka', '719138154', 3),
('DBM/B16/002', 'W M M S P Weerakkody', '772674333', 3),
('DBM/B16/003', 'Lehan Minanga Wickramaarachchi', '702995876', 3),
('DBM/B16/006', 'M.D Saman Piyankara Rathnasiri', '759400735', 3);

INSERT INTO results (student_id, marks_percentage, result_status) VALUES
(9, NULL, 'NOT_ELIGIBLE'),
(10, NULL, 'PENDING'),
(11, 80.00, 'PRESENT'),
(12, NULL, 'PENDING'),
(13, NULL, 'PENDING'),
(14, 75.00, 'PRESENT');

INSERT INTO payments (student_id, payment_status) VALUES
(9, 'ONE_TIME'),
(10, 'PENDING'),
(11, 'PAID'),
(12, 'PENDING'),
(13, 'PENDING'),
(14, 'ONE_TIME');

INSERT INTO certificates (student_id, certificate_status) VALUES
(9, 'NOT_ELIGIBLE'),
(10, 'PENDING'),
(11, 'READY'),
(12, 'PENDING'),
(13, 'PENDING'),
(14, 'READY');

-- Management course sample
INSERT INTO students (registration_number, full_name, phone, address, batch_id) VALUES
('TX/B09/002', 'T.H.D.C Chathuranga Chandrasena', '777298742', 'NO 77/A, PANDIWATTA, NATTARAMPOTHA', 18),
('TX/B09/004', 'U L C Janaka', '722697186', 'anasuma, Madahinna Kinigama, Bandarawela', 18),
('TX/B09/005', 'W M SANJAYA DILSHAN WIJESUNDARA', '773376348', 'SAMADHI, kebillewela south, Bandarawela', 18),
('TX/B09/006', 'J.D.R.C Jayasinghe', '757134196', '1/3 B, Delgahapitiya, Mahingala, Bope, Padukka', 18),
('TX/B09/009', 'P G Tharindu Malshan', '773572431', 'No. 71, Thammita, Makewita.', 18);

INSERT INTO results (student_id, assignment1, assignment2, final_exam, full_marks, result_status) VALUES
(15, 21, 24, 46, 91, 'PRESENT'),
(16, 20, 24, 44, 88, 'PRESENT'),
(17, 18, 24, 46, 88, 'PRESENT'),
(18, 23, 23, 48, 94, 'PRESENT'),
(19, 24, 25, 48, 97, 'PRESENT');

INSERT INTO payments (student_id, payment_status) VALUES
(15, 'PAID'),
(16, 'PAID'),
(17, 'PAID'),
(18, 'PAID'),
(19, 'PAID');

INSERT INTO certificates (student_id, certificate_status) VALUES
(15, 'READY'),
(16, 'READY'),
(17, 'READY'),
(18, 'READY'),
(19, 'READY');

SELECT 'Database created successfully with sample data!' AS message;
