<?php
/**
 * Internal Analytics Event Recorder - NEUTERED (v2.0)
 *
 * Tracking migrated to PostHog Cloud.
 * This file now returns { "ok": true } immediately
 * with ZERO file I/O to prevent PHP worker exhaustion.
 *
 * Kept alive as a stub in case cached pages still POST to it.
 */

// Allow CORS for cached pages
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Return OK immediately — no require, no file reads, no locks
echo '{"ok":true,"migrated":"posthog"}';
exit;
