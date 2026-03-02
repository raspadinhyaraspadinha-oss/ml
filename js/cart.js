/* ============================================
   Cart Management System
   Shared across all pages
   ============================================ */

var Cart = {
  KEY: 'ml_cart',

  getItems: function() {
    try {
      return JSON.parse(localStorage.getItem(this.KEY) || '[]');
    } catch (e) {
      return [];
    }
  },

  addItem: function(item) {
    var items = this.getItems();
    for (var i = 0; i < items.length; i++) {
      if (items[i].id === item.id) {
        // Item already in cart — don't add again
        return items;
      }
    }
    items.push({
      id: item.id,
      name: item.name,
      price: item.price,
      oldPrice: item.oldPrice,
      image: item.image,
      quantity: 1
    });
    localStorage.setItem(this.KEY, JSON.stringify(items));
    return items;
  },

  removeItem: function(id) {
    var items = this.getItems();
    var filtered = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].id !== id) filtered.push(items[i]);
    }
    localStorage.setItem(this.KEY, JSON.stringify(filtered));
    return filtered;
  },

  updateQuantity: function(id, qty) {
    var items = this.getItems();
    for (var i = 0; i < items.length; i++) {
      if (items[i].id === id) {
        items[i].quantity = Math.max(1, qty);
        break;
      }
    }
    localStorage.setItem(this.KEY, JSON.stringify(items));
    return items;
  },

  getSubtotal: function() {
    var items = this.getItems();
    var sum = 0;
    for (var i = 0; i < items.length; i++) {
      sum += items[i].price * items[i].quantity;
    }
    return sum;
  },

  getCount: function() {
    var items = this.getItems();
    var count = 0;
    for (var i = 0; i < items.length; i++) {
      count += items[i].quantity;
    }
    return count;
  },

  clear: function() {
    localStorage.removeItem(this.KEY);
  }
};

/* ============================================
   UTM Tracking - Capture & Persist
   ============================================ */

(function captureUTMs() {
  var params = new URLSearchParams(window.location.search);
  var fields = ['src', 'sck', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];
  var utms = {};
  for (var i = 0; i < fields.length; i++) {
    var v = params.get(fields[i]);
    if (v) utms[fields[i]] = v;
  }
  if (Object.keys(utms).length > 0) {
    localStorage.setItem('ml_utms', JSON.stringify(utms));
  }
  // fbp from Facebook Pixel cookie
  var fbpMatch = document.cookie.match(/(?:^|;\s*)_fbp=([^;]*)/);
  var fbp = fbpMatch ? fbpMatch[1] : null;
  if (fbp) localStorage.setItem('ml_fbp', fbp);
  // fbc from fbclid
  var fbclid = params.get('fbclid');
  if (fbclid) {
    localStorage.setItem('ml_fbc', 'fb.1.' + Date.now() + '.' + fbclid);
  }
})();

/* ============================================
   Helper: Parse Brazilian price string to cents
   "R$ 2.312,90" → 231290
   "R$ 187,58" → 18758
   ============================================ */

function parsePriceToCents(str) {
  if (!str) return 0;
  // Remove everything except digits, dots, commas
  var cleaned = str.replace(/[^\d,\.]/g, '');
  // Remove ALL dots (thousand separators in BR format)
  cleaned = cleaned.replace(/\./g, '');
  // Replace comma with dot (decimal separator)
  cleaned = cleaned.replace(',', '.');
  var val = parseFloat(cleaned);
  return isNaN(val) ? 0 : Math.round(val * 100);
}

/* ============================================
   Helper: Format cents to Brazilian currency
   18758 → "R$ 187,58"
   ============================================ */

function formatPrice(cents) {
  return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',');
}

/* ============================================
   Helper: Get UTM query string for navigation
   ============================================ */

function getUTMQueryString() {
  var utms = {};
  try { utms = JSON.parse(localStorage.getItem('ml_utms') || '{}'); } catch(e) {}
  var params = new URLSearchParams(window.location.search);
  var keys = Object.keys(utms);
  for (var i = 0; i < keys.length; i++) {
    if (!params.has(keys[i])) params.set(keys[i], utms[keys[i]]);
  }
  var qs = params.toString();
  return qs ? '?' + qs : '';
}

/* ============================================
   Helper: Resolve path for both file:// and http://
   From product page → checkout
   ============================================ */

