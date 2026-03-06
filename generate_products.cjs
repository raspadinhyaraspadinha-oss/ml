/**
 * Product Page Generator - Modern Unified Design
 * Reads each product's index.html, extracts unique data,
 * backs up originals, generates new modern pages.
 */

const fs = require('fs');
const path = require('path');

// Active product folder names (from recompensas)
const ACTIVE_PRODUCTS = [
  'jbl01','projetor04','jbl02','lilo','sam25','iphone16-preto',
  'fritadeira','xiaome','kitferramenta','iph06','iph07','iph08',
  'geladeira','xiaomex6','xiaomex7','aspirador','guarda-branco',
  'guarda-preto','sofa','iph09','tv','ps5','microo','lavar','ar','fogao'
];

const BASE = __dirname;
const PRODUTOS_DIR = path.join(BASE, 'produtos');

// ===== EXTRACTION =====
function extractData(html, folderId) {
  const d = { id: folderId };

  // Title
  let m = html.match(/<p\s+class="title"[^>]*>([\s\S]*?)<\/p>/i);
  if (m) d.title = m[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
  else d.title = folderId;

  // Sales count
  m = html.match(/product-status-custom[\s\S]*?<span>\s*([\s\S]*?)\s*<\/span>/i);
  if (m) d.sales = m[1].trim();
  else d.sales = '+500 vendidos';

  // Rating number
  m = html.match(/product-rating-custom[\s\S]*?<span>\s*([\d.]+)\s*<\/span>/i);
  if (m) d.rating = m[1].trim();
  else d.rating = '4.8';

  // Review count
  m = html.match(/\((\d[\d,.]*)\)\s*<\/span>\s*<\/div>\s*<\/div>/i);
  if (m) d.reviewCount = m[1].trim();
  else d.reviewCount = '500';

  // Main image
  m = html.match(/class="main-image"[^>]*src="([^"]+)"/i) || html.match(/src="([^"]+)"[^>]*class="main-image"/i);
  if (m) d.mainImage = m[1];
  else d.mainImage = 'images/main.jpg';

  // Thumbnails
  d.thumbnails = [];
  const thumbBlock = html.match(/<div\s+class="thumbnails"[^>]*>([\s\S]*?)<\/div>/i);
  if (thumbBlock) {
    const imgRe = /src="([^"]+)"/gi;
    let im;
    while ((im = imgRe.exec(thumbBlock[1])) !== null) {
      d.thumbnails.push(im[1]);
    }
  }
  if (d.thumbnails.length === 0) d.thumbnails = [d.mainImage];

  // Old price
  m = html.match(/class="old-price2"[^>]*>([\s\S]*?)<\/div>/i);
  if (m) d.oldPrice = m[1].replace(/<[^>]+>/g, '').trim();
  else d.oldPrice = 'R$ 999,00';

  // New price
  m = html.match(/class="new-price2"[^>]*>([\s\S]*?)<\/span>/i);
  if (m) d.newPrice = m[1].replace(/<[^>]+>/g, '').trim();
  else d.newPrice = 'R$ 99,00';

  // Discount
  m = html.match(/class="discount2"[^>]*>([\s\S]*?)<\/span>/i);
  if (m) d.discount = m[1].replace(/<[^>]+>/g, '').trim();
  else d.discount = '95% OFF';

  // Rating bar image
  m = html.match(/Opini[oõ]es do produto[\s\S]*?<img\s+src="([^"]+)"/i);
  if (m) d.ratingImage = m[1];
  else d.ratingImage = null;

  // Reviews
  d.reviews = [];
  const commentRe = /<div\s+class="comment">([\s\S]*?)(?=<div\s+class="comment">|<div\s+class="divisoria"|<\/div>\s*<div\s+class="divisoria"|$)/gi;
  // Simpler approach: split by comment blocks
  const commentParts = html.split(/<div\s+class="comment">/i);
  for (let i = 1; i < commentParts.length; i++) {
    const block = commentParts[i];
    const rev = {};

    // Stars count
    const stars = (block.match(/class="material-symbols-outlined stars">star</g) || []).length;
    rev.stars = stars || 5;

    // Date
    let dm = block.match(/class="date">([\s\S]*?)<\/div>/i);
    if (dm) rev.date = dm[1].trim();
    else rev.date = '';

    // Review images
    rev.images = [];
    const imgBlock = block.match(/<div\s+class="images">([\s\S]*?)<\/div>/i);
    if (imgBlock) {
      const imRe = /src="([^"]+)"/gi;
      let imm;
      while ((imm = imRe.exec(imgBlock[1])) !== null) {
        rev.images.push(imm[1]);
      }
    }

    // Review text
    let pm = block.match(/<\/div>\s*<p>([\s\S]*?)<\/p>/i);
    if (!pm) pm = block.match(/<p>([\s\S]*?)<\/p>/i);
    if (pm) rev.text = pm[1].trim();
    else rev.text = '';

    // Like count
    let lm = block.match(/class="count">([\d]+)<\/span>/i);
    if (lm) rev.likes = lm[1];
    else rev.likes = '0';

    if (rev.text && rev.text.length > 5) {
      d.reviews.push(rev);
    }
  }

  return d;
}

