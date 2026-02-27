/* ============================================
   Checkout - Mercado Livre Style
   Steps, Cart, ViaCEP, PIX Payment, Tracking
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

  /* ═══════════════════════════════════════
     INIT
     ═══════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function() {
    renderCartBar();
    renderCartExpand();
    initTimer();
    initSocialProof();
    initInputMasks();

    // If cart is empty, redirect back
    if (Cart.getCount() === 0) {
      window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
      return;
    }
  });

  /* ═══════════════════════════════════════
     CART BAR (compact top bar)
     ═══════════════════════════════════════ */
  function renderCartBar() {
    var countEl = document.getElementById('cart-bar-count');
    var totalEl = document.getElementById('cart-bar-total');

    if (countEl) countEl.textContent = Cart.getCount();
    if (totalEl) totalEl.textContent = formatPrice(Cart.getSubtotal() + selectedFrete);
  }

  function renderCartExpand() {
    var container = document.getElementById('cart-expand-items');
    if (!container) return;

    var items = Cart.getItems();
    container.innerHTML = '';

    items.forEach(function(item) {
      var div = document.createElement('div');
      div.className = 'cart-expand-item';
      div.innerHTML =
        '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">' +
        '<div class="cart-expand-item-info">' +
          '<div class="cart-expand-item-name">' + escapeHtml(item.name) + '</div>' +
          '<div class="cart-expand-item-meta">' + item.quantity + ' un.</div>' +
        '</div>' +
        '<span class="cart-expand-item-price">' + formatPrice(item.price * item.quantity) + '</span>' +
        '<button class="cart-expand-item-remove" data-id="' + escapeHtml(item.id) + '" title="Remover">&times;</button>';
      container.appendChild(div);
    });

    // Bind remove buttons
    container.querySelectorAll('.cart-expand-item-remove').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        Cart.removeItem(this.getAttribute('data-id'));
        renderCartBar();
        renderCartExpand();
        if (Cart.getCount() === 0) {
          window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
        }
      });
    });
  }

  window.toggleCartExpand = function() {
    var expand = document.getElementById('cart-expand');
    var chevron = document.getElementById('cart-bar-chevron');
    if (expand) expand.classList.toggle('open');
    if (chevron) chevron.classList.toggle('open');
  };

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

    // Show/hide cart bar (hide on step 3)
    var cartBar = document.getElementById('cart-bar');
    var cartExpand = document.getElementById('cart-expand');
    if (cartBar) cartBar.style.display = step === 3 ? 'none' : '';
    if (cartExpand) {
      cartExpand.classList.remove('open');
      if (step === 3) cartExpand.style.display = 'none';
      else cartExpand.style.display = '';
    }
    var chevron = document.getElementById('cart-bar-chevron');
    if (chevron) chevron.classList.remove('open');

    // Save customer data for UP page
    if (step === 2 || step === 3) {
      localStorage.setItem('ml_customer_data', JSON.stringify({
        email: getValue('email'),
        name: getValue('nome'),
        document: getValue('cpf'),
        phone: getValue('telefone')
      }));
    }

    // Step 3: render order summary and generate PIX
    if (step === 3) {
      renderOrderSummary();
      setTimeout(renderOrderSummary, 150);
      generatePix();
    }
  };

  window.goBack = function() {
    if (currentStep > 1) {
      goToStep(currentStep - 1);
    }
  };

  /* ═══════════════════════════════════════
     ORDER SUMMARY (Step 3)
     ═══════════════════════════════════════ */
  function renderOrderSummary() {
    try {
      var items = Cart.getItems();
      var osItems = document.getElementById('os-items');
      var osSubtotal = document.getElementById('os-subtotal');
      var osFrete = document.getElementById('os-frete');
      var osTotal = document.getElementById('os-total');

      if (osItems) {
        osItems.innerHTML = '';
        items.forEach(function(item) {
          var div = document.createElement('div');
          div.className = 'os-item';
          div.innerHTML =
            '<img src="' + escapeHtml(item.image || '') + '" alt="">' +
            '<span class="os-item-name">' + escapeHtml(item.name || 'Produto') + '</span>' +
            '<span class="os-item-price">' + formatPrice((item.price || 0) * (item.quantity || 1)) + '</span>';
          osItems.appendChild(div);
        });
      }

      var subtotal = Cart.getSubtotal();
      if (osSubtotal) osSubtotal.textContent = formatPrice(subtotal);
      if (osFrete) osFrete.textContent = selectedFrete === 0 ? 'Grátis' : formatPrice(selectedFrete);
      if (osTotal) osTotal.textContent = formatPrice(subtotal + selectedFrete);

      // Savings
      updateSavings();
    } catch(e) {
      console.error('renderOrderSummary error:', e);
    }
  }
  window.renderOrderSummary = renderOrderSummary;

  /* ═══════════════════════════════════════
     SAVINGS
     ═══════════════════════════════════════ */
  function updateSavings() {
    var items = Cart.getItems();
    var totalSavings = 0;
    items.forEach(function(item) {
      if (item.oldPrice && item.oldPrice > item.price) {
        totalSavings += (item.oldPrice - item.price) * item.quantity;
      }
    });

    if (totalSavings > 0) {
      var savingsRow = document.getElementById('os-savings-row');
      var savingsAmount = document.getElementById('os-savings');
      if (savingsRow) savingsRow.style.display = 'flex';
      if (savingsAmount) savingsAmount.textContent = '-' + formatPrice(totalSavings);
    }
  }

  /* ═══════════════════════════════════════
     FORM VALIDATION
     ═══════════════════════════════════════ */
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
      /* No strict blocking — accept any data to not lose leads */
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
    var shippingCard = document.getElementById('shipping-card');
    if (shippingCard) shippingCard.style.display = '';
  }

  window.buscarCEP = function() {
    var cep = getValue('cep').replace(/\D/g, '');
    if (cep.length !== 8) return;

    var cepBtn = document.querySelector('.cep-btn');
    if (cepBtn) cepBtn.textContent = '...';

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
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

        var shippingCard = document.getElementById('shipping-card');
        if (shippingCard) shippingCard.style.display = '';

        var numEl = document.getElementById('numero');
        if (numEl) numEl.focus();
      })
      .catch(function() {
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
    renderCartBar();
  };

  /* ═══════════════════════════════════════
     PIX GENERATION
     ═══════════════════════════════════════ */
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
      alert('Erro: valor do pedido inválido (R$ ' + (totalAmount / 100).toFixed(2).replace('.', ',') + '). Volte e adicione produtos novamente.');
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
        qrContainer.innerHTML = qr.createImgTag(5, 12);
      }

      // Set copy code
      var codeInput = document.getElementById('pix-code');
      if (codeInput) codeInput.value = data.pix_qrcode_text;

      // Show content
      if (loading) loading.style.display = 'none';
      if (content) content.style.display = 'block';

      // Re-render order summary (failsafe)
      renderOrderSummary();

      // Start PIX countdown timer
      startPixCountdown();

      // Start polling for payment
      startPolling();

      // Event ID for dedup
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

      // Fire TikTok InitiateCheckout
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
        '<button class="ml-btn-primary" onclick="generatePix()" style="max-width:260px;margin:0 auto;">' +
          'Tentar novamente' +
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
        btn.innerHTML =
          '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' +
          ' Código copiado!';

        setTimeout(function() {
          btn.classList.remove('copied');
          btn.innerHTML =
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>' +
            ' Copiar código Pix';
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
    var btn = document.querySelector('.ml-btn-secondary');
    if (btn) {
      btn.innerHTML =
        '<div class="ml-spinner" style="width:16px;height:16px;border-width:2px;margin:0;"></div>' +
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
          var btn = document.querySelector('.ml-btn-secondary');
          if (btn) {
            btn.innerHTML =
              '<svg width="16" height="16" viewBox="0 0 24 24" fill="#3483fa"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>' +
              ' Ainda não confirmado. Aguarde...';
          }
          setTimeout(function() {
            if (btn) {
              btn.innerHTML =
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="#3483fa"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' +
                ' Já paguei';
            }
            var statusEl = document.getElementById('payment-status');
            if (statusEl) statusEl.classList.remove('checking');
          }, 3000);
        }
      })
      .catch(function() {
        if (manual) {
          var btn = document.querySelector('.ml-btn-secondary');
          if (btn) {
            btn.innerHTML =
              '<svg width="16" height="16" viewBox="0 0 24 24" fill="#3483fa"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' +
              ' Já paguei';
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

    var purchaseEventId = 'pur_' + paymentCode + '_' + Date.now();
    try { localStorage.setItem('ml_purchase_event_id', purchaseEventId); } catch(e) {}

    var cartItems = Cart.getItems();
    var purchaseValue = (Cart.getSubtotal() + selectedFrete) / 100;

    // Fire FB Purchase
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

    // Redirect to UP page
    setTimeout(function() {
      window.location.href = resolveUrl('/up/index.html') + getUTMQueryString();
    }, 3000);
  }

  /* ═══════════════════════════════════════
     COUNTDOWN TIMER (STICKY BAR)
     ═══════════════════════════════════════ */
  function initTimer() {
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
     SOCIAL PROOF
     ═══════════════════════════════════════ */
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

    setTimeout(function() { showSocialNotification(); }, 4000);
    setInterval(function() { showSocialNotification(); }, 8000);

    function showSocialNotification() {
      var person = people[Math.floor(Math.random() * people.length)];
      var action = actions[Math.floor(Math.random() * actions.length)];
      var time = timeAgo[Math.floor(Math.random() * timeAgo.length)];

      var imgEl = el.querySelector('img');
      var textEl = el.querySelector('p');
      var timeEl = el.querySelector('.sp-time');

      if (imgEl) { imgEl.src = person.img; imgEl.alt = person.name; }
      if (textEl) { textEl.innerHTML = '<b>' + person.name + '</b> ' + action; }
      if (timeEl) { timeEl.textContent = time; }

      el.classList.add('show');
      setTimeout(function() { el.classList.remove('show'); }, 4000);
    }
  }

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
