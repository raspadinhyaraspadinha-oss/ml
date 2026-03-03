/**
 * Fix script for 12 old-format product pages
 * Fixes: titles, installment amounts, and reviews
 */
const fs = require('fs');
const path = require('path');

const BASE = path.join(__dirname, '..', 'produtos');

const PRODUCTS = {
  'celular-azul': {
    title: 'iPhone 15 (256GB) Titanio Branco',
    price: 187.58,
    reviews: [
      { text: 'Celular impecavel, tela linda e camera incrivel. Chegou antes do prazo, super recomendo!', date: 'fev. 2025', likes: 47 },
      { text: 'Qualidade Apple, como sempre. Bateria dura o dia todo sem problemas. Vale muito a pena.', date: 'jan. 2025', likes: 38 },
    ]
  },
  'celular-rosa': {
    title: 'iPhone 15 (256GB) Rosa',
    price: 187.58,
    reviews: [
      { text: 'Amei a cor! Muito lindo e rapido. Camera excelente para fotos e videos.', date: 'fev. 2025', likes: 52 },
      { text: 'Presente perfeito! Minha esposa adorou, funciona perfeitamente. Entrega rapida.', date: 'jan. 2025', likes: 31 },
    ]
  },
  'celular-titanio': {
    title: 'iPhone 15 (512GB) Titanio',
    price: 197.58,
    reviews: [
      { text: 'Material titanio e muito premium, leve e resistente. Melhor iPhone que ja tive.', date: 'fev. 2025', likes: 55 },
      { text: 'Armazenamento de 512GB e perfeito, nao preciso me preocupar com espaco. Otima compra!', date: 'jan. 2025', likes: 42 },
    ]
  },
  'celular-verde': {
    title: 'iPhone 15 (512GB) Titanio Preto',
    price: 224.90,
    reviews: [
      { text: 'Design sofisticado, cor preta titanio e elegantissima. Performance absurda!', date: 'fev. 2025', likes: 61 },
      { text: 'Chegou perfeito na caixa, tudo original. Estou muito satisfeito com a compra.', date: 'jan. 2025', likes: 35 },
    ]
  },
  'caixa-de-som-1': {
    title: 'JBL Boombox 3 Preto',
    price: 136.99,
    reviews: [
      { text: 'Som potentissimo! Grave absurdo pra esse tamanho. Bateria dura muito tambem.', date: 'fev. 2025', likes: 44 },
      { text: 'Levei pra praia e aguentou tudo. A prova d\'agua funciona de verdade. Recomendo!', date: 'jan. 2025', likes: 39 },
    ]
  },
  'caixa-de-som-2': {
    title: 'JBL PartyBox 710 Preta',
    price: 179.98,
    reviews: [
      { text: 'Essa caixa e um show! Som alto, grave forte, LED incrivel. Festa garantida!', date: 'fev. 2025', likes: 57 },
      { text: 'Comprei pra usar em eventos e nao me arrependi. Qualidade de som profissional.', date: 'jan. 2025', likes: 41 },
    ]
  },
  'caixa-de-som-3': {
    title: 'JBL Flip 6 Preto',
    price: 27.80,
    reviews: [
      { text: 'Compacta mas o som impressiona! Levo pra todo lugar. Bateria excelente.', date: 'fev. 2025', likes: 36 },
      { text: 'Conecta facil no bluetooth e o som e muito limpo. Otimo custo beneficio!', date: 'jan. 2025', likes: 28 },
    ]
  },
  'microondas': {
    title: 'Micro-ondas Brastemp 38L Inox Espelhado Com Grill Bivolt',
    price: 62.81,
    reviews: [
      { text: 'Acabamento inox espelhado e lindo na cozinha. Funcao grill e um diferencial enorme!', date: 'fev. 2025', likes: 33 },
      { text: '38 litros de capacidade, cabe tudo! Esquenta rapido e uniforme. Muito satisfeita.', date: 'jan. 2025', likes: 27 },
    ]
  },
  'parafusadeira': {
    title: 'Furadeira Parafusadeira Bosch GSB 180-LI + GDX 180-LI',
    price: 92.95,
    reviews: [
      { text: 'Kit completo Bosch, qualidade profissional! Bateria de litio dura bastante.', date: 'fev. 2025', likes: 41 },
      { text: 'Uso no dia a dia e nao decepciona. Torque excelente e boa ergonomia. Recomendo!', date: 'jan. 2025', likes: 34 },
    ]
  },
  'samsung': {
    title: 'Samsung The Freestyle Projetor Smart Portatil',
    price: 145.10,
    reviews: [
      { text: 'Projetor incrivel! Imagem nitida, facil de configurar. Perfeito pra assistir filmes em casa.', date: 'fev. 2025', likes: 48 },
      { text: 'Leve e portatil, levo pra qualquer lugar. Qualidade Samsung nao decepciona!', date: 'jan. 2025', likes: 36 },
    ]
  },
  'smart-tv': {
    title: 'Samsung Smart TV 55 Polegadas QLED 4K Q60D 2024',
    price: 192.27,
    reviews: [
      { text: 'Qualidade de imagem QLED e absurda! Cores vibrantes e pretos profundos. Adorei!', date: 'fev. 2025', likes: 53 },
      { text: 'TV enorme, 55 polegadas perfeita pra sala. Smart TV rapida e completa. Valeu cada centavo.', date: 'jan. 2025', likes: 45 },
    ]
  },
  'smartwatch': {
    title: 'Apple Watch Series 9 45mm Preto',
    price: 151.65,
    reviews: [
      { text: 'Relogio lindo, tela brilhante e funcionalidades incriveis. Monitora saude perfeitamente!', date: 'fev. 2025', likes: 50 },
      { text: 'Integra perfeito com iPhone, notificacoes no pulso e muito pratico. Excelente compra!', date: 'jan. 2025', likes: 37 },
    ]
  }
};

const starSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';

function makeReviewsHTML(reviews) {
  return reviews.map(r => `
        <div class="review-card">
          <div class="rev-header">
            <div class="rev-stars">${starSvg.repeat(5)}</div>
            <span class="rev-date">${r.date}</span>
          </div>

          <p class="rev-text">${r.text}</p>
          <div class="rev-actions">
            <button class="rev-like" onclick="this.classList.toggle('liked')">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
              <span>${r.likes}</span>
            </button>
          </div>
        </div>`).join('\n');
}

function formatBRL(num) {
  return num.toFixed(2).replace('.', ',');
}

let fixed = 0;
let errors = 0;

for (const [dir, data] of Object.entries(PRODUCTS)) {
  const filePath = path.join(BASE, dir, 'index.html');

  if (!fs.existsSync(filePath)) {
    console.log(`SKIP: ${dir} - file not found`);
    errors++;
    continue;
  }

  let html = fs.readFileSync(filePath, 'utf-8');
  const origLen = html.length;

  // 1. Fix <title>
  html = html.replace(/<title>Product - Mercado Livre<\/title>/, `<title>${data.title} - Mercado Livre</title>`);

  // 2. Fix <h1 class="product-title">
  html = html.replace(/<h1 class="product-title">Product<\/h1>/, `<h1 class="product-title">${data.title}</h1>`);

  // 3. Fix alt="Product" on main image
  html = html.replace(/alt="Product" class="main-image"/, `alt="${data.title}" class="main-image"`);

  // 4. Fix installments - calculate per-installment amount
  const perInstallment = formatBRL(data.price / 12);
  html = html.replace(
    /<div class="installments">em 12x sem juros<\/div>/,
    `<div class="installments">em 12x R$ ${perInstallment} sem juros</div>`
  );

  // 5. Fix reviews - replace the entire reviews section content
  const reviewsNew = makeReviewsHTML(data.reviews);

  // Match from <h4 class="reviews-highlight"> to the closing </div> of reviews-section
  const reviewsSectionRegex = /(<h4 class="reviews-highlight">Opinioes em destaque<\/h4>)\s*[\s\S]*?(<\/div>\s*<!-- FOOTER -->)/;
  const replacement = `$1\n${reviewsNew}\n$2`;
  html = html.replace(reviewsSectionRegex, replacement);

  if (html.length !== origLen) {
    fs.writeFileSync(filePath, html, 'utf-8');
    console.log(`FIXED: ${dir} -> "${data.title}" (12x R$ ${perInstallment})`);
    fixed++;
  } else {
    console.log(`WARN: ${dir} - no changes detected`);
    errors++;
  }
}

console.log(`\nDone! Fixed: ${fixed}/${Object.keys(PRODUCTS).length}, Errors: ${errors}`);
