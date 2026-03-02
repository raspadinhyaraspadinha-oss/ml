/* ============================================
   ML Analytics - Smart Tracking Library
   Shared across all pages

   Handles:
   - Internal analytics events (→ api/event.php)
   - Facebook Pixel + CAPI dedup (eventID)
   - TikTok Pixel + Events API dedup (event_id)
   - Session tracking, ttclid capture
   - Rich user matching data collection
   ============================================ */

var MLA = (function() {
  'use strict';

  // ── Session ID: persist per browser session ──
  var SESSION_KEY = 'ml_session_id';
  var sessionId = sessionStorage.getItem(SESSION_KEY);
  if (!sessionId) {
    sessionId = 'ses_' + Date.now() + '_' + rand(8);
    sessionStorage.setItem(SESSION_KEY, sessionId);
  }

  // ── Debug mode ──
  var DEBUG = (window.location.search.indexOf('ml_debug=1') !== -1);
  if (DEBUG) {
    try { localStorage.setItem('ml_debug', '1'); } catch(e) {}
  } else {
    try { DEBUG = localStorage.getItem('ml_debug') === '1'; } catch(e) {}
  }

  // ── Capture ttclid (TikTok Click ID) ──
  (function captureTTClid() {
    var params = new URLSearchParams(window.location.search);
    var ttclid = params.get('ttclid');
    if (ttclid) {
      try {
        localStorage.setItem('ml_ttclid', ttclid);
        // Also set as cookie for pixel auto-read
        document.cookie = 'ttclid=' + ttclid + '; max-age=2592000; path=/; SameSite=Lax';
      } catch(e) {}
    }
  })();

  // ── Helpers ──
  function rand(len) {
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    var s = '';
    for (var i = 0; i < len; i++) {
      s += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return s;
  }

  function generateEventId(prefix) {
    // UUID-like: prefix_timestamp_random
    prefix = prefix || 'evt';
    return prefix + '_' + Date.now() + '_' + rand(8);
  }

  function log() {
    if (DEBUG && console && console.log) {
      var args = ['[MLA]'].concat(Array.prototype.slice.call(arguments));
      console.log.apply(console, args);
    }
  }

  function getPageName() {
    var path = window.location.pathname;
    // Detect funnel stage from URL
    if (path.indexOf('/prevsl') !== -1) return 'prevsl';
    if (path.indexOf('/vsl') !== -1) return 'vsl';
    if (path.indexOf('/questionario') !== -1) return 'questionario';
    if (path.indexOf('/roleta') !== -1) return 'roleta';
    if (path.indexOf('/recompensas') !== -1) return 'recompensas';
    if (path.indexOf('/produtos/') !== -1) {
      var parts = path.split('/').filter(function(p) { return p && p !== 'index.html'; });
      return 'produto:' + (parts[parts.length - 1] || 'unknown');
    }
    if (path.indexOf('/checkout') !== -1) return 'checkout';
    if (path.indexOf('/up') !== -1) return 'upsell';
    return path;
  }

  function getFunnelStage() {
    var stages = {
      'prevsl': 1,
      'vsl': 2,
      'questionario': 3,
      'roleta': 4,
      'recompensas': 5,
      'checkout': 7,
      'upsell': 8
    };
    var page = getPageName();
    if (page.indexOf('produto:') === 0) return 6;
    return stages[page] || 0;
  }

  function getStoredUTMs() {
    try {
      return JSON.parse(localStorage.getItem('ml_utms') || '{}');
    } catch(e) { return {}; }
  }

  function getStoredFBP() {
    // Try cookie first, then localStorage
    var match = document.cookie.match(/(?:^|;\s*)_fbp=([^;]*)/);
    if (match) return match[1];
    return localStorage.getItem('ml_fbp') || null;
  }

  function getStoredFBC() {
    var match = document.cookie.match(/(?:^|;\s*)_fbc=([^;]*)/);
    if (match) return match[1];
    return localStorage.getItem('ml_fbc') || null;
  }

  function getStoredTTClid() {
    return localStorage.getItem('ml_ttclid') || null;
  }

  // ── Track internal analytics event ──
  function track(eventName, data) {
    data = data || {};

    var payload = {
      event: eventName,
      session_id: sessionId,
      page: getPageName(),
      funnel_stage: getFunnelStage(),
      url: window.location.href,
      referrer: document.referrer || '',
      timestamp: new Date().toISOString(),
      data: data,
      utms: getStoredUTMs()
    };

    log('track:', eventName, data);

    // Fire and forget — non-blocking
    try {
      var apiBase = '';
      if (window.location.protocol === 'file:') {
        // Can't send to API from file:// protocol
        log('SKIP (file:// protocol)');
        return;
      }

      // Resolve API path
      var path = window.location.pathname;
      if (path.indexOf('/produtos/') !== -1) {
        apiBase = '../../api/event.php';
      } else if (path.indexOf('/checkout/') !== -1 || path.indexOf('/recompensas/') !== -1 ||
                 path.indexOf('/roleta/') !== -1 || path.indexOf('/up/') !== -1 ||
                 path.indexOf('/vsl/') !== -1 || path.indexOf('/questionario/') !== -1 ||
                 path.indexOf('/prevsl/') !== -1) {
        apiBase = '../api/event.php';
      } else {
        apiBase = '/api/event.php';
      }

      var xhr = new XMLHttpRequest();
      xhr.open('POST', apiBase, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify(payload));
    } catch(e) {
      log('track error:', e);
    }
  }

  // ── Facebook Pixel with dedup ──
  function fbTrack(eventName, customData, eventId) {
    if (typeof fbq !== 'function') {
      log('FB Pixel not loaded, skip:', eventName);
      return eventId;
    }
    eventId = eventId || generateEventId('fb');

    var opts = { eventID: eventId };

    if (eventName === 'PageView') {
      // PageView is auto-tracked, but we can fire it explicitly
      fbq('track', 'PageView', {}, opts);
    } else {
      fbq('track', eventName, customData || {}, opts);
    }

    log('FB Pixel:', eventName, 'eventID:', eventId, customData);
    return eventId;
  }

  // ── Facebook Custom Event with dedup ──
  function fbTrackCustom(eventName, customData, eventId) {
    if (typeof fbq !== 'function') {
      log('FB Pixel not loaded, skip custom:', eventName);
      return eventId;
    }
    eventId = eventId || generateEventId('fb');
    fbq('trackCustom', eventName, customData || {}, { eventID: eventId });
    log('FB Custom:', eventName, 'eventID:', eventId, customData);
    return eventId;
  }

  // ── TikTok Pixel with dedup ──
  function ttTrack(eventName, properties, eventId) {
    if (typeof ttq === 'undefined') {
      log('TikTok Pixel not loaded, skip:', eventName);
      return eventId;
    }
    eventId = eventId || generateEventId('tt');

    var opts = {
      event_id: eventId
    };

    // Merge properties
    var data = {};
    if (properties) {
      for (var key in properties) {
        if (properties.hasOwnProperty(key)) {
          data[key] = properties[key];
        }
      }
    }

    ttq.track(eventName, data, opts);
    log('TT Pixel:', eventName, 'event_id:', eventId, data);
    return eventId;
  }

  // ── Combined: Fire both FB + TT + internal analytics ──
  function fireEvent(eventName, opts) {
    opts = opts || {};
    var eventId = opts.eventId || generateEventId(eventName.toLowerCase().replace(/[^a-z]/g, ''));

    // Internal analytics
    var analyticsData = opts.analyticsData || {};
    analyticsData.event_id = eventId;
    track(eventName, analyticsData);

    // Facebook Pixel
    if (opts.fb !== false) {
      var fbData = opts.fbData || opts.customData || {};
      if (opts.fbCustom) {
        fbTrackCustom(eventName, fbData, eventId);
      } else {
        fbTrack(opts.fbEventName || eventName, fbData, eventId);
      }
    }

    // TikTok Pixel
    if (opts.tt !== false) {
      var ttData = opts.ttData || opts.customData || {};
      ttTrack(opts.ttEventName || eventName, ttData, eventId);
    }

    return eventId;
  }

  // ── Specific funnel events ──

  // ViewContent: product page
  function trackViewContent(product) {
    var eventId = generateEventId('vc');
    var value = (product.price || 0) / 100;

    track('ViewContent', {
      event_id: eventId,
      product_id: product.id,
      product_name: product.name,
      value: value
    });

    fbTrack('ViewContent', {
      content_ids: [product.id],
      content_name: product.name,
      content_type: 'product',
      value: value,
      currency: 'BRL'
    }, eventId);

    ttTrack('ViewContent', {
      content_id: product.id,
      content_name: product.name,
      content_type: 'product',
      value: value,
      currency: 'BRL'
    }, eventId);

    return eventId;
  }

  // AddToCart: product page
  function trackAddToCart(product) {
    var eventId = generateEventId('atc');
    var value = (product.price || 0) / 100;

    track('AddToCart', {
      event_id: eventId,
      product_id: product.id,
      product_name: product.name,
      value: value
    });

    fbTrack('AddToCart', {
      content_ids: [product.id],
      content_name: product.name,
      content_type: 'product',
      value: value,
      currency: 'BRL'
    }, eventId);

    ttTrack('AddToCart', {
      content_id: product.id,
      content_name: product.name,
      content_type: 'product',
      value: value,
      currency: 'BRL'
    }, eventId);

    return eventId;
  }

  // InitiateCheckout: checkout page load (step 1)
  function trackInitiateCheckout(items, totalValue) {
    var eventId = generateEventId('ic');
    var value = (totalValue || 0) / 100;
    var contentIds = items.map(function(i) { return i.id; });

    track('InitiateCheckout', {
      event_id: eventId,
      num_items: items.length,
      value: value,
      content_ids: contentIds
    });

    fbTrack('InitiateCheckout', {
      content_ids: contentIds,
      content_type: 'product',
      num_items: items.length,
      value: value,
      currency: 'BRL'
    }, eventId);

    ttTrack('InitiateCheckout', {
      content_type: 'product',
      content_id: contentIds.join(','),
      quantity: items.length,
      value: value,
      currency: 'BRL'
    }, eventId);

    return eventId;
  }

  // AddPaymentInfo: checkout step 5 (confirm)
  function trackAddPaymentInfo(items, totalValue) {
    var eventId = generateEventId('api');
    var value = (totalValue || 0) / 100;

    track('AddPaymentInfo', {
      event_id: eventId,
      payment_method: 'pix',
      value: value,
      num_items: items.length
    });

    fbTrack('AddPaymentInfo', {
      content_type: 'product',
      value: value,
      currency: 'BRL',
      content_ids: items.map(function(i) { return i.id; })
    }, eventId);

    ttTrack('AddPaymentInfo', {
      content_type: 'product',
      value: value,
      currency: 'BRL',
      description: 'PIX'
    }, eventId);

    return eventId;
  }

  // GeneratePixCode: custom event when QR appears
  function trackGeneratePixCode(paymentCode, totalValue, items) {
    var eventId = generateEventId('gpx');
    var value = (totalValue || 0) / 100;

    track('GeneratePixCode', {
      event_id: eventId,
      payment_code: paymentCode,
      value: value,
      num_items: (items || []).length
    });

    fbTrackCustom('GeneratePixCode', {
      value: value,
      currency: 'BRL',
      payment_method: 'pix',
      order_id: paymentCode,
      content_type: 'product',
      content_ids: (items || []).map(function(i) { return i.id; })
    }, eventId);

    // TikTok custom event
    ttTrack('GeneratePixCode', {
      value: value,
      currency: 'BRL',
      order_id: paymentCode,
      description: 'PIX QR Code Generated'
    }, eventId);

    return eventId;
  }

  // CopyPixCode: custom event when user copies PIX code
  function trackCopyPixCode(paymentCode) {
    var eventId = generateEventId('cpx');

    track('CopyPixCode', {
      event_id: eventId,
      payment_code: paymentCode
    });

    // Only browser-side (no server visibility)
    fbTrackCustom('CopyPixCode', {
      payment_method: 'pix',
      order_id: paymentCode
    }, eventId);

    ttTrack('ClickButton', {
      description: 'Copy PIX Code',
      order_id: paymentCode
    }, eventId);

    return eventId;
  }

  // Purchase: when payment confirmed on client
  // IMPORTANT: event_id MUST match server-side (fireApprovalTracking) for dedup
  function trackPurchase(paymentCode, totalValue, items) {
    var eventId = 'pur_' + paymentCode;
    var value = (totalValue || 0) / 100;
    var contentIds = (items || []).map(function(i) { return i.id; });

    track('Purchase', {
      event_id: eventId,
      payment_code: paymentCode,
      value: value,
      num_items: (items || []).length
    });

    fbTrack('Purchase', {
      value: value,
      currency: 'BRL',
      content_ids: contentIds,
      content_type: 'product',
      order_id: paymentCode
    }, eventId);

    ttTrack('CompletePayment', {
      content_type: 'product',
      content_id: contentIds.join(','),
      quantity: (items || []).reduce(function(sum, i) { return sum + (i.quantity || 1); }, 0),
      value: value,
      currency: 'BRL'
    }, eventId);

    return eventId;
  }

  // Checkout step tracking (internal only)
  function trackCheckoutStep(step, stepName) {
    track('checkout_step', {
      step: step,
      step_name: stepName || ('step_' + step)
    });
  }

  // Form error tracking (internal only)
  function trackFormError(field, message) {
    track('form_error', {
      field: field,
      message: message
    });
  }

  // Funnel page view (internal only)
  function trackFunnelView(page) {
    track('funnel_view', {
      page: page || getPageName(),
      funnel_stage: getFunnelStage()
    });
  }

  // ── Auto-track funnel page view on load ──
  document.addEventListener('DOMContentLoaded', function() {
    trackFunnelView();
  });

  // ── Public API ──
  return {
    // Core
    track: track,
    fbTrack: fbTrack,
    fbTrackCustom: fbTrackCustom,
    ttTrack: ttTrack,
    fireEvent: fireEvent,
    generateEventId: generateEventId,

    // Specific funnel events
    trackViewContent: trackViewContent,
    trackAddToCart: trackAddToCart,
    trackInitiateCheckout: trackInitiateCheckout,
    trackAddPaymentInfo: trackAddPaymentInfo,
    trackGeneratePixCode: trackGeneratePixCode,
    trackCopyPixCode: trackCopyPixCode,
    trackPurchase: trackPurchase,
    trackCheckoutStep: trackCheckoutStep,
    trackFormError: trackFormError,
    trackFunnelView: trackFunnelView,

    // Utilities
    getSessionId: function() { return sessionId; },
    getPageName: getPageName,
    getFunnelStage: getFunnelStage,
    getStoredFBP: getStoredFBP,
    getStoredFBC: getStoredFBC,
    getStoredTTClid: getStoredTTClid,
    getStoredUTMs: getStoredUTMs,
    isDebug: function() { return DEBUG; }
  };

})();
