/* ============================================
   A/B Testing Engine - Client-Side
   Allocates variants deterministically via hash
   Persists in localStorage, caches config in sessionStorage
   Usage: ABEngine.getAssignment('exp_id'), ABEngine.getConfig('exp_id')
   Sets window.__ML_EXPERIMENT for event tracking
   ============================================ */

var ABEngine = (function() {
  'use strict';

  var STORAGE_KEY = 'ml_experiments';
  var CACHE_KEY = 'ml_experiments_config';
  var CACHE_TTL = 10 * 60 * 1000; // 10 minutes

  var experiments = {};
  var assignments = {};
  var initialized = false;

  // Load existing assignments from localStorage
  try {
    var stored = localStorage.getItem(STORAGE_KEY);
    if (stored) assignments = JSON.parse(stored);
  } catch(e) { assignments = {}; }

  function init() {
    // Check if A/B engine is enabled via feature flags
    if (typeof MLFlags !== 'undefined' && !MLFlags.isEnabled('ab_engine')) {
      initialized = true;
      return;
    }

    fetchConfig(function(config) {
      experiments = config.experiments || {};
      processExperiments();
      initialized = true;
    });
  }

  function fetchConfig(callback) {
    // Try cache first
    try {
      var cached = sessionStorage.getItem(CACHE_KEY);
      if (cached) {
        var c = JSON.parse(cached);
        if (c && c.ts && (Date.now() - c.ts < CACHE_TTL)) {
          callback(c.data);
          return;
        }
      }
    } catch(e) {}

    // Resolve API path based on current page depth
    var apiBase = '/api/experiments.php';
    var path = window.location.pathname;
    if (path.indexOf('/produtos/') !== -1) {
      apiBase = '../../api/experiments.php';
    } else if (path.indexOf('/checkout/') !== -1 || path.indexOf('/recompensas/') !== -1 ||
               path.indexOf('/roleta/') !== -1 || path.indexOf('/up/') !== -1 ||
               path.indexOf('/vsl/') !== -1 || path.indexOf('/questionario/') !== -1 ||
               path.indexOf('/prevsl/') !== -1) {
      apiBase = '../api/experiments.php';
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', apiBase, true);
    xhr.timeout = 3000;
    xhr.onload = function() {
      if (xhr.status === 200) {
        try {
          var data = JSON.parse(xhr.responseText);
          sessionStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, ts: Date.now() }));
          callback(data);
        } catch(e) {
          callback({ experiments: {} });
        }
      } else {
        callback({ experiments: {} });
      }
    };
    xhr.onerror = function() { callback({ experiments: {} }); };
    xhr.ontimeout = function() { callback({ experiments: {} }); };
    xhr.send();
  }

  // DJB2 hash — simple, deterministic, fast
  function simpleHash(str) {
    var hash = 5381;
    for (var i = 0; i < str.length; i++) {
      hash = ((hash << 5) + hash) + str.charCodeAt(i);
      hash = hash & hash; // Convert to 32-bit integer
    }
    return Math.abs(hash);
  }

  function allocateVariant(experimentId, variants) {
    var sessionId = 'unknown';
    if (typeof MLA !== 'undefined' && typeof MLA.getSessionId === 'function') {
      sessionId = MLA.getSessionId();
    } else {
      try { sessionId = sessionStorage.getItem('ml_session_id') || 'unknown'; } catch(e) {}
    }

    var hash = simpleHash(sessionId + '_' + experimentId);
    var totalWeight = 0;
    var variantList = [];

    for (var name in variants) {
      if (variants.hasOwnProperty(name)) {
        var w = parseInt(variants[name].weight) || 0;
        totalWeight += w;
        variantList.push({ name: name, weight: w });
      }
    }

    if (totalWeight === 0 || variantList.length === 0) return 'control';

    var roll = hash % totalWeight;
    var cumulative = 0;
    for (var i = 0; i < variantList.length; i++) {
      cumulative += variantList[i].weight;
      if (roll < cumulative) return variantList[i].name;
    }
    return variantList[0].name; // fallback
  }

  function getPageName() {
    if (typeof MLA !== 'undefined' && typeof MLA.getPageName === 'function') {
      return MLA.getPageName();
    }
    return window.location.pathname;
  }

  function processExperiments() {
    var currentPage = getPageName();

    for (var expId in experiments) {
      if (!experiments.hasOwnProperty(expId)) continue;
      var exp = experiments[expId];

      // Only process running experiments
      if (exp.status !== 'running') continue;

      // Check if this experiment targets the current page
      var targets = exp.target_pages || [];
      var targeted = targets.length === 0; // empty = all pages
      if (!targeted) {
        for (var t = 0; t < targets.length; t++) {
          if (currentPage.indexOf(targets[t]) !== -1) {
            targeted = true;
            break;
          }
        }
      }
      if (!targeted) continue;

      // Assign variant if not already assigned
      if (!assignments[expId]) {
        assignments[expId] = allocateVariant(expId, exp.variants);
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(assignments));
        } catch(e) {}
      }

      var variantName = assignments[expId];
      var variantConfig = (exp.variants && exp.variants[variantName]) ? exp.variants[variantName].config || {} : {};

      // Set active experiment for event tracking (last one wins if multiple)
      window.__ML_EXPERIMENT = {
        id: expId,
        variant: variantName,
        config: variantConfig
      };

      // Dispatch custom event for page-specific variant handlers
      try {
        var evt = new CustomEvent('ml_variant_applied', {
          detail: {
            experiment: expId,
            variant: variantName,
            config: variantConfig
          }
        });
        document.dispatchEvent(evt);
      } catch(e) {}
    }
  }

  // Public API
  var api = {
    init: init,
    getAssignment: function(expId) { return assignments[expId] || null; },
    getConfig: function(expId) {
      var variant = assignments[expId];
      if (!variant || !experiments[expId]) return {};
      var v = experiments[expId].variants;
      return (v && v[variant]) ? v[variant].config || {} : {};
    },
    getAllAssignments: function() {
      var copy = {};
      for (var k in assignments) {
        if (assignments.hasOwnProperty(k)) copy[k] = assignments[k];
      }
      return copy;
    },
    isReady: function() { return initialized; },
    // Force clear (for testing)
    reset: function() {
      assignments = {};
      try {
        localStorage.removeItem(STORAGE_KEY);
        sessionStorage.removeItem(CACHE_KEY);
      } catch(e) {}
    }
  };

  // Auto-init on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { init(); });
  } else {
    // DOM already loaded
    setTimeout(init, 0);
  }

  return api;
})();
