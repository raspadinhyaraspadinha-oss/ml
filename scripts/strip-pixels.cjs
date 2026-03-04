/**
 * Strip Tracking Pixels - Remove /api/track.php pixel scripts from ALL HTML files
 *
 * PostHog Cloud now handles pageview tracking automatically.
 * This script removes the old <script>...</script> blocks that create
 * Image() beacons pointing to /api/track.php.
 *
 * Run: node scripts/strip-pixels.cjs
 */
const fs = require('fs');
const path = require('path');

const BASE = path.join(__dirname, '..');

// Recursively find all .html files
function findHTMLFiles(dir) {
  let results = [];
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    // Skip hidden dirs, node_modules, painel
    if (entry.isDirectory()) {
      if (entry.name.startsWith('.') || entry.name === 'node_modules' || entry.name === 'painel') continue;
      results = results.concat(findHTMLFiles(fullPath));
    } else if (entry.name.endsWith('.html')) {
      results.push(fullPath);
    }
  }
  return results;
}

// Pattern 1: Full block with <!-- Page View Tracker --> comment + script
// <!-- Page View Tracker -->
// <script>
// (function(){...track.php...})();
// </script>
const PATTERN_COMMENT_BLOCK = /\s*<!-- ?Page View Tracker ?-->\s*<script>\s*\(function\(\)\{[\s\S]*?track\.php[\s\S]*?\}\)\(\);\s*<\/script>/g;

// Pattern 2: Just the <script> block without comment (products, etc.)
// <script>
// (function(){...track.php...})();
// </script>
const PATTERN_SCRIPT_BLOCK = /\s*<script>\s*\(function\(\)\s*\{[\s\S]*?track\.php[\s\S]*?\}\)\(\);\s*<\/script>/g;

const files = findHTMLFiles(BASE);
let stripped = 0;
let skipped = 0;
let errors = 0;

for (const filePath of files) {
  try {
    let html = fs.readFileSync(filePath, 'utf-8');
    const originalLen = html.length;

    // Try pattern 1 first (with comment), then pattern 2 (without comment)
    html = html.replace(PATTERN_COMMENT_BLOCK, '');
    html = html.replace(PATTERN_SCRIPT_BLOCK, '');

    if (html.length !== originalLen) {
      fs.writeFileSync(filePath, html, 'utf-8');
      const rel = path.relative(BASE, filePath);
      const saved = originalLen - html.length;
      console.log(`STRIPPED: ${rel} (removed ${saved} bytes)`);
      stripped++;
    } else {
      // Check if it has track.php reference we missed
      if (html.indexOf('track.php') !== -1) {
        const rel = path.relative(BASE, filePath);
        console.log(`WARN: ${rel} - still contains track.php reference`);
        errors++;
      } else {
        skipped++;
      }
    }
  } catch (e) {
    const rel = path.relative(BASE, filePath);
    console.log(`ERROR: ${rel} - ${e.message}`);
    errors++;
  }
}

console.log(`\nDone! Stripped: ${stripped}, Skipped (no pixel): ${skipped}, Warnings: ${errors}`);
console.log(`Total HTML files scanned: ${files.length}`);