// ===== TEMPLATE =====
function generatePage(d) {
  const thumbsHTML = d.thumbnails.map((t, i) =>
    `<div class="thumb${i === 0 ? ' active' : ''}" onclick="changeImage(this, '${t}')"><img src="${t}" alt="Vista ${i+1}" loading="lazy"></div>`
  ).join('\n          ');

  const reviewsHTML = d.reviews.map(r => {
    const starsStr = Array(r.stars).fill('<span class="material-symbols-outlined">star</span>').join('');
    const imgsStr = r.images.length > 0
      ? `<div class="rev-images">${r.images.map(im => `<img src="${im}" alt="Review" loading="lazy">`).join('')}</div>`
      : '';
    return `
        <div class="review-card">
          <div class="rev-header">
            <div class="rev-stars">${starsStr}</div>
            <span class="rev-date">${r.date}</span>
          </div>
          ${imgsStr}
          <p class="rev-text">${r.text}</p>
          <div class="rev-actions">
            <button class="rev-like" onclick="this.classList.toggle('liked')">
              <span class="material-symbols-outlined">thumb_up</span>
              <span>${r.likes}</span>
            </button>
          </div>
        </div>`;
  }).join('\n');

  const ratingBarHTML = d.ratingImage
    ? `<img src="${d.ratingImage}" alt="Rating" class="rating-bar-img" loading="lazy">`
    : '';

  // Calculate installments
  const priceNum = parseFloat(d.newPrice.replace(/[^\d,]/g, '').replace(',', '.'));
  const instValue = (priceNum / 12).toFixed(2).replace('.', ',');

  return `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>${d.title} - Mercado Livre</title>
<link rel="icon" href="images/favicon.png">
<link rel="shortcut icon" href="images/favicon.png">
<link rel="stylesheet" href="css/fonts.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,1,0">

<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '895868873361776');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=895868873361776&ev=PageView&noscript=1"
/></noscript>

<!-- TikTok Pixel Code Start -->
<script>
!function (w, d, t) {
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script")
;n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
  ttq.load('D5MQFEBC77UEK8Q4IIB0');
  ttq.page();
}(window, document, 'ttq');
</script>

<!-- UTMify Pixel -->
<script>
  window.pixelId = "699df39a5e21aedf65f652e7";
  var a = document.createElement("script");
  a.setAttribute("async", "");
  a.setAttribute("defer", "");
  a.setAttribute("src", "https://cdn.utmify.com.br/scripts/pixel/pixel.js");
  document.head.appendChild(a);
</script>
<script src="https://cdn.utmify.com.br/scripts/utms/latest.js" data-utmify-prevent-xcod-sck data-utmify-prevent-subids async defer></script>

<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth;-webkit-tap-highlight-color:transparent}
body{font-family:'GellixRegular',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f5f5;color:#333;overflow-x:hidden;-webkit-font-smoothing:antialiased}

/* TOP BANNER */
.top-bar{background:linear-gradient(135deg,#ffe600,#f5d000);text-align:center;padding:8px 16px;font-family:'GellixSemiBold',sans-serif;font-size:12px;color:#333}

/* NAVBAR */
.nav{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#fff;border-bottom:1px solid #eee;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.nav-back{display:flex;align-items:center;gap:4px;font-family:'GellixMedium',sans-serif;font-size:14px;color:#3483fa;cursor:pointer;text-decoration:none;padding:4px 0}
.nav-back .material-symbols-outlined{font-size:20px}
.nav-logo img{height:28px}
.nav-cart{position:relative;cursor:pointer;padding:6px}
.nav-cart .material-symbols-outlined{font-size:26px;color:#333}
.cart-badge{position:absolute;top:0;right:0;background:#3483fa;color:#fff;font-family:'GellixBold',sans-serif;font-size:10px;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;transform:scale(0);transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.cart-badge.visible{transform:scale(1)}

/* SOCIAL PROOF BAR */
.social-bar{display:flex;align-items:center;justify-content:space-between;padding:8px 16px;background:#fff;border-bottom:1px solid #f0f0f0}
.sb-sales{font-family:'GellixMedium',sans-serif;font-size:12px;color:#00a650}
.sb-rating{display:flex;align-items:center;gap:4px;font-family:'GellixRegular',sans-serif;font-size:12px;color:#999}
.sb-rating .stars{color:#3483fa;letter-spacing:-1px}
.sb-rating strong{color:#333}

/* PRODUCT TITLE */
.product-title{padding:12px 16px 8px;font-family:'GellixMedium',sans-serif;font-size:16px;color:#333;line-height:1.4;background:#fff}

/* IMAGE GALLERY */
.gallery{background:#fff;padding:0 16px 12px;position:relative}
.main-img-wrap{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#fafafa;border-radius:12px;overflow:hidden;margin-bottom:8px}
.main-img-wrap img{max-height:85%;max-width:85%;object-fit:contain;transition:opacity .3s}
.thumbs-row{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.thumbs-row::-webkit-scrollbar{display:none}
.thumb{width:56px;height:56px;border-radius:8px;overflow:hidden;border:2px solid transparent;flex-shrink:0;cursor:pointer;transition:border-color .2s}
.thumb.active{border-color:#3483fa}
.thumb img{width:100%;height:100%;object-fit:cover}

/* PRICE SECTION */
.price-section{background:#fff;padding:12px 16px 16px;margin-top:6px}
.offer-badge{display:inline-block;background:linear-gradient(135deg,#3483fa,#2968cc);color:#fff;font-family:'GellixSemiBold',sans-serif;font-size:10px;padding:3px 10px;border-radius:4px;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px}
.old-price{font-family:'GellixRegular',sans-serif;font-size:13px;color:#999;text-decoration:line-through}
.price-row{display:flex;align-items:baseline;gap:8px;margin:4px 0}
.new-price{font-family:'GellixBold',sans-serif;font-size:28px;color:#333}
.discount-tag{font-family:'GellixSemiBold',sans-serif;font-size:14px;color:#00a650}
.installments{font-family:'GellixRegular',sans-serif;font-size:12px;color:#00a650;margin-top:2px}
.shipping-line{display:flex;align-items:center;gap:4px;margin-top:8px;font-family:'GellixMedium',sans-serif;font-size:13px;color:#00a650}
.shipping-line .material-symbols-outlined{font-size:18px;color:#00a650}
.shipping-line .full{font-family:'GellixBold',sans-serif}

/* BUY BUTTON */
.buy-btn{display:block;width:calc(100% - 32px);margin:12px 16px;padding:16px;background:linear-gradient(135deg,#3483fa,#2968cc);color:#fff;font-family:'GellixBold',sans-serif;font-size:16px;border:none;border-radius:10px;cursor:pointer;text-align:center;letter-spacing:.5px;position:relative;overflow:hidden;box-shadow:0 4px 12px rgba(52,131,250,.3);transition:transform .15s}
.buy-btn:active{transform:scale(.97)}
.buy-btn::after{content:'';position:absolute;top:0;left:-100%;width:200%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);animation:btnShimmer 3s ease-in-out infinite}
@keyframes btnShimmer{0%{transform:translateX(-50%)}100%{transform:translateX(50%)}}
.buy-btn .material-symbols-outlined{vertical-align:middle;margin-left:6px;font-size:20px}

/* URGENCY STRIP */
.urgency-strip{margin:0 16px 8px;background:linear-gradient(135deg,#ff6b35,#f23d4f);border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:8px;color:#fff}
.urgency-strip .material-symbols-outlined{font-size:20px;flex-shrink:0;animation:urgPulse 1.5s ease-in-out infinite}
@keyframes urgPulse{0%,100%{opacity:1}50%{opacity:.6}}
.urgency-strip span{font-family:'GellixMedium',sans-serif;font-size:12px;line-height:1.3}
.urgency-strip strong{font-family:'GellixBold',sans-serif}

/* TRUST BADGES */
.trust-row{display:flex;gap:8px;padding:12px 16px;background:#fff;margin-top:6px}
.trust-item{flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;padding:10px 4px;background:#f8f9fa;border-radius:8px}
.trust-item .material-symbols-outlined{font-size:22px;color:#3483fa}
.trust-item span{font-family:'GellixMedium',sans-serif;font-size:9px;color:#666;line-height:1.3}

/* COUNTDOWN */
.countdown-section{margin:8px 16px;background:linear-gradient(135deg,#011E51,#6b2fa0,#a52aad);border-radius:10px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;color:#fff;box-shadow:0 3px 12px rgba(1,30,81,.2)}
.cd-label{font-family:'GellixMedium',sans-serif;font-size:12px;display:flex;align-items:center;gap:6px}
.cd-label .material-symbols-outlined{font-size:18px;color:#ffe600}
.cd-timer{display:flex;gap:3px;align-items:center}
.cd-block{background:rgba(255,255,255,.15);backdrop-filter:blur(4px);border-radius:5px;padding:3px 6px;min-width:30px;text-align:center}
.cd-block .n{font-family:'GellixBold',sans-serif;font-size:14px;display:block;line-height:1.2}
.cd-block .u{font-size:7px;opacity:.7;text-transform:uppercase}
.cd-sep{font-family:'GellixBold',sans-serif;font-size:14px;opacity:.5}

/* VIEWERS */
.viewers-bar{margin:8px 16px;background:#fff;border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:8px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.live-dot{width:7px;height:7px;background:#e53935;border-radius:50%;animation:livePulse 1.5s ease-in-out infinite;flex-shrink:0}
@keyframes livePulse{0%,100%{box-shadow:0 0 0 0 rgba(229,57,53,.4)}50%{box-shadow:0 0 0 5px rgba(229,57,53,0)}}
.viewers-bar span{font-family:'GellixMedium',sans-serif;font-size:11px;color:#666}
.viewers-bar strong{color:#333}

/* REVIEWS SECTION */
.reviews-section{background:#fff;margin-top:6px;padding:16px}
.reviews-title{font-family:'GellixBold',sans-serif;font-size:16px;margin-bottom:12px}
.rating-bar-img{width:100%;border-radius:8px;margin-bottom:16px}
.reviews-highlight{font-family:'GellixSemiBold',sans-serif;font-size:14px;margin-bottom:12px;color:#333}
.review-card{border-bottom:1px solid #f0f0f0;padding:12px 0}
.review-card:last-child{border-bottom:none}
.rev-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.rev-stars{display:flex;gap:0}
.rev-stars .material-symbols-outlined{font-size:16px;color:#3483fa}
.rev-date{font-family:'GellixRegular',sans-serif;font-size:11px;color:#999}
.rev-images{display:flex;gap:6px;margin-bottom:8px;overflow-x:auto}
.rev-images img{width:72px;height:72px;object-fit:cover;border-radius:8px;flex-shrink:0}
.rev-text{font-family:'GellixRegular',sans-serif;font-size:13px;color:#555;line-height:1.5}
.rev-actions{margin-top:6px}
.rev-like{display:flex;align-items:center;gap:4px;border:none;background:none;cursor:pointer;font-family:'GellixMedium',sans-serif;font-size:12px;color:#999;padding:4px 0}
.rev-like.liked{color:#3483fa}
.rev-like .material-symbols-outlined{font-size:16px}

/* FOOTER */
.site-footer{background:#fff;padding:20px 16px;margin-top:8px;border-top:1px solid #eee}
.footer-links{font-family:'GellixSemiBold',sans-serif;font-size:11px;color:#333;line-height:1.8;margin-bottom:10px}
.footer-legal{font-family:'GellixRegular',sans-serif;font-size:10px;color:#999;line-height:1.6}

/* SOCIAL PROOF NOTIFICATION */
.social-notif{position:fixed;bottom:16px;left:16px;right:16px;max-width:340px;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.12);padding:10px 12px;display:flex;align-items:center;gap:8px;transform:translateY(120%);opacity:0;transition:all .4s cubic-bezier(.34,1.56,.64,1);z-index:500;border-left:3px solid #00a650}
.social-notif.show{transform:translateY(0);opacity:1}
.sn-icon{width:32px;height:32px;background:linear-gradient(135deg,#00a650,#00c853);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sn-icon .material-symbols-outlined{font-size:16px;color:#fff}
.sn-text{flex:1;font-family:'GellixRegular',sans-serif;font-size:11px;color:#666}
.sn-text strong{font-family:'GellixBold',sans-serif;color:#333}
.sn-time{font-family:'GellixRegular',sans-serif;font-size:9px;color:#aaa;white-space:nowrap}

/* TOAST */
.toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(30px);background:#333;color:#fff;font-family:'GellixMedium',sans-serif;font-size:13px;padding:10px 20px;border-radius:8px;opacity:0;pointer-events:none;transition:all .3s;z-index:999;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.15)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* ANIMATION */
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.animate-in{animation:fadeIn .4s ease forwards}
</style>
</head>
<body>

<!-- TOP BANNER -->
<div class="top-bar">Promocao exclusiva: <strong>25 anos Mercado Livre</strong></div>

<!-- NAVBAR -->
<nav class="nav">
  <a class="nav-back" onclick="history.back();">
    <span class="material-symbols-outlined">arrow_back_ios</span>
    Voltar
  </a>
  <div class="nav-logo">
    <img src="images/logo.webp" alt="Mercado Livre">
  </div>
  <div class="nav-cart" id="navCart" onclick="handleCartClick()">
    <span class="material-symbols-outlined">shopping_cart</span>
    <div class="cart-badge" id="cartBadge">0</div>
  </div>
</nav>

<!-- SOCIAL PROOF BAR -->
<div class="social-bar">
  <span class="sb-sales">${d.sales}</span>
  <div class="sb-rating">
    <strong>${d.rating}</strong>
    <span class="stars">★★★★★</span>
    <span>(${d.reviewCount})</span>
  </div>
</div>

<!-- TITLE -->
<h1 class="product-title">${d.title}</h1>

<!-- IMAGE GALLERY -->
<div class="gallery">
  <div class="main-img-wrap">
    <img src="${d.mainImage}" alt="${d.title}" class="main-image" id="mainImg">
  </div>
  <div class="thumbs-row">
    ${thumbsHTML}
  </div>
</div>

<!-- PRICE SECTION -->
<div class="price-section">
  <div class="offer-badge">OFERTA DO DIA</div>
  <div class="old-price">${d.oldPrice}</div>
  <div class="price-row">
    <span class="new-price">${d.newPrice}</span>
    <span class="discount-tag">${d.discount}</span>
  </div>
  <div class="installments">em 12x R$ ${instValue} sem juros</div>
  <div class="shipping-line">
    <span class="material-symbols-outlined">bolt</span>
    Frete <span class="full">FULL</span> - Envio gratis
  </div>
</div>

<!-- URGENCY -->
<div class="urgency-strip">
  <span class="material-symbols-outlined">local_fire_department</span>
  <span><strong>Alta demanda!</strong> Ultimas unidades com esse preço. Não perca essa oportunidade.</span>
</div>

<!-- BUY BUTTON -->
<button class="buy-btn pergunta-botao" id="buyBtn">
  COMPRAR AGORA
  <span class="material-symbols-outlined">shopping_cart</span>
</button>

<!-- COUNTDOWN -->
<div class="countdown-section">
  <div class="cd-label">
    <span class="material-symbols-outlined">timer</span>
    Oferta expira em
  </div>
  <div class="cd-timer">
    <div class="cd-block"><span class="n" id="cdH">01</span><span class="u">hrs</span></div>
    <span class="cd-sep">:</span>
    <div class="cd-block"><span class="n" id="cdM">25</span><span class="u">min</span></div>
    <span class="cd-sep">:</span>
    <div class="cd-block"><span class="n" id="cdS">39</span><span class="u">seg</span></div>
  </div>
</div>

<!-- VIEWERS -->
<div class="viewers-bar">
  <div class="live-dot"></div>
  <span><strong id="viewerCount">183</strong> pessoas vendo este produto agora</span>
</div>

<!-- TRUST BADGES -->
<div class="trust-row">
  <div class="trust-item">
    <span class="material-symbols-outlined">undo</span>
    <span>Devolução<br>gratis 30 dias</span>
  </div>
  <div class="trust-item">
    <span class="material-symbols-outlined">verified_user</span>
    <span>Compra<br>Garantida</span>
  </div>
  <div class="trust-item">
    <span class="material-symbols-outlined">emoji_events</span>
    <span>12 meses<br>de garantia</span>
  </div>
</div>

<!-- REVIEWS -->
<div class="reviews-section">
  <h3 class="reviews-title">Opiniões do produto</h3>
  ${ratingBarHTML}
  <h4 class="reviews-highlight">Opiniões em destaque</h4>
  ${reviewsHTML}
</div>

<!-- FOOTER -->
<div class="site-footer">
  <div class="footer-links">Termos e condicoes &middot; Privacidade &middot; Acessibilidade &middot; Blog &middot; Afiliados</div>
  <div class="footer-legal">&copy; 1999-2024 Ebazar.com.br LTDA. CNPJ n.o 03.007.331/0001-41 / Av. das Nacoes Unidas, no 3.003, Bonfim, Osasco/SP - CEP 06233-903 - empresa do grupo Mercado Livre.<br><br><strong>Brasil/Portugues</strong></div>
</div>

<!-- TOAST -->
<div class="toast" id="toast">Carrinho vazio! Clique em COMPRAR primeiro.</div>

<!-- SOCIAL PROOF -->
<div class="social-notif" id="socialNotif">
  <div class="sn-icon"><span class="material-symbols-outlined">shopping_bag</span></div>
  <div class="sn-text"><strong id="snName">Carlos</strong> acabou de comprar com desconto</div>
  <div class="sn-time" id="snTime">agora</div>
</div>

<!-- SCRIPTS -->
<script src="../../js/cart.js"></script>
<script>
/* Thumbnail gallery */
function changeImage(el, src) {
  document.getElementById('mainImg').src = src;
  document.querySelectorAll('.thumb').forEach(function(t){ t.classList.remove('active'); });
  el.classList.add('active');
}

/* Cart badge */
function updateCartBadge(){
  var badge=document.getElementById('cartBadge');
  try{var items=JSON.parse(localStorage.getItem('ml_cart')||'[]');
  var c=0;for(var i=0;i<items.length;i++)c+=items[i].quantity||1;
  badge.textContent=c;badge.classList.toggle('visible',c>0);}catch(e){}
}
function handleCartClick(){
  try{var items=JSON.parse(localStorage.getItem('ml_cart')||'[]');
  if(items.length>0){window.location.href='../../checkout/index.html'+window.location.search;}
  else{var t=document.getElementById('toast');t.classList.add('show');setTimeout(function(){t.classList.remove('show');},2500);}
  }catch(e){}
}

/* Countdown */
(function(){var h=1,m=25,s=39;
var hE=document.getElementById('cdH'),mE=document.getElementById('cdM'),sE=document.getElementById('cdS');
setInterval(function(){if(--s<0){s=59;m--;}if(m<0){m=59;h--;}if(h<0){h=0;m=0;s=0;}
hE.textContent=h.toString().padStart(2,'0');mE.textContent=m.toString().padStart(2,'0');sE.textContent=s.toString().padStart(2,'0');},1000);
})();

/* Viewers */
(function(){var el=document.getElementById('viewerCount');var b=120+Math.floor(Math.random()*100);el.textContent=b;
setInterval(function(){b+=Math.floor(Math.random()*7)-3;if(b<80)b=85+Math.floor(Math.random()*15);if(b>300)b=280-Math.floor(Math.random()*15);el.textContent=b;},5000);
})();

/* Social proof */
(function(){var names=['Carlos','Maria','Joao','Ana','Pedro','Juliana','Rafael','Fernanda','Lucas','Adriana','Bruno','Camila','Gabriel','Patricia','Diego','Amanda','Thiago','Vanessa','Marcos','Leticia','Felipe','Beatriz','Gustavo','Jessica','Igor'];
var times=['agora','ha 1 min','ha 2 min','ha 3 min'];
var el=document.getElementById('socialNotif'),nEl=document.getElementById('snName'),tEl=document.getElementById('snTime');
function show(){nEl.textContent=names[Math.floor(Math.random()*names.length)];
tEl.textContent=times[Math.floor(Math.random()*times.length)];
el.classList.add('show');setTimeout(function(){el.classList.remove('show');},4000);
setTimeout(show,(Math.floor(Math.random()*8)+6)*1000);}
setTimeout(show,7000);
})();

/* Init */
document.addEventListener('DOMContentLoaded',function(){
  updateCartBadge();
  window.addEventListener('storage',updateCartBadge);
});
</script>

<!-- Page View Tracker -->
<script>
(function(){
  var p=encodeURIComponent(location.pathname);
  var r=encodeURIComponent(document.referrer);
  var params=new URLSearchParams(location.search);
  var qs='page='+p+'&ref='+r;
  ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(function(k){
    var v=params.get(k);if(v)qs+='&'+k+'='+encodeURIComponent(v);
  });
  var img=new Image();img.src='/api/track.php?'+qs+'&t='+Date.now();
})();
</script>
</body>
</html>`;
}

