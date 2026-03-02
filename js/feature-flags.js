/* ============================================
   Feature Flags - Client-Side Reader
   Loads flags from /api/feature-flags.php
   Caches in sessionStorage (5min TTL)
   Usage: MLFlags.isEnabled('pix_idempotency')
   ============================================ */

var MLFlags = (function() {
  'use strict';

  var CACHE_KEY = 'ml_feature_flags';
  var CACHE_TTL = 5 * 60 * 1000; // 5 minutes
  var flags = null;
  var loaded = false;
  var callbacks = [];

  // Try loading from cache immediately (synchronous)
  try {
    var cached = sessionStorage.getItem(CACHE_KEY);
    if (cached) {
      var parsed = JSON.parse(cached);
      if (parsed && parsed.ts && (Date.now() - parsed.ts < CACHE_TTL)) {
        flags = parsed.data;
        loaded = true;
      }
    }
  } catch(e) {}

  // Fetch from API (async, updates cache)
  function fetchFlags() {
    try {
      var apiBase = '';
      var path = window.location.pathname;
      if (path.indexOf('/produtos/') !== -1) {
        apiBase = '../../api/feature-flags.php';
      } else if (path.indexOf('/checkout/') !== -1 || path.indexOf('/recompensas/') !== -1 ||
                 path.indexOf('/roleta/') !== -1 || path.indexOf('/up/') !== -1 ||
                 path.indexOf('/vsl/') !== -1 || path.indexOf('/questionario/') !== -1 ||
                 path.indexOf('/prevsl/') !== -1) {
        apiBase = '../api/feature-flags.php';
      } else {
        apiBase = '/api/feature-flags.php';
      }

      var xhr = new XMLHttpRequest();
      xhr.open('GET', apiBase, true);
      xhr.timeout = 3000; // 3s timeout - don't block page
      xhr.onload = function() {
        if (xhr.status === 200) {
          try {
            var data = JSON.parse(xhr.responseText);
            flags = data;
            loaded = true;
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, ts: Date.now() }));

            // Fire pending callbacks
            while (callbacks.length > 0) {
              var cb = callbacks.shift();
              try { cb(flags); } catch(e) {}
            }
          } catch(e) {}
        }
      };
      xhr.onerror = function() {
        // On error, flags remain as cached or null (defaults to enabled)
        loaded = true;
      };
      xhr.ontimeout = function() {
        loaded = true;
      };
      xhr.send();
    } catch(e) {
      loaded = true;
    }
  }

  // Check if a specific feature flag is enabled
  // Returns true by default if flags haven't loaded (fail-open for UX)
  // If global_killswitch is ON, returns false for everything
  function isEnabled(flagName) {
    if (!flags) return true; // Default: enabled if no flags loaded

    // Global killswitch overrides everything
    if (flags.global_killswitch === true) return false;

    var flag = flags.flags ? flags.flags[flagName] : null;
    if (!flag) return true; // Unknown flag: enabled by default

    return flag.enabled !== false;
  }

  // Wait for flags to load, then run callback
  function onReady(callback) {
    if (loaded) {
      callback(flags);
    } else {
      callbacks.push(callback);
    }
  }

  // Start fetching (non-blocking)
  fetchFlags();

  return {
    isEnabled: isEnabled,
    onReady: onReady,
    getAll: function() { return flags; },
    isLoaded: function() { return loaded; },
    // Force refresh (used by dashboard)
    refresh: function() {
      sessionStorage.removeItem(CACHE_KEY);
      fetchFlags();
    }
  };

})();
