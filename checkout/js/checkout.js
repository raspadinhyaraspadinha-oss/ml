/* ============================================
   Checkout Page Logic
   Steps, Cart, ViaCEP, Mangofy, Tracking
   ============================================ */

(function() {
  'use strict';

  var currentStep = 1;
  var selectedFrete = 0; // in cents
  var paymentCode = null;
  var pollingInterval = null;
  var timerInterval = null;
  var countdownSeconds = 5 * 60 + 30; // 5 min 30 sec initial timer

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
  });

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
            '<div class="cart-item-qty">' + item.quantity + ' un.</div>' +
          '</div>' +
          '<button class="cart-item-remove" data-id="' + escapeHtml(item.id) + '" title="Remover">&times;</button>';
        container.appendChild(div);
      });

      // Bind remove buttons
      container.querySelectorAll('.cart-item-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
          Cart.removeItem(this.getAttribute('data-id'));
          renderCart();
          if (Cart.getCount() === 0) {
            window.location.href = resolveUrl('/recompensas/index.html') + getUTMQueryString();
          }
        });
      });
    }

    var subtotal = Cart.getSubtotal();
    if (subtotalEl) subtotalEl.textContent = formatPrice(subtotal);
    if (freteEl) freteEl.textContent = selectedFrete === 0 ? '-' : formatPrice(selectedFrete);
    if (totalEl) totalEl.textContent = formatPrice(subtotal + selectedFrete);
  };

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

    // If step 3, generate PIX
    if (step === 3) {
      generatePix();
    }
  };

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
      var cep = getValue('cep');
      var numero = getValue('numero');
      var rua = getValue('rua');
      var valid = true;

      if (!cep || cep.replace(/\D/g, '').length !== 8) {
        showError('cep', 'Informe um CEP válido');
        valid = false;
      }
      if (!rua) {
        showError('cep', 'Busque o CEP primeiro');
        valid = false;
      }
      if (!numero) {
        showError('numero', 'Informe o número');
        valid = false;
      }
      return valid;
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
  window.buscarCEP = function() {
    var cep = getValue('cep').replace(/\D/g, '');
    if (cep.length !== 8) {
      showError('cep', 'CEP inválido');
      return;
    }

    var cepBtn = document.querySelector('.cep-btn');
    if (cepBtn) cepBtn.textContent = '...';

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.erro) {
          showError('cep', 'CEP não encontrado');
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
        showError('cep', 'Erro ao buscar CEP');
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
        qrContainer.innerHTML = qr.createImgTag(5, 16);
      }

      // Set copy code
      var codeInput = document.getElementById('pix-code');
      if (codeInput) codeInput.value = data.pix_qrcode_text;

      // Show content
      if (loading) loading.style.display = 'none';
      if (content) content.style.display = 'block';

      // Start polling for payment
      startPolling();

      // Fire FB InitiateCheckout
      if (typeof fbq === 'function') {
        fbq('track', 'InitiateCheckout', {
          value: totalAmount / 100,
          currency: 'BRL',
          num_items: Cart.getCount()
        });
      }

      // Fire TikTok InitiateCheckout (PIX generated)
      if (typeof ttq !== 'undefined') {
        ttq.track('InitiateCheckout', {
          content_type: 'product',
          value: totalAmount / 100,
          currency: 'BRL'
        });
      }
    })
    .catch(function(err) {
      if (loading) loading.innerHTML = '<p style="color:#f23d4f;">Erro ao gerar PIX. Tente novamente.</p>' +
        '<button class="cta-btn" onclick="generatePix()" style="margin-top:12px;">Tentar novamente</button>';
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

  /* ---- COPY PIX CODE ---- */
  window.copyPixCode = function() {
    var input = document.getElementById('pix-code');
    if (!input) return;

    if (navigator.clipboard) {
      navigator.clipboard.writeText(input.value).then(function() {
        showToast('Código PIX copiado!');
      });
    } else {
      input.select();
      document.execCommand('copy');
      showToast('Código PIX copiado!');
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
    }, 5000);
  }

  window.checkPayment = function() {
    var btn = document.querySelector('.check-payment-btn');
    if (btn) btn.textContent = 'Verificando...';
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
          if (btn) btn.textContent = 'Ainda não confirmado. Tente novamente.';
          setTimeout(function() {
            if (btn) btn.textContent = 'Já paguei - Verificar';
          }, 3000);
        }
      })
      .catch(function() {
        if (manual) {
          var btn = document.querySelector('.check-payment-btn');
          if (btn) btn.textContent = 'Erro. Tente novamente.';
        }
      });
  }

  function onPaymentConfirmed() {
    if (pollingInterval) clearInterval(pollingInterval);

    var content = document.getElementById('pix-content');
    var confirmed = document.getElementById('pix-confirmed');

    if (content) content.style.display = 'none';
    if (confirmed) confirmed.style.display = 'block';

    // Fire FB Purchase event (client-side)
    if (typeof fbq === 'function') {
      fbq('track', 'Purchase', {
        value: (Cart.getSubtotal() + selectedFrete) / 100,
        currency: 'BRL',
        content_ids: Cart.getItems().map(function(i) { return i.id; }),
        content_type: 'product'
      });
    }

    // Fire TikTok CompletePayment
    if (typeof ttq !== 'undefined') {
      ttq.track('CompletePayment', {
        content_type: 'product',
        value: (Cart.getSubtotal() + selectedFrete) / 100,
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

  /* ---- COUNTDOWN TIMER ---- */
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
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  /* ---- SOCIAL PROOF ---- */
  function initSocialProof() {
    var names = [
      'Carlos H. de São Paulo',
      'Felipe A. de Recife',
      'Isabela M. de Curitiba',
      'Ricardo M. de BH',
      'Tatiane F. do Rio',
      'Daniel V. de Salvador',
      'Letícia N. de Brasília',
      'Lucas F. de Fortaleza',
      'Vanessa B. de Manaus',
      'Gabriela D. de Campinas',
      'Natália R. de Goiânia',
      'Bianca M. de Porto Alegre'
    ];

    var actions = [
      'acabou de finalizar a compra',
      'resgatou o cupom de desconto',
      'adicionou itens ao carrinho',
      'acabou de pagar via PIX'
    ];

    var el = document.getElementById('social-proof');
    if (!el) return;

    setInterval(function() {
      var name = names[Math.floor(Math.random() * names.length)];
      var action = actions[Math.floor(Math.random() * actions.length)];
      var textEl = el.querySelector('p');
      if (textEl) {
        textEl.innerHTML = '<b>' + name + '</b> ' + action;
      }
      el.classList.add('show');
      setTimeout(function() {
        el.classList.remove('show');
      }, 4000);
    }, 8000);
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
    }, 2500);
  }

  /* ---- HELPERS ---- */
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

})();
