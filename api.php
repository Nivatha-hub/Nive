<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$config = [
  'host' => getenv('DB_HOST') ?: '127.0.0.1',
  'port' => getenv('DB_PORT') ?: '3306',
  'name' => getenv('DB_NAME') ?: 'nexahire',
  'user' => getenv('DB_USER') ?: 'root',
  'pass' => getenv('DB_PASSWORD') ?: '',
];

function body() { return json_decode(file_get_contents('php://input'), true) ?: $_POST; }
function respond($data, $status = 200) { http_response_code($status); echo json_encode($data); exit; }
function db() {
  global $config;
  static $pdo;
  if ($pdo) return $pdo;
  try {
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
  } catch (Throwable $e) { respond(['error' => 'MySQL connection failed. Import schema.sql and check your credentials.'], 503); }
}
function user() { return $_SESSION['user'] ?? null; }
function require_user() { $u = user(); if (!$u) respond(['error' => 'Please sign in first.'], 401); return $u; }
function clean($value) { return trim((string)($value ?? '')); }

$action = $_GET['action'] ?? '';
$data = body();
try {
  if ($action === 'request_otp') {
    $destination = clean($data['destination']); $channel = filter_var($destination, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
    $code = (string)random_int(100000, 999999); $hash = password_hash($code, PASSWORD_DEFAULT);
    $pdo = db(); $lookup = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1'); $lookup->execute([$destination, $destination]); $found = $lookup->fetch();
    $stmt = $pdo->prepare('INSERT INTO otp_codes (user_id, destination, channel, code_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'); $stmt->execute([$found['id'] ?? null, $destination, $channel, $hash]);
    respond(['message' => "OTP sent to your {$channel}.", 'dev_otp' => $code]);
  }
  if ($action === 'verify_otp') {
    $destination = clean($data['destination']); $code = clean($data['code']);
    $stmt = db()->prepare('SELECT * FROM otp_codes WHERE destination = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1'); $stmt->execute([$destination]); $otp = $stmt->fetch();
    if (!$otp || !password_verify($code, $otp['code_hash'])) respond(['error' => 'That OTP is invalid or expired.'], 422);
    db()->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([$otp['id']]);
    $lookup = db()->prepare('SELECT id, role, full_name, email, phone, headline, location, bio, skills FROM users WHERE email = ? OR phone = ? LIMIT 1'); $lookup->execute([$destination, $destination]); $account = $lookup->fetch();
    if ($account) { $account['skills'] = json_decode($account['skills'] ?: '[]', true); $_SESSION['user'] = $account; }
    respond(['verified' => true, 'user' => $account]);
  }
  if ($action === 'register') {
    $role = in_array($data['role'] ?? '', ['user', 'employer'], true) ? $data['role'] : 'user'; $name = clean($data['full_name']); $email = clean($data['email']);
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen(clean($data['password'])) < 1) respond(['error' => 'Name, valid email, and password are required.'], 422);
    $stmt = db()->prepare('INSERT INTO users (role, full_name, email, phone, password_hash, headline, location) VALUES (?, ?, ?, ?, ?, ?, ?)'); $stmt->execute([$role, $name, $email, clean($data['phone']), password_hash($data['password'], PASSWORD_DEFAULT), $role === 'employer' ? 'Hiring team' : 'Open to new opportunities', clean($data['location'])]);
    $id = db()->lastInsertId(); $lookup = db()->prepare('SELECT id, role, full_name, email, phone, headline, location, bio, skills FROM users WHERE id = ?'); $lookup->execute([$id]); $account = $lookup->fetch(); $account['skills'] = []; $_SESSION['user'] = $account; respond(['user' => $account], 201);
  }
  if ($action === 'login') {
    $destination = clean($data['destination']); $stmt = db()->prepare('SELECT id, role, full_name, email, phone, headline, location, bio, skills, password_hash FROM users WHERE email = ? OR phone = ? LIMIT 1'); $stmt->execute([$destination, $destination]); $account = $stmt->fetch();
    if (!$account) respond(['error' => 'We could not match those details.'], 422);
    unset($account['password_hash']); $account['skills'] = json_decode($account['skills'] ?: '[]', true); $_SESSION['user'] = $account; respond(['user' => $account]);
  }
  if ($action === 'logout') { session_destroy(); respond(['logged_out' => true]); }
  if ($action === 'jobs') { $stmt = db()->query("SELECT j.*, u.full_name AS employer_name, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count FROM jobs j JOIN users u ON u.id = j.employer_id WHERE j.status = 'Active' ORDER BY j.created_at DESC"); respond(['jobs' => $stmt->fetchAll()]); }
  $current = require_user();
  if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'GET') { $stmt = db()->prepare('SELECT id, role, full_name, email, phone, headline, location, bio, skills FROM users WHERE id = ?'); $stmt->execute([$current['id']]); $profile = $stmt->fetch(); $profile['skills'] = json_decode($profile['skills'] ?: '[]', true); respond(['user' => $profile]); }
  if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') { $skills = array_values(array_filter(array_map('trim', explode(',', clean($data['skills']))))); db()->prepare('UPDATE users SET full_name = ?, phone = ?, headline = ?, location = ?, bio = ?, skills = ? WHERE id = ?')->execute([clean($data['full_name']), clean($data['phone']), clean($data['headline']), clean($data['location']), clean($data['bio']), json_encode($skills), $current['id']]); respond(['message' => 'Profile updated.']); }
  if ($action === 'my_jobs') { $stmt = db()->prepare('SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count FROM jobs j WHERE employer_id = ? ORDER BY j.created_at DESC'); $stmt->execute([$current['id']]); respond(['jobs' => $stmt->fetchAll()]); }
  if ($action === 'job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($current['role'] !== 'employer') respond(['error' => 'Employer access required.'], 403);
    $title = clean($data['title']); $company = clean($data['company_name']); $location = clean($data['location']); $description = clean($data['description']);
    $workMode = clean($data['work_mode']); $employmentType = clean($data['employment_type']);
    if (!$title || !$company || !$location || !$description) respond(['error' => 'Title, company, location, and description are required.'], 422);
    if (!in_array($workMode, ['Remote', 'Hybrid', 'On-site'], true) || !in_array($employmentType, ['Full-time', 'Part-time', 'Contract', 'Internship'], true)) respond(['error' => 'Invalid work mode or employment type.'], 422);
    $skills = array_values(array_filter(array_map('trim', explode(',', clean($data['skills'])))));
    db()->prepare('INSERT INTO jobs (employer_id, title, company_name, location, work_mode, employment_type, salary_range, description, skills) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$current['id'], $title, $company, $location, $workMode, $employmentType, clean($data['salary_range']), $description, json_encode($skills)]);
    respond(['message' => 'Job posted successfully.'], 201);
  }
  if ($action === 'apply') { db()->prepare('INSERT INTO applications (job_id, user_id, cover_note) VALUES (?, ?, ?)')->execute([(int)$data['job_id'], $current['id'], clean($data['cover_note'])]); respond(['message' => 'Application submitted.'], 201); }
  if ($action === 'applications') { if ($current['role'] === 'employer') { $stmt = db()->prepare('SELECT a.*, j.title, u.full_name, u.email, u.headline, u.skills FROM applications a JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.user_id WHERE j.employer_id = ? ORDER BY a.applied_at DESC'); $stmt->execute([$current['id']]); } else { $stmt = db()->prepare('SELECT a.*, j.title, j.company_name, j.location FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.user_id = ? ORDER BY a.applied_at DESC'); $stmt->execute([$current['id']]); } respond(['applications' => $stmt->fetchAll()]); }
  respond(['error' => 'Unknown action.'], 404);
} catch (PDOException $e) { respond(['error' => $e->errorInfo[2] ?? 'Database request failed.'], 500); }
