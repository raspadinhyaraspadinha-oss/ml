<?php
/**
 * Page View Tracking Endpoint - NEUTERED (v2.0)
 *
 * Tracking migrated to PostHog Cloud.
 * This file now returns a 1x1 transparent GIF immediately
 * with ZERO file I/O to prevent PHP worker exhaustion.
 *
 * Kept alive as a stub in case cached pages still request it.
 */

// Return GIF immediately — no require, no file reads, no locks
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 1x1 transparent GIF pixel (43 bytes)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