function resolveUrl(absolutePath) {
  // On file:// protocol, absolute paths don't work
  // Convert to relative based on current location
  if (window.location.protocol === 'file:') {
    // Detect depth: count how many folders deep we are from site root
    // Product pages are at /produtos/{name}/index.html → depth 2
    // Recompensas is at /recompensas/index.html → depth 1
    // Checkout is at /checkout/index.html → depth 1
    var path = window.location.pathname;
    if (path.indexOf('/produtos/') !== -1) {
      // We're 2 levels deep: /produtos/{name}/
      return '../..' + absolutePath;
    } else if (path.indexOf('/recompensas/') !== -1 ||
               path.indexOf('/checkout/') !== -1 ||
               path.indexOf('/roleta/') !== -1 ||
               path.indexOf('/up/') !== -1 ||
               path.indexOf('/vsl/') !== -1 ||
               path.indexOf('/questionario/') !== -1 ||
               path.indexOf('/prevsl/') !== -1) {
      // We're 1 level deep
      return '..' + absolutePath;
    }
    // Fallback: same level
    return '.' + absolutePath;
  }
  // On HTTP, absolute paths work fine
  return absolutePath;
}

/* ============================================
   Auto-detect Product Page & Override Buy Button
   ============================================ */

document.addEventListener('DOMContentLoaded', function() {
  var buyBtn = document.querySelector('.pergunta-botao');
  if (buyBtn) {
    // CRITICAL: Remove the inline onclick handler properly
    // removeAttribute only removes the HTML attribute, not the compiled handler
    buyBtn.onclick = null;
    buyBtn.removeAttribute('onclick');

    buyBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      // Extract product ID from URL path
      var pathParts = window.location.pathname.split('/');
      var filtered = [];
      for (var i = 0; i < pathParts.length; i++) {
        if (pathParts[i] && pathParts[i] !== '') filtered.push(pathParts[i]);
      }
      // If last part is index.html or .html file, use second-to-last
      var lastPart = filtered[filtered.length - 1] || '';
      var productId;
      if (lastPart.indexOf('.html') !== -1 || lastPart === 'index.html') {
        productId = filtered[filtered.length - 2] || 'produto';
      } else {
        productId = lastPart || 'produto';
      }

      // Try multiple selectors for compatibility with old and new product page layouts
      var nameEl = document.querySelector('.product-title') || document.querySelector('.title');
      var priceEl = document.querySelector('.new-price') || document.querySelector('.new-price2');
      var oldPriceEl = document.querySelector('.old-price') || document.querySelector('.old-price2');
      var imgEl = document.querySelector('.main-image');

      var product = {
        id: productId,
        name: nameEl ? nameEl.textContent.trim() : 'Produto',
        price: parsePriceToCents(priceEl ? priceEl.textContent : '0'),
        oldPrice: parsePriceToCents(oldPriceEl ? oldPriceEl.textContent : '0'),
        image: imgEl ? imgEl.src : '',
        quantity: 1
      };

      // Safety: never add a product with price 0 to cart
      if (product.price <= 0) {
        console.error('Cart: product price is 0, selectors may be wrong. Name:', product.name, 'Price element:', priceEl);
        alert('Erro ao adicionar produto. Por favor, tente novamente.');
        return;
      }

      Cart.addItem(product);

      // Fire Facebook AddToCart event
      if (typeof fbq === 'function') {
        fbq('track', 'AddToCart', {
          content_name: product.name,
          content_ids: [product.id],
          content_type: 'product',
          value: product.price / 100,
          currency: 'BRL'
        });
      }

      // Fire TikTok AddToCart event
      if (typeof ttq !== 'undefined') {
        ttq.track('AddToCart', {
          content_id: product.id,
          content_name: product.name,
          content_type: 'product',
          value: product.price / 100,
          currency: 'BRL'
        });
      }

      window.location.href = resolveUrl('/checkout/index.html') + getUTMQueryString();
    });

  }
});

/* ============================================
   Fix "Voltar" button on product pages
   Uses capture-phase delegation to override
   inline onclick handlers reliably.
   Handles BOTH modern (.nav-back) and legacy
   (.menu-icon with /recompensas onclick) pages.
   ============================================ */

document.addEventListener('click', function(e) {
  if (!document.querySelector('.pergunta-botao')) return; // only on product pages
  if (!e.target.closest) return; // old browser guard

  var hit = e.target.closest('.nav-back')
         || e.target.closest('[onclick*="/recompensas"]');

  if (hit) {
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
  }
}, true);
