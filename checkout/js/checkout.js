/* ============================================
   Checkout Page Logic - High Conversion Version
   Steps, Cart, ViaCEP, Mangofy, Tracking
   PIX Payment Focus + Conversion Psychology
   ============================================ */

(function() {
  'use strict';

  var currentStep = 1;
  var selectedFrete = 0; // in cents
  var paymentCode = null;
  var pollingInterval = null;
  var timerInterval = null;
  var pixTimerInterval = null;
  var countdownSeconds = 5 * 60 + 30; // 5 min 30 sec initial timer
  var pixCountdownSeconds = 600; // 10 min PIX timer

  /* ---- INIT ---- */
  document.addEventListener('DOMContentLoaded', function() {
    renderCart();
    initTimer();
    initSocialProof();
    initInputMasks();

    // If cart is empty, redirect back
    if (Cart.getCount() === 0) {
      window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
      return;
    }

    // Expand cart by default
    var cartItems = document.getElementById('cart-items');
    var chevron = document.querySelector('.cart-chevron');
    if (cartItems) cartItems.classList.add('open');
    if (chevron) chevron.classList.add('open');

    // Calculate and show savings
    updateSavings();
  });

  /* ---- SAVINGS CALCULATION ---- */
  function updateSavings() {
    var items = Cart.getItems();
    var totalSavings = 0;
    items.forEach(function(item) {
      if (item.oldPrice && item.oldPrice > item.price) {
        totalSavings += (item.oldPrice - item.price) * item.quantity;
      }
    });

    if (totalSavings > 0) {
      var savingsRow = document.getElementById('savings-row');
      var savingsAmount = document.getElementById('savings-amount');
      if (savingsRow) savingsRow.style.display = 'flex';
      if (savingsAmount) savingsAmount.textContent = '-' + formatPrice(totalSavings);

      var osmSavingsRow = document.getElementById('osm-savings-row');
      var osmSavings = document.getElementById('osm-savings');
      if (osmSavingsRow) osmSavingsRow.style.display = 'flex';
      if (osmSavings) osmSavings.textContent = '-' + formatPrice(totalSavings);
    }
  }

  /* ---- CART RENDERING ---- */
  window.renderCart = function() {
    var items = Cart.getItems();
    var container = document.getElementById('cart-items');
    var countEl = document.getElementById('cart-count');
    var subtotalEl = document.getElementById('subtotal');
    var freteEl = document.getElementById('frete-display');
    var totalEl = document.getElementById('total');

    if (countEl) countEl.textContent = Cart.getCount();

    if (container) {
      container.innerHTML = '';
      items.forEach(function(item) {
        var div = document.createElement('div');
        div.className = 'cart-item';
        div.innerHTML =
          '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">' +
          '<div class="cart-item-info">' +
            '<div class="cart-item-name">' + escapeHtml(item.name) + '</div>' +
            '<div class="cart-item-qty">' + item.quantity + ' un. &middot; ' + formatPrice(item.price * item.quantity) + '</div>' +
          '</div>' +
          '<button class="cart-item-remove" data-id="' + escapeHtml(item.id) + '" title="Remover">&times;</button>';
        container.appendChild(div);
      });

      // Bind remove buttons
      container.querySelectorAll('.cart-item-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
          Cart.removeItem(this.getAttribute('data-id'));
          renderCart();
          updateSavings();
          if (Cart.getCount() === 0) {
            window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
          }
        });
      });
    }

    var subtotal = Cart.getSubtotal();
    if (subtotalEl) subtotalEl.textContent = formatPrice(subtotal);
    if (freteEl) freteEl.textContent = selectedFrete === 0 ? 'Grátis' : formatPrice(selectedFrete);
    if (totalEl) totalEl.textContent = formatPrice(subtotal + selectedFrete);
  };

  /* ---- ORDER SUMMARY MINI (Step 3) ---- */
  function renderOrderSummary() {
    try {
      var items = Cart.getItems();
      var osmItems = document.getElementById('osm-items');
      var osmCount = document.getElementById('osm-count');
      var osmSubtotal = document.getElementById('osm-subtotal');
      var osmFrete = document.getElementById('osm-frete');
      var osmTotal = document.getElementById('osm-total');

      var count = Cart.getCount();
      if (osmCount) osmCount.textContent = count + (count === 1 ? ' item' : ' itens');

      if (osmItems) {
        osmItems.innerHTML = '';
        items.forEach(function(item) {
          var div = document.createElement('div');
          div.className = 'osm-item';
          div.innerHTML =
            '<img src="' + escapeHtml(item.image || '') + '" alt="">' +
            '<span class="osm-item-name">' + escapeHtml(item.name || 'Produto') + '</span>' +
            '<span class="osm-item-price">' + formatPrice((item.price || 0) * (item.quantity || 1)) + '</span>';
          osmItems.appendChild(div);
        });
      }

      var subtotal = Cart.getSubtotal();
      if (osmSubtotal) osmSubtotal.textContent = formatPrice(subtotal);
      if (osmFrete) osmFrete.textContent = selectedFrete === 0 ? 'Grátis' : formatPrice(selectedFrete);
      if (osmTotal) osmTotal.textContent = formatPrice(subtotal + selectedFrete);

      updateSavings();
    } catch(e) {
      console.error('renderOrderSummary error:', e);
    }
  }
  // Expose globally for failsafe calls
  window.renderOrderSummary = renderOrderSummary;

  /* ---- CART TOGGLE ---- */
  window.toggleCart = function() {
    var items = document.getElementById('cart-items');
    var chevron = document.querySelector('.cart-chevron');
    if (items) {
      items.classList.toggle('open');
    }
    if (chevron) {
      chevron.classList.toggle('open');
    }
  };

  /* ---- GO TO RECOMPENSAS ---- */
  window.goToRecompensas = function() {
    window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
  };

  /* ---- STEP NAVIGATION ---- */
  window.goToStep = function(step) {
    // Validate current step before advancing
    if (step > currentStep) {
      if (!validateStep(currentStep)) return;
    }

    currentStep = step;

    // Update step indicators
    document.querySelectorAll('.step').forEach(function(el) {
      var s = parseInt(el.getAttribute('data-step'));
      el.classList.remove('active', 'completed');
      if (s === currentStep) el.classList.add('active');
      else if (s < currentStep) el.classList.add('completed');
    });

    // Update progress bar
    updateProgressBar(step);

    // Show/hide step content
    document.querySelectorAll('.step-content').forEach(function(el) {
      el.style.display = 'none';
    });
    var stepEl = document.getElementById('step-' + step);
    if (stepEl) {
      stepEl.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Save customer data after step 1 for UP page to use later
    if (step === 2 || step === 3) {
      localStorage.setItem('ml_customer_data', JSON.stringify({
        email: getValue('email'),
        name: getValue('nome'),
        document: getValue('cpf'),
        phone: getValue('telefone')
      }));
    }

    // If step 3, render order summary and generate PIX
    if (step === 3) {
      renderOrderSummary();
      // Failsafe: render again after a short delay in case of timing issues
      setTimeout(renderOrderSummary, 150);
      generatePix();
    }
  };

  /* ---- PROGRESS BAR (removed from HTML, kept as no-op for safety) ---- */
  function updateProgressBar(step) {
    // Progress bar HTML was removed; step indicators handle visual feedback
  }

  /* ---- BACK STEP ---- */
  window.goBack = function() {
    if (currentStep > 1) {
      goToStep(currentStep - 1);
    }
  };

  /* ---- FORM VALIDATION ---- */
  function validateStep(step) {
    clearErrors();

    if (step === 1) {
      var email = getValue('email');
      var nome = getValue('nome');
      var cpf = getValue('cpf');
      var telefone = getValue('telefone');
      var valid = true;

      if (!email || !isValidEmail(email)) {
        showError('email', 'Informe um e-mail válido');
        valid = false;
      }
      if (!nome || nome.split(' ').length < 2) {
        showError('nome', 'Informe nome e sobrenome');
        valid = false;
      }
      if (!cpf || cpf.replace(/\D/g, '').length !== 11) {
        showError('cpf', 'Informe um CPF válido');
        valid = false;
      }
      if (!telefone || telefone.replace(/\D/g, '').length < 10) {
        showError('telefone', 'Informe um telefone válido');
        valid = false;
      }
      return valid;
    }

    if (step === 2) {
      /* Sem bloqueio rigoroso — aceita qualquer dado para não perder leads */
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
      var errEl = el.parentNode.querySelector('.error-msg');
      if (errEl) {
        errEl.textContent = msg;
        errEl.style.display = 'block';
      }
      // Focus on first error field
      el.focus();
    }
  }

  function clearErrors() {
    document.querySelectorAll('.error').forEach(function(el) {
      el.classList.remove('error');
    });
    document.querySelectorAll('.error-msg').forEach(function(el) {
      el.style.display = 'none';
    });
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /* ---- INPUT MASKS ---- */
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

  /* ---- VIACEP ---- */
  function unlockAddressFields() {
    ['rua', 'bairro', 'cidade', 'uf'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el && el.hasAttribute('readonly')) {
        el.removeAttribute('readonly');
        el.placeholder = 'Digite aqui';
      }
    });
    // Show shipping even on error so user can proceed
    var shippingCard = document.getElementById('shipping-card');
    if (shippingCard) shippingCard.style.display = '';
  }

  window.buscarCEP = function() {
    var cep = getValue('cep').replace(/\D/g, '');
    if (cep.length !== 8) return; /* silencioso — sem erro vermelho */

    var cepBtn = document.querySelector('.cep-btn');
    if (cepBtn) cepBtn.textContent = '...';

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.erro) {
          /* CEP não encontrado — libera campos para digitação manual */
          unlockAddressFields();
          return;
        }
        setField('rua', data.logradouro || '');
        setField('bairro', data.bairro || '');
        setField('cidade', data.localidade || '');
        setField('uf', data.uf || '');

        // Show shipping options after successful CEP lookup
        var shippingCard = document.getElementById('shipping-card');
        if (shippingCard) shippingCard.style.display = '';

        // Focus on número field
        var numEl = document.getElementById('numero');
        if (numEl) numEl.focus();
      })
      .catch(function() {
        /* Erro de rede — libera campos para digitação manual, sem vermelho */
        unlockAddressFields();
      })
      .finally(function() {
        if (cepBtn) cepBtn.textContent = 'Buscar';
      });
  };

  function setField(id, val) {
    var el = document.getElementById(id);
    if (el) {
      el.value = val;
      // If ViaCEP returned empty for this field, make it editable
      if (!val && el.hasAttribute('readonly')) {
        el.removeAttribute('readonly');
        el.placeholder = 'Digite aqui';
      }
    }
  }

  /* ---- SHIPPING SELECTION ---- */
  window.selectFrete = function(value) {
    selectedFrete = parseInt(value);
    document.querySelectorAll('.shipping-option').forEach(function(el) {
      el.classList.remove('selected');
    });
    var radio = document.querySelector('input[name="frete"][value="' + value + '"]');
    if (radio) {
      radio.checked = true;
      radio.closest('.shipping-option').classList.add('selected');
    }
    renderCart();
  };

  /* ---- PIX GENERATION ---- */
  function generatePix() {
    var loading = document.getElementById('pix-loading');
    var content = document.getElementById('pix-content');
    var confirmed = document.getElementById('pix-confirmed');

    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';
    if (confirmed) confirmed.style.display = 'none';

    var items = Cart.getItems();
    var totalAmount = Cart.getSubtotal() + selectedFrete;

    // Safety: block payment if amount is invalid (minimum R$5,00 = 500 cents)
    if (totalAmount < 500) {
      if (loading) loading.style.display = 'none';
      alert('Erro: valor do pedido inválido (R$ ' + (totalAmount / 100).toFixed(2).replace('.', ',') + '). Por favor, volte e adicione o produto novamente.');
      console.error('generatePix blocked: totalAmount=' + totalAmount + ' (below 500 cents minimum)');
      window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
      return;
    }

    var utms = {};
    try { utms = JSON.parse(localStorage.getItem('ml_utms') || '{}'); } catch(e) {}
    var fbp = localStorage.getItem('ml_fbp') || null;
    var fbc = localStorage.getItem('ml_fbc') || null;

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
      trackingParameters: Object.assign({}, utms, { fbp: fbp, fbc: fbc }),
      metadata: {
        frete: selectedFrete,
        frete_type: getSelectedFreteType()
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

      // Generate QR Code
      var qrContainer = document.getElementById('qr-code');
      if (qrContainer && typeof qrcode !== 'undefined') {
        var qr = qrcode(0, 'M');
        qr.addData(data.pix_qrcode_text);
        qr.make();
        qrContainer.innerHTML = qr.createImgTag(6, 16);
      }

      // Set copy code
      var codeInput = document.getElementById('pix-code');
      if (codeInput) codeInput.value = data.pix_qrcode_text;

      // Show content
      if (loading) loading.style.display = 'none';
      if (content) content.style.display = 'block';

      // Re-render order summary with confirmed cart data (failsafe)
      renderOrderSummary();

      // Start PIX countdown timer
      startPixCountdown();

      // Start polling for payment
      startPolling();

      // Event ID for dedup between client-side pixel and server-side CAPI
      var icEventId = 'ic_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);

      // Fire FB InitiateCheckout
      if (typeof fbq === 'function') {
        fbq('track', 'InitiateCheckout', {
          value: totalAmount / 100,
          currency: 'BRL',
          num_items: Cart.getCount(),
          content_ids: items.map(function(i) { return i.id; }),
          content_type: 'product'
        }, { eventID: icEventId });
      }

      // Fire TikTok InitiateCheckout (PIX generated)
      if (typeof ttq !== 'undefined') {
        ttq.track('InitiateCheckout', {
          content_type: 'product',
          content_id: items.map(function(i) { return i.id; }).join(','),
          quantity: Cart.getCount(),
          value: totalAmount / 100,
          currency: 'BRL'
        });
      }
    })
    .catch(function(err) {
      if (loading) loading.innerHTML =
        '<p style="color:#f23d4f;font-size:14px;margin-bottom:12px;">Erro ao gerar PIX. Tente novamente.</p>' +
        '<button class="cta-btn" onclick="generatePix()" style="margin:0 auto;max-width:280px;">' +
          '<span>Tentar novamente</span>' +
        '</button>';
      console.error('PIX Error:', err);
    });
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

  /* ---- PIX COUNTDOWN (with color escalation) ---- */
  function startPixCountdown() {
    pixCountdownSeconds = 600; // Reset to 10 min
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

    // Color escalation
    if (box) {
      box.classList.remove('warning', 'urgent');
      if (pixCountdownSeconds <= 120) {
        box.classList.add('urgent'); // Red pulsing < 2 min
      } else if (pixCountdownSeconds <= 300) {
        box.classList.add('warning'); // Yellow < 5 min
      }
    }
  }

  /* ---- COPY PIX CODE ---- */
  window.copyPixCode = function() {
    var input = document.getElementById('pix-code');
    var btn = document.getElementById('copy-btn');
    if (!input) return;

    var copySuccess = function() {
      // Visual feedback - green button with checkmark
      if (btn) {
        btn.classList.add('copied');
        btn.innerHTML =
          '<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' +
          '<span>CÓDIGO COPIADO!</span>';

        // Reset after 3 seconds
        setTimeout(function() {
          btn.classList.remove('copied');
          btn.innerHTML =
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>' +
            '<span>COPIAR CÓDIGO PIX</span>';
        }, 3000);
      }
      showToast('Código PIX copiado! Cole no app do seu banco.');
    };

    if (navigator.clipboard) {
      navigator.clipboard.writeText(input.value).then(copySuccess);
    } else {
      input.select();
      document.execCommand('copy');
      copySuccess();
    }
  };

  /* ---- PAYMENT POLLING ---- */
  function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    var maxAttempts = 120; // 10 min at 5s intervals
    var attempts = 0;

    pollingInterval = setInterval(function() {
      attempts++;
      if (attempts > maxAttempts) {
        clearInterval(pollingInterval);
        return;
      }
      checkPaymentStatus();

      // Update status message periodically for reassurance
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
    var btn = document.querySelector('.check-payment-btn');
    if (btn) {
      btn.innerHTML =
        '<div class="spinner" style="width:18px;height:18px;border-width:2px;margin:0;"></div>' +
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
        } else if (manual) {
          var btn = document.querySelector('.check-payment-btn');
          if (btn) {
            btn.innerHTML =
              '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>' +
              ' Ainda não confirmado. Aguarde...';
          }
          setTimeout(function() {
            if (btn) {
              btn.innerHTML =
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' +
                ' Já paguei - Verificar pagamento';
            }
            var statusEl = document.getElementById('payment-status');
            if (statusEl) statusEl.classList.remove('checking');
          }, 3000);
        }
      })
      .catch(function() {
        if (manual) {
          var btn = document.querySelector('.check-payment-btn');
          if (btn) {
            btn.innerHTML =
              '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' +
              ' Já paguei - Verificar pagamento';
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

    // Update status
    var statusEl = document.getElementById('payment-status');
    if (statusEl) {
      statusEl.classList.remove('checking');
      statusEl.classList.add('confirmed');
    }

    // Event ID for dedup between client-side pixel and server-side CAPI
    var purchaseEventId = 'pur_' + paymentCode + '_' + Date.now();
    // Save for potential server-side dedup
    try { localStorage.setItem('ml_purchase_event_id', purchaseEventId); } catch(e) {}

    var cartItems = Cart.getItems();
    var purchaseValue = (Cart.getSubtotal() + selectedFrete) / 100;

    // Fire FB Purchase event (client-side)
    if (typeof fbq === 'function') {
      fbq('track', 'Purchase', {
        value: purchaseValue,
        currency: 'BRL',
        content_ids: cartItems.map(function(i) { return i.id; }),
        content_type: 'product',
        order_id: paymentCode
      }, { eventID: purchaseEventId });
    }

    // Fire TikTok CompletePayment
    if (typeof ttq !== 'undefined') {
      ttq.track('CompletePayment', {
        content_type: 'product',
        content_id: cartItems.map(function(i) { return i.id; }).join(','),
        quantity: cartItems.reduce(function(sum, i) { return sum + i.quantity; }, 0),
        value: purchaseValue,
        currency: 'BRL'
      });
    }

    // Clear cart
    Cart.clear();

    // Redirect to UP page after 3 seconds
    setTimeout(function() {
      window.location.href = resolveUrl('/up/index.html') + getUTMQueryString();
    }, 3000);
  }

  /* ---- COUNTDOWN TIMER (STICKY BAR) ---- */
  function initTimer() {
    // Try to restore saved timer
    var saved = sessionStorage.getItem('ml_timer');
    if (saved) {
      var elapsed = Math.floor((Date.now() - parseInt(saved)) / 1000);
      countdownSeconds = Math.max(0, countdownSeconds - elapsed);
    } else {
      sessionStorage.setItem('ml_timer', Date.now().toString());
    }

    updateTimerDisplay();
    timerInterval = setInterval(function() {
      countdownSeconds--;
      if (countdownSeconds <= 0) {
        countdownSeconds = 0;
        clearInterval(timerInterval);
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

    // Make sticky timer urgent when < 2 min
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

  /* ---- SOCIAL PROOF ---- */
  function initSocialProof() {
    var people = [
      { name: 'Carlos H. de São Paulo', img: '../images/carloshenrique.jpeg' },
      { name: 'Felipe A. de Recife', img: '../images/felipeandrade.jpeg' },
      { name: 'Isabela M. de Curitiba', img: '../images/isabelamonteiro.jpeg' },
      { name: 'Ricardo M. de BH', img: '../images/ricardomoura.jpeg' },
      { name: 'Tatiane F. do Rio', img: '../images/tatianefreitas.jpeg' },
      { name: 'Daniel V. de Salvador', img: '../images/danielvasques.jpeg' },
      { name: 'Letícia N. de Brasília', img: '../images/leticianunes.jpeg' },
      { name: 'Lucas F. de Fortaleza', img: '../images/lucasferreira.jpeg' },
      { name: 'Vanessa B. de Manaus', img: '../images/vanessabarros.jpeg' },
      { name: 'Gabriela D. de Campinas', img: '../images/gabrieladuarte.jpeg' },
      { name: 'Natália R. de Goiânia', img: '../images/nataliaribeiro.jpeg' },
      { name: 'Bianca M. de Porto Alegre', img: '../images/biancamartins.jpeg' },
      { name: 'Thaís G. de Florianópolis', img: '../images/thaisgomes.jpeg' },
      { name: 'Simone O. de Belém', img: '../images/simoneoliveira.jpeg' },
      { name: 'Carolina V. de Vitória', img: '../images/carolinavasconcelos.jpeg' },
      { name: 'Ana Paula M. de Natal', img: '../images/anapaulamendes.jpg' },
      { name: 'Mariana T. de Santos', img: '../images/marianatorres.jpg' },
      { name: 'Larissa A. de Joinville', img: '../images/larissaaparecida.jpeg' },
      { name: 'Renata L. de Ribeirão Preto', img: '../images/renatalima.jpeg' },
      { name: 'Patrícia S. de Maceió', img: '../images/patriciasilva.jpeg' },
      { name: 'Camila F. de Maceió', img: '../images/camilafernandes.jpeg' },
      { name: 'Aline R. de Cuiabá', img: '../images/alinerocha.jpeg' }
    ];

    // More PIX-focused actions to reassure users
    var actions = [
      'acabou de finalizar a compra via PIX',
      'resgatou o cupom de desconto',
      'acabou de pagar via PIX',
      'confirmou o pagamento PIX',
      'concluiu a compra agora',
      'adicionou itens ao carrinho'
    ];

    var timeAgo = [
      'agora mesmo',
      'há 1 minuto',
      'há 2 minutos',
      'há poucos segundos',
      'agora mesmo'
    ];

    var el = document.getElementById('social-proof');
    if (!el) return;

    // Show first notification after 3 seconds
    setTimeout(function() { showSocialNotification(); }, 3000);

    // Then every 7 seconds
    setInterval(function() {
      showSocialNotification();
    }, 7000);

    function showSocialNotification() {
      var person = people[Math.floor(Math.random() * people.length)];
      var action = actions[Math.floor(Math.random() * actions.length)];
      var time = timeAgo[Math.floor(Math.random() * timeAgo.length)];

      var imgEl = el.querySelector('img');
      var textEl = el.querySelector('p');
      var timeEl = el.querySelector('.sp-time');

      if (imgEl) {
        imgEl.src = person.img;
        imgEl.alt = person.name;
      }
      if (textEl) {
        textEl.innerHTML = '<b>' + person.name + '</b> ' + action;
      }
      if (timeEl) {
        timeEl.textContent = time;
      }

      el.classList.add('show');
      setTimeout(function() {
        el.classList.remove('show');
      }, 4500);
    }
  }

  /* ---- TOAST ---- */
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
    setTimeout(function() {
      toast.classList.remove('show');
    }, 3000);
  }

  /* ---- HELPERS ---- */
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

})();