// ===== MAIN =====
let success = 0;
let errors = [];

ACTIVE_PRODUCTS.forEach(function(id) {
  const dir = path.join(PRODUTOS_DIR, id);
  const indexPath = path.join(dir, 'index.html');
  const bkpPath = path.join(dir, 'index_bkp.html');

  if (!fs.existsSync(indexPath)) {
    errors.push(id + ': index.html not found');
    return;
  }

  // Read original
  const html = fs.readFileSync(indexPath, 'utf-8');

  // Backup (only if no backup exists yet)
  if (!fs.existsSync(bkpPath)) {
    fs.writeFileSync(bkpPath, html, 'utf-8');
    console.log('[BKP] ' + id + '/index_bkp.html created');
  } else {
    console.log('[BKP] ' + id + '/index_bkp.html already exists, skipping backup');
  }

  // Extract data
  const data = extractData(html, id);

  // Verify extraction
  if (!data.title || data.title === id) {
    console.log('[WARN] ' + id + ': title extraction may have failed, using folder name');
  }
  console.log('[DATA] ' + id + ': "' + data.title + '" | ' + data.newPrice + ' | ' + data.reviews.length + ' reviews | thumbs: ' + data.thumbnails.length);

  // Generate new page
  const newHTML = generatePage(data);
  fs.writeFileSync(indexPath, newHTML, 'utf-8');
  console.log('[OK] ' + id + '/index.html updated');

  success++;
});

console.log('\n========================================');
console.log('Done! ' + success + '/' + ACTIVE_PRODUCTS.length + ' products updated.');
if (errors.length > 0) {
  console.log('Errors:');
  errors.forEach(function(e) { console.log('  - ' + e); });
}
