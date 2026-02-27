/**
 * fix_icons.cjs
 * Replaces all Google Material Symbols icon references with inline SVGs
 * across all 26 active product pages. This eliminates the dependency on
 * Google Fonts CDN and ensures icons always render correctly.
 */

const fs = require('fs');
const path = require('path');

const PRODUCTS_DIR = path.join(__dirname, 'produtos');

const ACTIVE_PRODUCTS = [
  'jbl01','projetor04','jbl02','lilo','sam25','iphone16-preto',
  'fritadeira','xiaome','kitferramenta','iph06','iph07','iph08',
  'geladeira','xiaomex6','xiaomex7','aspirador','guarda-branco',
  'guarda-preto','sofa','iph09','tv','ps5','microo','lavar','ar','fogao'
];

// Map of Material Symbol icon names to inline SVGs
const ICON_MAP = {
  'arrow_back_ios': '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M11.67 3.87L9.9 2.1 0 12l9.9 9.9 1.77-1.77L3.54 12z"/></svg>',
  'shopping_cart': '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM7.17 14.75l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A.996.996 0 0020.01 4H5.21l-.94-2H1v2h2l3.6 7.59-1.35 2.44C4.52 15.37 5.48 17 7 17h12v-2H7.42c-.14 0-.25-.11-.25-.25z"/></svg>',
  'bolt': '<svg width="18" height="18" viewBox="0 0 24 24" fill="#00a650" style="vertical-align:middle"><path d="M11 21h-1l1-7H7.5c-.58 0-.57-.32-.38-.66.19-.34.05-.08.07-.12C8.48 10.94 10.42 7.54 13 3h1l-1 7h3.5c.49 0 .56.33.47.51l-.07.15C12.96 17.55 11 21 11 21z"/></svg>',
  'local_fire_department': '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M12 12.9l-2.13 2.09C9.31 15.55 9 16.28 9 17.06 9 18.68 10.35 20 12 20s3-1.32 3-2.94c0-.78-.31-1.52-.87-2.07L12 12.9z"/><path d="M16 6l-.44.55C14.38 8.02 12 7.19 12 5.3V2S4 7 4 13c0 4.42 3.58 8 8 8s8-3.58 8-8c0-2.96-1.61-5.62-4-7zm-4 16c-3.31 0-6-2.69-6-6 0-3.72 3.01-6.96 4.65-8.65C11 8.34 12.21 9.69 13.4 10l.6-2 .47.36C16.42 9.92 18 12.29 18 15c0 2.76-2.24 5-5 5z"/></svg>',
  'timer': '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M15 1H9v2h6V1zm-4 13h2V8h-2v6zm8.03-6.61l1.42-1.42c-.43-.51-.9-.99-1.41-1.41l-1.42 1.42C16.07 4.74 14.12 4 12 4c-4.97 0-9 4.03-9 9s4.02 9 9 9 9-4.03 9-9c0-2.12-.74-4.07-1.97-5.61zM12 20c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg>',
  'undo': '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>',
  'verified_user': '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>',
  'emoji_events': '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/></svg>',
  'star': '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
  'thumb_up': '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>',
  'shopping_bag': '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm6 16H6V8h2v2c0 .55.45 1 1 1s1-.45 1-1V8h4v2c0 .55.45 1 1 1s1-.45 1-1V8h2v12z"/></svg>'
};

let updated = 0;
let errors = 0;

ACTIVE_PRODUCTS.forEach(function(id) {
  const filePath = path.join(PRODUCTS_DIR, id, 'index.html');

  try {
    let html = fs.readFileSync(filePath, 'utf8');

    // 1. Remove the Google Material Symbols font link
    html = html.replace(
      /<link[^>]*Material\+Symbols\+Outlined[^>]*>/gi,
      '<!-- Material Symbols replaced with inline SVGs -->'
    );

    // 2. Replace all <span class="material-symbols-outlined">icon_name</span> with SVGs
    // Handle various contexts where the span might have additional attributes
    html = html.replace(
      /<span\s+class="material-symbols-outlined"(?:\s+[^>]*)?>([^<]+)<\/span>/g,
      function(match, iconName) {
        const name = iconName.trim();
        if (ICON_MAP[name]) {
          return ICON_MAP[name];
        }
        console.warn(`  WARNING: Unknown icon "${name}" in ${id}`);
        return match; // Keep original if unknown
      }
    );

    // 3. Also handle cases where style attribute is on the span
    // e.g. <span class="material-symbols-outlined" style="...">icon</span>
    // The regex above already handles this with (?:\s+[^>]*)?

    // 4. Fix any CSS rules that reference .material-symbols-outlined
    // Change font-size rules to work with SVGs
    html = html.replace(
      /\.nav-back\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.nav-back svg{width:20px;height:20px}'
    );
    html = html.replace(
      /\.nav-cart\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.nav-cart svg{width:26px;height:26px;fill:#333}'
    );
    html = html.replace(
      /\.shipping-line\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.shipping-line svg{width:18px;height:18px}'
    );
    html = html.replace(
      /\.urgency-strip\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.urgency-strip svg{width:20px;height:20px;flex-shrink:0;animation:urgPulse 1.5s ease-in-out infinite}'
    );
    html = html.replace(
      /\.trust-item\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.trust-item svg{width:22px;height:22px;fill:#3483fa}'
    );
    html = html.replace(
      /\.cd-label\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.cd-label svg{width:18px;height:18px;fill:#ffe600}'
    );
    html = html.replace(
      /\.rev-stars\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.rev-stars svg{width:16px;height:16px;fill:#3483fa}'
    );
    html = html.replace(
      /\.rev-like\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.rev-like svg{width:16px;height:16px}'
    );
    html = html.replace(
      /\.sn-icon\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.sn-icon svg{width:16px;height:16px;fill:#fff}'
    );
    html = html.replace(
      /\.buy-btn\s+\.material-symbols-outlined\s*\{[^}]*\}/g,
      '.buy-btn svg{vertical-align:middle;margin-left:6px;width:20px;height:20px;fill:#fff}'
    );

    fs.writeFileSync(filePath, html, 'utf8');
    updated++;
    console.log(`✓ ${id} - icons replaced`);

  } catch(err) {
    errors++;
    console.error(`✗ ${id}: ${err.message}`);
  }
});

console.log(`\n=== Done: ${updated}/${ACTIVE_PRODUCTS.length} updated, ${errors} errors ===`);
