<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Database;

Auth::requireLogin();

$pdo = Database::connection();

// ── Queue stats ──────────────────────────────────────────────
$queueStats = $pdo->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(CASE WHEN status = 'PENDING'  THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN status = 'FLAGGED'  THEN 1 ELSE 0 END) AS flagged,
        SUM(CASE WHEN status = 'EXECUTED' THEN 1 ELSE 0 END) AS executed,
        SUM(CASE WHEN needs_split IS TRUE THEN 1 ELSE 0 END) AS needs_split
    FROM files
")->fetch();

// ── Confidence breakdown (pending only) ──────────────────────
$confidenceStats = $pdo->query("
    SELECT confidence, COUNT(*) AS cnt
    FROM files
    WHERE status = 'PENDING'
    GROUP BY confidence
    ORDER BY CASE confidence WHEN 'LOW' THEN 1 WHEN 'MEDIUM' THEN 2 WHEN 'HIGH' THEN 3 END
")->fetchAll();

// ── Recent scan jobs ─────────────────────────────────────────
$recentScans = $pdo->query("
    SELECT sj.*, s.name AS source_name, u.email AS created_by_email
    FROM scan_jobs sj
    JOIN sources s ON s.id = sj.source_id
    JOIN users u   ON u.id = sj.created_by
    ORDER BY sj.created_at DESC
    LIMIT 5
")->fetchAll();

// ── Recent audit entries ─────────────────────────────────────
$recentAudit = $pdo->query("
    SELECT * FROM audit_log
    ORDER BY created_at DESC
    LIMIT 10
")->fetchAll();

// ── Pending split queue count ─────────────────────────────────
$splitPending = (int) $pdo->query("
    SELECT COUNT(*) FROM split_queue WHERE status = 'PENDING'
")->fetchColumn();

$dashboardTab = 'operations';
$title = 'Dashboard — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/dashboard/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
