/* ============================================
   Checkout - Mercado Livre Style
   5 Steps: Carrinho → Dados → Endereço → Envio → Pagamento
   ============================================ */

(function() {
  'use strict';

  var currentStep = 1;
  var selectedFrete = 0; // in cents
  var paymentCode = null;
  var pollingInterval = null;
  var timerInterval = null;
  var pixTimerInterval = null;
  var countdownSeconds = 5 * 60 + 30; // 5 min 30 sec initial timer (overridden by timer_fix)
  var pixCountdownSeconds = 600; // 10 min PIX timer
  var cachedPixData = null; // For PIX idempotency

  /* ═══════════════════════════════════════
     INIT
     ═══════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function() {
    initTimer();
    initInputMasks();
    renderCartPage();

    // If cart is empty, redirect back
    if (Cart.getCount() === 0) {
      window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
      return;
    }

    // ── Fire InitiateCheckout on checkout page load ──
    if (typeof MLA !== 'undefined') {
      MLA.trackInitiateCheckout(Cart.getItems(), Cart.getSubtotal());
      MLA.trackCheckoutStep(1, 'carrinho');
    }

    // ═══ FASE 3E: Show trust signals if flag enabled ═══
    if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('trust_signals')) {
      var trustEl = document.getElementById('trustSignals');
      if (trustEl) trustEl.style.display = 'block';
    }
  });

  /* ═══════════════════════════════════════
     CART PAGE (Step 1 - dedicated)
     ═══════════════════════════════════════ */
  function renderCartPage() {
    var container = document.getElementById('cart-items');
    var totalEl = document.getElementById('cart-page-total');
    if (!container) return;

    var items = Cart.getItems();
    container.innerHTML = '';

    if (items.length === 0) {
      container.innerHTML =
        '<div class="cart-empty">' +
          '<svg width="48" height="48" viewBox="0 0 24 24" fill="#999"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM7.17 14.75l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A.996.996 0 0020.01 4H5.21l-.94-2H1v2h2l3.6 7.59-1.35 2.44C4.52 15.37 5.48 17 7 17h12v-2H7.42c-.14 0-.25-.11-.25-.25z"/></svg>' +
          '<p>Seu carrinho está vazio</p>' +
          '<a href="#" onclick="goToRecompensas(); return false;" class="cart-empty-btn">Ver produtos</a>' +
        '</div>';
      if (totalEl) totalEl.textContent = 'R$ 0,00';
      return;
    }

    items.forEach(function(item) {
      var div = document.createElement('div');
      div.className = 'cart-item';

      var oldPriceHtml = '';
      if (item.oldPrice && item.oldPrice > item.price) {
        oldPriceHtml = '<span class="cart-item-old-price">' + formatPrice(item.oldPrice) + '</span>';
      }

      div.innerHTML =
        '<img class="cart-item-img" src="' + escapeHtml(item.image || '') + '" alt="' + escapeHtml(item.name) + '">' +
        '<div class="cart-item-body">' +
          '<div class="cart-item-name">' + escapeHtml(item.name) + '</div>' +
          '<div class="cart-item-prices">' +
            oldPriceHtml +
            '<span class="cart-item-price">' + formatPrice(item.price) + '</span>' +
          '</div>' +
          '<div class="cart-item-qty">Quantidade: ' + (item.quantity || 1) + '</div>' +
          '<button class="cart-item-remove" data-id="' + escapeHtml(item.id) + '">Eliminar</button>' +
        '</div>';
      container.appendChild(div);
    });

    // Bind remove buttons
    container.querySelectorAll('.cart-item-remove').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        Cart.removeItem(this.getAttribute('data-id'));
        renderCartPage();
        if (Cart.getCount() === 0) {
          window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
        }
      });
    });

    // Update total
    if (totalEl) totalEl.textContent = formatPrice(Cart.getSubtotal());
  }

  window.goToRecompensas = function() {
    window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
  };

  /* ═══════════════════════════════════════
     STEP NAVIGATION
     ═══════════════════════════════════════ */
  window.goToStep = function(step) {
    // Validate current step before advancing
    if (step > currentStep) {
      if (!validateStep(currentStep)) return;
    }

    currentStep = step;

    // Update stepper indicators
    var steps = document.querySelectorAll('.ml-step');
    var lines = document.querySelectorAll('.ml-step-line');

    steps.forEach(function(el) {
      var s = parseInt(el.getAttribute('data-step'));
      el.classList.remove('active', 'completed');
      if (s === currentStep) el.classList.add('active');
      else if (s < currentStep) el.classList.add('completed');
    });

    // Update connecting lines
    lines.forEach(function(line, idx) {
      if (idx < currentStep - 1) {
        line.classList.add('completed');
      } else {
        line.classList.remove('completed');
      }
    });

    // Show/hide step panels
    document.querySelectorAll('.step-panel').forEach(function(el) {
      el.classList.remove('active');
    });
    var stepEl = document.getElementById('step-' + step);
    if (stepEl) {
      stepEl.classList.add('active');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Save customer data for UP page
    if (step >= 3) {
      localStorage.setItem('ml_customer_data', JSON.stringify({
        email: getValue('email'),
        name: getValue('nome'),
        document: getValue('cpf'),
        phone: getValue('telefone')
      }));
    }

    // ── Track checkout step changes ──
    if (typeof MLA !== 'undefined') {
      var stepNames = { 1: 'carrinho', 2: 'dados', 3: 'endereco', 4: 'envio', 5: 'pagamento' };
      MLA.trackCheckoutStep(step, stepNames[step] || 'step_' + step);
    }

    // Step 5: show REVIEW first (not PIX yet)
    if (step === 5) {
      renderReview();
      var reviewEl = document.getElementById('step-5-review');
      var pixEl = document.getElementById('step-5-pix');
      if (reviewEl) reviewEl.style.display = 'block';
      if (pixEl) pixEl.style.display = 'none';

      // ── Fire AddPaymentInfo when reaching payment step ──
      if (typeof MLA !== 'undefined') {
        MLA.trackAddPaymentInfo(Cart.getItems(), Cart.getSubtotal() + selectedFrete);
      }
    }
  };

  window.goBack = function() {
    if (currentStep > 1) {
      // If on PIX view within step 5, go back to review (not previous step)
      var pixEl = document.getElementById('step-5-pix');
      var reviewEl = document.getElementById('step-5-review');
      if (currentStep === 5 && pixEl && pixEl.style.display !== 'none') {
        pixEl.style.display = 'none';
        if (reviewEl) reviewEl.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      goToStep(currentStep - 1);
    }
  };

  /* ═══════════════════════════════════════
     CONFIRM AND PAY (Review → PIX transition)
     ═══════════════════════════════════════ */
  window.confirmAndPay = function() {
    var reviewEl = document.getElementById('step-5-review');
    var pixEl = document.getElementById('step-5-pix');

    // Hide review, show PIX view
    if (reviewEl) reviewEl.style.display = 'none';
    if (pixEl) pixEl.style.display = 'block';

    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Now generate PIX
    generatePix();
  };

  /* ═══════════════════════════════════════
     REVIEW PAGE (renders billing, address, items)
     ═══════════════════════════════════════ */
  function renderReview() {
    try {
      var items = Cart.getItems();
      var subtotal = Cart.getSubtotal();
      var total = subtotal + selectedFrete;

      // Format price as "R$ XX<sup>YY</sup>"
      function priceWithSup(cents) {
        var reais = Math.floor(cents / 100);
        var centavos = cents % 100;
        return 'R$ ' + reais.toLocaleString('pt-BR') + '<sup>' + (centavos < 10 ? '0' : '') + centavos + '</sup>';
      }

      // Pricing
      var prodEl = document.getElementById('review-produto');
      var freteEl = document.getElementById('review-frete');
      var subEl = document.getElementById('review-subtotal');
      var totalEl = document.getElementById('review-total');

      if (prodEl) prodEl.innerHTML = priceWithSup(subtotal);
      if (freteEl) freteEl.innerHTML = selectedFrete === 0 ? '<span style="color:#00a650;font-weight:600">Grátis</span>' : priceWithSup(selectedFrete);
      if (subEl) subEl.innerHTML = priceWithSup(total);
      if (totalEl) totalEl.innerHTML = priceWithSup(total);

      // Billing info
      var nameEl = document.getElementById('review-name');
      var cpfEl = document.getElementById('review-cpf');
      if (nameEl) nameEl.textContent = getValue('nome') || '—';
      if (cpfEl) cpfEl.textContent = 'CPF ' + (getValue('cpf') || '—');

      // Address
      var addrEl = document.getElementById('review-address');
      if (addrEl) {
        var rua = getValue('rua');
        var num = getValue('numero');
        var comp = getValue('complemento');
        var bairro = getValue('bairro');
        var cidade = getValue('cidade');
        var uf = getValue('uf');
        var parts = [];
        if (rua) parts.push(rua);
        if (num) parts.push(num);
        if (comp) parts.push(comp);
        var line2 = [];
        if (bairro) line2.push(bairro);
        if (cidade) line2.push(cidade);
        if (uf) line2.push(uf);
        addrEl.innerHTML = (parts.join(' ') || '—') + (line2.length ? '<br>' + line2.join(', ') : '');
      }

      // Shipping items
      var shipContainer = document.getElementById('review-shipping-items');
      if (shipContainer) {
        var deliveryText = getSelectedFreteDelivery();
        shipContainer.innerHTML = '';
        items.forEach(function(item) {
          var div = document.createElement('div');
          div.className = 'review-ship-item';
          div.innerHTML =
            '<img src="' + escapeHtml(item.image || '') + '" alt="">' +
            '<div class="review-ship-info">' +
              '<div class="review-ship-delivery">' + deliveryText + '</div>' +
              '<div class="review-ship-name">' + escapeHtml(item.name || 'Produto') + '</div>' +
              '<div class="review-ship-qty">Quantidade: ' + (item.quantity || 1) + '</div>' +
            '</div>';
          shipContainer.appendChild(div);
        });
      }
    } catch(e) {
      console.error('renderReview error:', e);
    }
  }

  function getSelectedFreteDelivery() {
    var radio = document.querySelector('input[name="frete"]:checked');
    if (!radio) return 'Chegará em até 21 dias úteis';
    switch(radio.value) {
      case '3266': return 'Chegará em até 3 dias úteis';
      case '5922': return 'Chegará em 12 a 24 horas';
      default: return 'Chegará em até 21 dias úteis';
    }
  }


  /* ═══════════════════════════════════════
     FORM VALIDATION
     ═══════════════════════════════════════ */
  function validateStep(step) {
    clearErrors();

    if (step === 1) {
      // Cart: just ensure cart isn't empty
      return Cart.getCount() > 0;
    }

    if (step === 2) {
      // FASE 3D: Minimal validation — require at least email OR nome
      if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('form_validation')) {
        var email = getValue('email');
        var nome = getValue('nome');
        if (!email && !nome) {
          showError('email', 'Informe pelo menos seu e-mail');
          return false;
        }
      }
      return true;
    }

    if (step === 3) {
      // FASE 3D: Basic CEP validation (8 digits)
      if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('form_validation')) {
        var cep = getValue('cep').replace(/\D/g, '');
        if (cep && cep.length !== 8) {
          showError('cep', 'CEP deve ter 8 dígitos');
          return false;
        }
      }
      return true;
    }

    return true;
  }

  function getValue(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  function showError(id, msg) {
    var el = document.getElementById(id);
    if (el) {
      el.classList.add('error');
      var errEl = el.parentNode.querySelector('.field-error');
      if (errEl) {
        errEl.textContent = msg;
        errEl.style.display = 'block';
      }
      el.focus();

      // ── Track form error ──
      if (typeof MLA !== 'undefined') {
        MLA.trackFormError(id, msg);
      }
    }
  }

  function clearErrors() {
    document.querySelectorAll('.error').forEach(function(el) {
      el.classList.remove('error');
    });
    document.querySelectorAll('.field-error').forEach(function(el) {
      el.style.display = 'none';
    });
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /* ═══════════════════════════════════════
     INPUT MASKS
     ═══════════════════════════════════════ */
  function initInputMasks() {
    var cpfEl = document.getElementById('cpf');
    if (cpfEl) {
      cpfEl.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        this.value = v;
      });
    }

    var telEl = document.getElementById('telefone');
    if (telEl) {
      telEl.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 6) v = v.replace(/(\d{2})(\d{5})(\d{1,4})/, '($1) $2-$3');
        else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,5})/, '($1) $2');
        this.value = v;
      });
    }

    var cepEl = document.getElementById('cep');
    if (cepEl) {
      cepEl.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '').slice(0, 8);
        if (v.length > 5) v = v.replace(/(\d{5})(\d{1,3})/, '$1-$2');
        this.value = v;
      });
      // Auto-search on 8 digits
      cepEl.addEventListener('input', function() {
        if (this.value.replace(/\D/g, '').length === 8) {
          buscarCEP();
        }
      });
    }
  }

  /* ═══════════════════════════════════════
     VIACEP
     ═══════════════════════════════════════ */
  function unlockAddressFields() {
    ['rua', 'bairro', 'cidade', 'uf'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el && el.hasAttribute('readonly')) {
        el.removeAttribute('readonly');
        el.placeholder = 'Digite aqui';
      }
    });
  }

  window.buscarCEP = function() {
    var cep = getValue('cep').replace(/\D/g, '');
    if (cep.length !== 8) return;

    var cepBtn = document.querySelector('.cep-btn');
    if (cepBtn) cepBtn.textContent = '...';

    // Use server-side proxy to avoid CORS issues with ViaCEP
    fetch('/api/cep.php?cep=' + cep)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.erro) {
          unlockAddressFields();
          return;
        }
        setField('rua', data.logradouro || '');
        setField('bairro', data.bairro || '');
        setField('cidade', data.localidade || '');
        setField('uf', data.uf || '');

        var numEl = document.getElementById('numero');
        if (numEl) numEl.focus();
      })
      .catch(function() {
        unlockAddressFields();
        showToast('CEP não encontrado. Digite o endereço manualmente.');
      })
      .finally(function() {
        if (cepBtn) cepBtn.textContent = 'Buscar';
      });
  };

  function setField(id, val) {
    var el = document.getElementById(id);
    if (el) {
      el.value = val;
      if (!val && el.hasAttribute('readonly')) {
        el.removeAttribute('readonly');
        el.placeholder = 'Digite aqui';
      }
    }
  }

  /* ═══════════════════════════════════════
     SHIPPING SELECTION
     ═══════════════════════════════════════ */
  window.selectFrete = function(value) {
    selectedFrete = parseInt(value);
    document.querySelectorAll('.ship-opt').forEach(function(el) {
      el.classList.remove('selected');
    });
    var radio = document.querySelector('input[name="frete"][value="' + value + '"]');
    if (radio) {
      radio.checked = true;
      radio.closest('.ship-opt').classList.add('selected');
    }
  };

  /* ═══════════════════════════════════════
     PIX GENERATION
     ═══════════════════════════════════════ */
  function generatePix() {
    var loading = document.getElementById('pix-loading');
    var content = document.getElementById('pix-content');
    var confirmed = document.getElementById('pix-confirmed');

    var items = Cart.getItems();
    var totalAmount = Cart.getSubtotal() + selectedFrete;

    // Safety: block payment if amount is invalid (minimum R$5,00 = 500 cents)
    if (totalAmount < 500) {
      if (loading) loading.style.display = 'none';
      alert('Erro: valor do pedido inválido (R$ ' + (totalAmount / 100).toFixed(2).replace('.', ',') + '). Volte e adicione produtos novamente.');
      window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
      return;
    }

    // ═══ FASE 3A: PIX Idempotency — reuse pending PIX from same session ═══
    if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('pix_idempotency')) {
      try {
        var cached = sessionStorage.getItem('ml_pending_pix');
        if (cached) {
          var pix = JSON.parse(cached);
          var ageMin = (Date.now() - pix.created_at) / 1000 / 60;
          if (pix.amount === totalAmount && ageMin < 25 && pix.pix_qrcode_text) {
            // Reuse existing PIX — skip API call
            showExistingPix(pix, totalAmount, items);
            return;
          }
        }
      } catch(e) {}
    }

    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';
    if (confirmed) confirmed.style.display = 'none';

    var utms = {};
    try { utms = JSON.parse(localStorage.getItem('ml_utms') || '{}'); } catch(e) {}
    var fbp = localStorage.getItem('ml_fbp') || null;
    var fbc = localStorage.getItem('ml_fbc') || null;
    var ttclid = localStorage.getItem('ml_ttclid') || null;

    var sessionId = (typeof MLA !== 'undefined') ? MLA.getSessionId() : '';

    var payload = {
      customer: {
        email: getValue('email'),
        name: getValue('nome'),
        document: getValue('cpf'),
        phone: getValue('telefone')
      },
      amount: totalAmount,
      items: items.map(function(item) {
        return {
          id: item.id,
          name: item.name,
          price: item.price * item.quantity,
          quantity: item.quantity
        };
      }),
      trackingParameters: (function() {
        var tp = {};
        for (var k in utms) { if (utms.hasOwnProperty(k)) tp[k] = utms[k]; }
        tp.fbp = fbp; tp.fbc = fbc; tp.ttclid = ttclid;
        return tp;
      })(),
      metadata: {
        frete: selectedFrete,
        frete_type: getSelectedFreteType(),
        cep: getValue('cep'),
        cidade: getValue('cidade'),
        uf: getValue('uf'),
        bairro: getValue('bairro'),
        session_id: sessionId,
        experiment_id: (window.__ML_EXPERIMENT && window.__ML_EXPERIMENT.id) || null,
        variant_id: (window.__ML_EXPERIMENT && window.__ML_EXPERIMENT.variant) || null
      }
    };

    fetch('/api/payment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success || !data.pix_qrcode_text) {
        throw new Error(data.error || 'Erro ao gerar PIX');
      }

      paymentCode = data.payment_code;

      // ═══ FASE 3A: Cache PIX data for idempotency ═══
      try {
        sessionStorage.setItem('ml_pending_pix', JSON.stringify({
          payment_code: data.payment_code,
          pix_qrcode_text: data.pix_qrcode_text,
          amount: totalAmount,
          created_at: Date.now()
        }));
      } catch(e) {}

      displayPixUI(data.pix_qrcode_text, totalAmount, items);
    })
    .catch(function(err) {
      if (loading) loading.innerHTML =
        '<p style="color:#f23d4f;font-size:14px;margin-bottom:12px;">Erro ao gerar PIX. Tente novamente.</p>' +
        '<button class="ml-btn-primary" onclick="generatePix()" style="max-width:260px;margin:0 auto;">' +
          'Tentar novamente' +
        '</button>';
      console.error('PIX Error:', err);
    });
  }

  // ═══ Show cached PIX (idempotency) ═══
  function showExistingPix(pixData, totalAmount, items) {
    paymentCode = pixData.payment_code;

    var loading = document.getElementById('pix-loading');
    if (loading) loading.style.display = 'none';

    displayPixUI(pixData.pix_qrcode_text, totalAmount, items);
  }

  // ═══ Shared PIX UI display logic ═══
  function displayPixUI(pixQrcodeText, totalAmount, items) {
    var loading = document.getElementById('pix-loading');
    var content = document.getElementById('pix-content');

    // ═══ FASE 3C: QR Code visibility toggle ═══
    var qrVisible = (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('qr_code_visible'));
    var qrSection = document.getElementById('pix-qr-section');
    var qrDetails = document.getElementById('pix-qr-details');
    if (qrSection) qrSection.style.display = qrVisible ? 'block' : 'none';
    if (qrDetails) qrDetails.style.display = qrVisible ? 'none' : 'block';

    // Generate QR Code
    var qrContainer = document.getElementById('qr-code');
    if (qrContainer && typeof qrcode !== 'undefined') {
      var qr = qrcode(0, 'M');
      qr.addData(pixQrcodeText);
      qr.make();
      qrContainer.innerHTML = qr.createImgTag(5, 12);
    }
    // Also fill fallback QR container if present
    var qrFallback = document.getElementById('qr-code-fallback');
    if (qrFallback && typeof qrcode !== 'undefined') {
      var qr2 = qrcode(0, 'M');
      qr2.addData(pixQrcodeText);
      qr2.make();
      qrFallback.innerHTML = qr2.createImgTag(5, 12);
    }

    // Set copy code
    var codeInput = document.getElementById('pix-code');
    if (codeInput) codeInput.value = pixQrcodeText;

    // Show content
    if (loading) loading.style.display = 'none';
    if (content) content.style.display = 'block';

    // Set PIX amount in hero
    var pixAmountEl = document.getElementById('pix-amount');
    if (pixAmountEl) pixAmountEl.textContent = formatPrice(totalAmount);

    // Start PIX countdown timer
    startPixCountdown();

    // Start polling for payment
    startPolling();

    // ── Fire GeneratePixCode event (FB custom + TT + internal) ──
    if (typeof MLA !== 'undefined') {
      MLA.trackGeneratePixCode(paymentCode, totalAmount, items);
    } else {
      if (typeof fbq === 'function') {
        fbq('track', 'InitiateCheckout', {
          value: totalAmount / 100,
          currency: 'BRL',
          num_items: Cart.getCount(),
          content_ids: items.map(function(i) { return i.id; }),
          content_type: 'product'
        });
      }
      if (typeof ttq !== 'undefined') {
        ttq.track('InitiateCheckout', {
          content_type: 'product',
          content_id: items.map(function(i) { return i.id; }).join(','),
          quantity: Cart.getCount(),
          value: totalAmount / 100,
          currency: 'BRL'
        });
      }
    }

    // ═══ FASE 1: Track page visibility changes (user switching to bank app) ═══
    if (!window._pixVisibilityTracked) {
      window._pixVisibilityTracked = true;
      document.addEventListener('visibilitychange', function() {
        if (typeof MLA !== 'undefined' && paymentCode) {
          MLA.track('pix_page_visibility', {
            payment_code: paymentCode,
            visible: !document.hidden,
            state: document.visibilityState
          });
        }
      });
    }
  }

  window.generatePix = generatePix;

  function getSelectedFreteType() {
    var radio = document.querySelector('input[name="frete"]:checked');
    if (!radio) return 'gratis';
    switch(radio.value) {
      case '3266': return 'mercado_envio';
      case '5922': return 'azul_cargo';
      default: return 'gratis';
    }
  }

  /* ═══════════════════════════════════════
     PIX COUNTDOWN
     ═══════════════════════════════════════ */
  function startPixCountdown() {
    pixCountdownSeconds = 600;
    if (pixTimerInterval) clearInterval(pixTimerInterval);

    updatePixCountdown();
    pixTimerInterval = setInterval(function() {
      pixCountdownSeconds--;
      if (pixCountdownSeconds <= 0) {
        pixCountdownSeconds = 0;
        clearInterval(pixTimerInterval);
      }
      updatePixCountdown();
    }, 1000);
  }

  function updatePixCountdown() {
    var el = document.getElementById('pix-countdown');
    var box = document.getElementById('pix-timer-box');
    if (!el) return;

    var m = Math.floor(pixCountdownSeconds / 60);
    var s = pixCountdownSeconds % 60;
    el.textContent = pad(m) + ':' + pad(s);

    if (box) {
      box.classList.remove('warning', 'urgent');
      if (pixCountdownSeconds <= 120) {
        box.classList.add('urgent');
      } else if (pixCountdownSeconds <= 300) {
        box.classList.add('warning');
      }
    }
  }

  /* ═══════════════════════════════════════
     COPY PIX CODE
     ═══════════════════════════════════════ */
  window.copyPixCode = function() {
    var input = document.getElementById('pix-code');
    var btn = document.getElementById('copy-btn');
    if (!input) return;

    var copySuccess = function() {
      if (btn) {
        btn.classList.add('copied');
        btn.textContent = '\u2713 Código copiado!';

        setTimeout(function() {
          btn.classList.remove('copied');
          btn.textContent = 'Copiar código';
        }, 3000);
      }
      showToast('Código PIX copiado! Cole no app do seu banco.');

      // ── Fire CopyPixCode event ──
      if (typeof MLA !== 'undefined') {
        MLA.trackCopyPixCode(paymentCode);
      }
    };

    if (navigator.clipboard) {
      navigator.clipboard.writeText(input.value).then(copySuccess);
    } else {
      input.select();
      document.execCommand('copy');
      copySuccess();
    }
  };

  /* ═══════════════════════════════════════
     PAYMENT POLLING
     ═══════════════════════════════════════ */
  function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    var maxAttempts = 120;
    var attempts = 0;

    pollingInterval = setInterval(function() {
      attempts++;
      if (attempts > maxAttempts) {
        clearInterval(pollingInterval);
        return;
      }
      checkPaymentStatus();
      updatePollingMessage(attempts);
    }, 5000);
  }

  function updatePollingMessage(attempts) {
    var statusEl = document.getElementById('payment-status');
    if (!statusEl) return;
    var span = statusEl.querySelector('span');
    if (!span) return;

    var messages = [
      'Aguardando pagamento...',
      'Aguardando confirmação do banco...',
      'Verificando pagamento...',
      'Ainda aguardando...',
      'O banco pode levar alguns segundos...'
    ];

    var idx = Math.floor(attempts / 3) % messages.length;
    span.textContent = messages[idx];
  }

  window.checkPayment = function() {
    var btn = document.getElementById('check-btn');
    if (btn) {
      btn.innerHTML =
        '<div class="ml-spinner" style="width:16px;height:16px;border-width:2px;margin:0;display:inline-block;vertical-align:middle;"></div>' +
        ' Verificando...';
    }

    var statusEl = document.getElementById('payment-status');
    if (statusEl) statusEl.classList.add('checking');

    checkPaymentStatus(true);
  };

  function checkPaymentStatus(manual) {
    if (!paymentCode) return;

    fetch('/api/check-payment.php?code=' + encodeURIComponent(paymentCode))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.status === 'paid') {
          onPaymentConfirmed();
        } else if (data.status === 'failed') {
          // Payment failed at gateway level - show error and allow retry
          if (pollingInterval) clearInterval(pollingInterval);
          var statusEl = document.getElementById('payment-status');
          if (statusEl) {
            statusEl.classList.remove('checking');
            statusEl.innerHTML = '<div style="color:#ef4444;text-align:center;padding:1rem 0">' +
              '<div style="font-size:1.3rem;margin-bottom:0.5rem">⚠️ Erro no pagamento</div>' +
              '<div style="font-size:0.9rem;color:#fca5a5;margin-bottom:1rem">O banco retornou um erro ao processar o PIX. Isso pode acontecer por instabilidade momentânea.</div>' +
              '<button onclick="location.reload()" style="background:#3b82f6;color:#fff;border:none;padding:0.7rem 2rem;border-radius:8px;font-size:0.95rem;cursor:pointer;font-weight:600">Tentar novamente</button>' +
              '</div>';
          }
          var btn = document.getElementById('check-btn');
          if (btn) btn.style.display = 'none';
        } else if (manual) {
          var btn = document.getElementById('check-btn');
          if (btn) {
            btn.textContent = 'Ainda não confirmado. Aguarde...';
          }
          setTimeout(function() {
            if (btn) {
              btn.textContent = 'Já paguei - Verificar';
            }
            var statusEl = document.getElementById('payment-status');
            if (statusEl) statusEl.classList.remove('checking');
          }, 3000);
        }
      })
      .catch(function() {
        if (manual) {
          var btn = document.getElementById('check-btn');
          if (btn) {
            btn.textContent = 'Já paguei - Verificar';
          }
        }
      });
  }

  function onPaymentConfirmed() {
    if (pollingInterval) clearInterval(pollingInterval);
    if (pixTimerInterval) clearInterval(pixTimerInterval);

    var content = document.getElementById('pix-content');
    var confirmed = document.getElementById('pix-confirmed');

    if (content) content.style.display = 'none';
    if (confirmed) confirmed.style.display = 'block';

    var statusEl = document.getElementById('payment-status');
    if (statusEl) {
      statusEl.classList.remove('checking');
      statusEl.classList.add('confirmed');
    }

    var cartItems = Cart.getItems();
    var purchaseTotal = Cart.getSubtotal() + selectedFrete;

    // ── Fire Purchase event via MLA (FB + TT + internal, with dedup) ──
    if (typeof MLA !== 'undefined') {
      var purchaseEventId = MLA.trackPurchase(paymentCode, purchaseTotal, cartItems);
      try { localStorage.setItem('ml_purchase_event_id', purchaseEventId); } catch(e) {}
    } else {
      // Fallback without MLA
      var purchaseEventId = 'pur_' + paymentCode + '_' + Date.now();
      try { localStorage.setItem('ml_purchase_event_id', purchaseEventId); } catch(e) {}
      var purchaseValue = purchaseTotal / 100;
      if (typeof fbq === 'function') {
        fbq('track', 'Purchase', {
          value: purchaseValue,
          currency: 'BRL',
          content_ids: cartItems.map(function(i) { return i.id; }),
          content_type: 'product',
          order_id: paymentCode
        }, { eventID: purchaseEventId });
      }
      if (typeof ttq !== 'undefined') {
        ttq.track('CompletePayment', {
          content_type: 'product',
          content_id: cartItems.map(function(i) { return i.id; }).join(','),
          quantity: cartItems.reduce(function(sum, i) { return sum + i.quantity; }, 0),
          value: purchaseValue,
          currency: 'BRL'
        });
      }
    }

    // Clear cached PIX data
    try { sessionStorage.removeItem('ml_pending_pix'); } catch(e) {}

    // Clear cart
    Cart.clear();

    // Redirect to UP page
    setTimeout(function() {
      window.location.href = resolveUrl('/up/index.html') + getUTMQueryString();
    }, 3000);
  }

  /* ═══════════════════════════════════════
     COUNTDOWN TIMER (STICKY BAR)
     ═══════════════════════════════════════ */
  function initTimer() {
    // FASE 3B: Timer fix — longer duration, hide when expired instead of showing 00:00:00
    if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('timer_fix')) {
      countdownSeconds = 15 * 60; // 15 minutes
      var saved = sessionStorage.getItem('ml_timer_v2');
      if (saved) {
        var elapsed = Math.floor((Date.now() - parseInt(saved)) / 1000);
        countdownSeconds = Math.max(0, countdownSeconds - elapsed);
      } else {
        sessionStorage.setItem('ml_timer_v2', Date.now().toString());
      }
    } else {
      // Original behavior
      var saved = sessionStorage.getItem('ml_timer');
      if (saved) {
        var elapsed = Math.floor((Date.now() - parseInt(saved)) / 1000);
        countdownSeconds = Math.max(0, countdownSeconds - elapsed);
      } else {
        sessionStorage.setItem('ml_timer', Date.now().toString());
      }
    }

    updateTimerDisplay();
    timerInterval = setInterval(function() {
      countdownSeconds--;
      if (countdownSeconds <= 0) {
        countdownSeconds = 0;
        clearInterval(timerInterval);
        // FASE 3B: Hide timer instead of showing 00:00:00
        if (typeof MLFlags !== 'undefined' && MLFlags.isEnabled('timer_fix')) {
          var stickyTimer = document.getElementById('sticky-timer');
          if (stickyTimer) stickyTimer.style.display = 'none';
          return;
        }
      }
      updateTimerDisplay();
    }, 1000);
  }

  function updateTimerDisplay() {
    var h = Math.floor(countdownSeconds / 3600);
    var m = Math.floor((countdownSeconds % 3600) / 60);
    var s = countdownSeconds % 60;

    var hEl = document.getElementById('timer-hours');
    var mEl = document.getElementById('timer-mins');
    var sEl = document.getElementById('timer-secs');

    if (hEl) hEl.textContent = pad(h);
    if (mEl) mEl.textContent = pad(m);
    if (sEl) sEl.textContent = pad(s);

    var stickyTimer = document.getElementById('sticky-timer');
    if (stickyTimer) {
      if (countdownSeconds <= 120) {
        stickyTimer.classList.add('urgent');
      } else {
        stickyTimer.classList.remove('urgent');
      }
    }
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  /* ═══════════════════════════════════════
     TOAST
     ═══════════════════════════════════════ */
  function showToast(msg) {
    var toast = document.getElementById('toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'toast';
      toast.className = 'toast';
      document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 3000);
  }

  /* ═══════════════════════════════════════
     HELPERS
     ═══════════════════════════════════════ */
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

})();
