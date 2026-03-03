/**
 * Sync stock display between recompensas and product pages
 * Replaces generic "Estoque disponível + FULL" with actual stock bar
 */
const fs = require('fs');
const path = require('path');

const BASE = path.join(__dirname, '..', 'produtos');

// Stock data from recompensas/index.html
const STOCK_DATA = {
  'jbl01': { stock: 19, maxStock: 30 },
  'projetor04': { stock: 10, maxStock: 25 },
  'jbl02': { stock: 10, maxStock: 25 },
  'lilo': { stock: 24, maxStock: 40 },
  'sam25': { stock: 16, maxStock: 30 },
  'iphone16-preto': { stock: 8, maxStock: 20 },
  'fritadeira': { stock: 15, maxStock: 30 },
  'xiaome': { stock: 14, maxStock: 25 },
  'kitferramenta': { stock: 8, maxStock: 20 },
  'iph06': { stock: 7, maxStock: 20 },
  'iph07': { stock: 6, maxStock: 20 },
  'iph08': { stock: 8, maxStock: 20 },
  'geladeira': { stock: 18, maxStock: 30 },
  'xiaomex6': { stock: 9, maxStock: 20 },
  'xiaomex7': { stock: 12, maxStock: 25 },
  'aspirador': { stock: 18, maxStock: 30 },
  'guarda-branco': { stock: 16, maxStock: 30 },
  'guarda-preto': { stock: 7, maxStock: 20 },
  'sofa': { stock: 18, maxStock: 30 },
  'iph09': { stock: 4, maxStock: 15 },
  'tv': { stock: 12, maxStock: 25 },
  'ps5': { stock: 7, maxStock: 20 },
  'microo': { stock: 4, maxStock: 15 },
  'lavar': { stock: 8, maxStock: 20 },
  'ar': { stock: 9, maxStock: 20 },
  'fogao': { stock: 7, maxStock: 20 }
};

function getStockLevel(stock, max) {
  const pct = (stock / max) * 100;
  if (pct > 60) return 'high';
  if (pct > 30) return 'medium';
  return 'low';
}

function getStockWidth(stock, max) {
  return Math.max(8, (stock / max) * 100);
}

function getStockMessage(stock, level) {
  if (level === 'low') return `<span style="color:#f44336;font-weight:700">⚡ Ultimas ${stock} unidades!</span>`;
  if (level === 'medium') return `<span style="color:#ff9800;font-weight:600">Restam ${stock} unidades</span>`;
  return `<span style="color:#00a650;font-weight:600">Em estoque: ${stock} un.</span>`;
}

function getBarColor(level) {
  if (level === 'low') return '#f44336';
  if (level === 'medium') return '#ff9800';
  return '#00a650';
}

function makeStockHTML(stock, maxStock) {
  const level = getStockLevel(stock, maxStock);
  const width = getStockWidth(stock, maxStock).toFixed(0);
  const message = getStockMessage(stock, level);
  const color = getBarColor(level);

  return `<div class="stock-row">
    <div style="width:100%">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <span class="stock-label">${message}</span>
      </div>
      <div style="background:#e0e0e0;border-radius:4px;height:6px;width:100%;overflow:hidden">
        <div style="background:${color};height:100%;width:${width}%;border-radius:4px;transition:width 0.5s ease"></div>
      </div>
    </div>
  </div>`;
}

let fixed = 0;
let errors = 0;

for (const [dir, data] of Object.entries(STOCK_DATA)) {
  const filePath = path.join(BASE, dir, 'index.html');

  if (!fs.existsSync(filePath)) {
    console.log(`SKIP: ${dir} - file not found`);
    errors++;
    continue;
  }

  let html = fs.readFileSync(filePath, 'utf-8');
  const origLen = html.length;

  // Pattern 1: New-format stock row with FULL badge
  // <div class="stock-row">
  //   <span class="stock-label">Estoque disponivel</span>
  //   <span class="stock-full">...</span>
  // </div>
  const stockRowRegex = /<div class="stock-row">\s*<span class="stock-label">Estoque dispon[ií]vel<\/span>\s*<span class="stock-full">[\s\S]*?<\/span>\s*<\/div>/;

  const newStockHTML = makeStockHTML(data.stock, data.maxStock);

  if (stockRowRegex.test(html)) {
    html = html.replace(stockRowRegex, newStockHTML);
  } else {
    console.log(`WARN: ${dir} - stock-row pattern not found, trying alternate`);
    // Try alternate: just find any stock-row div
    const altRegex = /<div class="stock-row">[\s\S]*?<\/div>\s*(?=\s*<\/div>)/;
    if (altRegex.test(html)) {
      html = html.replace(altRegex, newStockHTML);
    } else {
      console.log(`SKIP: ${dir} - no stock display found`);
      errors++;
      continue;
    }
  }

  if (html.length !== origLen) {
    fs.writeFileSync(filePath, html, 'utf-8');
    const level = getStockLevel(data.stock, data.maxStock);
    console.log(`FIXED: ${dir} -> stock ${data.stock}/${data.maxStock} (${level})`);
    fixed++;
  } else {
    console.log(`WARN: ${dir} - no changes detected`);
    errors++;
  }
}

console.log(`\nDone! Fixed: ${fixed}/${Object.keys(STOCK_DATA).length}, Errors: ${errors}`);
