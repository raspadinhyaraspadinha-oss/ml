#!/usr/bin/env node
/* ============================================
   Funnel Validation Script
   Simulates a complete session flow:
     1. Health check
     2. Feature flags load
     3. Experiments load
     4. Event tracking
     5. PIX payment generation
     6. Payment status check
     7. PIX idempotency (duplicate check)

   Usage: node tests/validate-funnel.js [BASE_URL]
   Default BASE_URL: http://localhost:8000
   ============================================ */

const http = require('http');
const https = require('https');

const BASE_URL = process.argv[2] || 'http://localhost:8000';
const SESSION_ID = 'ses_test_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);

let passed = 0;
let failed = 0;
let warnings = 0;
const results = [];

// ── HTTP Helper ──
function request(method, path, body) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const mod = url.protocol === 'https:' ? https : http;

    const opts = {
      hostname: url.hostname,
      port: url.port,
      path: url.pathname + url.search,
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'User-Agent': 'ML-FunnelValidator/1.0'
      },
      timeout: 10000
    };

    const req = mod.request(opts, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, body: JSON.parse(data), raw: data });
        } catch (e) {
          resolve({ status: res.statusCode, body: null, raw: data });
        }
      });
    });

    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Request timeout')); });

    if (body) {
      req.write(JSON.stringify(body));
    }
    req.end();
  });
}

// ── Test runner ──
function check(name, condition, detail) {
  if (condition) {
    passed++;
    results.push({ name, status: 'PASS', detail });
    console.log(`  ✅ ${name}` + (detail ? ` (${detail})` : ''));
  } else {
    failed++;
    results.push({ name, status: 'FAIL', detail });
    console.log(`  ❌ ${name}` + (detail ? ` — ${detail}` : ''));
  }
}

function warn(name, detail) {
  warnings++;
  results.push({ name, status: 'WARN', detail });
  console.log(`  ⚠️  ${name}` + (detail ? ` — ${detail}` : ''));
}

// ══════════════════════════════════
//  TEST SUITES
// ══════════════════════════════════

async function testHealthCheck() {
  console.log('\n── 1. Health Check ──');
  try {
    const res = await request('GET', '/api/health-check.php');
    check('Health check endpoint responds', res.status === 200 || res.status === 503, `HTTP ${res.status}`);
    check('Returns valid JSON', res.body !== null);

    if (res.body) {
      check('Has status field', !!res.body.status, res.body.status);
      check('Has checks object', !!res.body.checks);

      if (res.body.checks) {
        const failedChecks = Object.entries(res.body.checks)
          .filter(([_, v]) => v.status === 'fail' || v.status === 'corrupt')
          .map(([k]) => k);

        if (failedChecks.length > 0) {
          check('All critical checks pass', false, `Failed: ${failedChecks.join(', ')}`);
        } else {
          check('All critical checks pass', true, `${res.body.summary?.ok || '?'} ok`);
        }
      }
    }
  } catch (e) {
    check('Health check reachable', false, e.message);
  }
}

async function testFeatureFlags() {
  console.log('\n── 2. Feature Flags ──');
  try {
    const res = await request('GET', '/api/feature-flags.php');
    check('Feature flags endpoint responds', res.status === 200, `HTTP ${res.status}`);
    check('Returns valid JSON', res.body !== null);

    if (res.body) {
      check('Has global_killswitch field', res.body.global_killswitch !== undefined,
        `killswitch=${res.body.global_killswitch}`);
      check('Has flags object', !!res.body.flags);

      if (res.body.global_killswitch === true) {
        warn('Global killswitch is ON', 'All new features disabled');
      }

      const expectedFlags = ['pix_idempotency', 'timer_fix', 'form_validation', 'qr_code_visible', 'trust_signals', 'ab_engine'];
      const presentFlags = res.body.flags ? Object.keys(res.body.flags) : [];
      const missingFlags = expectedFlags.filter(f => !presentFlags.includes(f));

      check('All expected flags present', missingFlags.length === 0,
        missingFlags.length > 0 ? `Missing: ${missingFlags.join(', ')}` : `${presentFlags.length} flags`);
    }
  } catch (e) {
    check('Feature flags reachable', false, e.message);
  }
}

async function testExperiments() {
  console.log('\n── 3. Experiments ──');
  try {
    const res = await request('GET', '/api/experiments.php?all=1');
    check('Experiments endpoint responds', res.status === 200, `HTTP ${res.status}`);
    check('Returns valid JSON', res.body !== null);

    if (res.body && res.body.experiments) {
      const exps = res.body.experiments;
      const expList = Object.values(exps);
      check('Has experiments defined', expList.length > 0, `${expList.length} experiments`);

      const running = expList.filter(e => e.status === 'running');
      const draft = expList.filter(e => e.status === 'draft');

      if (running.length > 0) {
        console.log(`    ℹ️  ${running.length} running: ${running.map(e => e.name).join(', ')}`);
      }
      if (draft.length > 0) {
        console.log(`    ℹ️  ${draft.length} draft: ${draft.map(e => e.name).join(', ')}`);
      }

      // Verify each experiment has required fields
      let validExps = 0;
      for (const exp of expList) {
        const hasFields = exp.id && exp.name && exp.variants && exp.metric && exp.status;
        if (hasFields) validExps++;
      }
      check('All experiments have required fields', validExps === expList.length,
        `${validExps}/${expList.length} valid`);
    }
  } catch (e) {
    check('Experiments reachable', false, e.message);
  }
}

async function testEventTracking() {
  console.log('\n── 4. Event Tracking ──');
  try {
    const eventPayload = {
      event: 'validate_funnel_test',
      session_id: SESSION_ID,
      page: '/tests/validate-funnel',
      data: { test: true, timestamp: Date.now() },
      experiment_id: 'exp_test',
      variant_id: 'control'
    };

    const res = await request('POST', '/api/event.php', eventPayload);
    check('Event endpoint responds', res.status === 200, `HTTP ${res.status}`);
    check('Event accepted', res.body && res.body.success === true,
      res.body ? (res.body.success ? 'accepted' : res.body.error) : 'no body');
  } catch (e) {
    check('Event tracking reachable', false, e.message);
  }
}

async function testPaymentGeneration() {
  console.log('\n── 5. PIX Payment Generation ──');

  const paymentPayload = {
    customer: {
      email: 'teste-validator@example.com',
      name: 'Teste Validação',
      document: '12345678901',
      phone: '11999999999'
    },
    amount: 9990, // R$99.90 em centavos
    items: [{
      id: 'test_product',
      name: 'Produto Teste Validação',
      price: 9990,
      quantity: 1
    }],
    trackingParameters: {},
    metadata: {
      session_id: SESSION_ID,
      experiment_id: 'exp_test',
      variant_id: 'control',
      frete: 0,
      frete_type: 'gratis',
      cep: '01001000',
      cidade: 'São Paulo',
      uf: 'SP'
    }
  };

  let paymentCode = null;
  let pixQrcode = null;

  try {
    const res = await request('POST', '/api/payment.php', paymentPayload);
    check('Payment endpoint responds', res.status === 200, `HTTP ${res.status}`);

    if (res.body) {
      check('Payment returns success', res.body.success === true,
        res.body.success ? '' : (res.body.error || 'unknown error'));

      if (res.body.success) {
        check('Has payment_code', !!res.body.payment_code, res.body.payment_code);
        check('Has pix_qrcode_text', !!res.body.pix_qrcode_text,
          res.body.pix_qrcode_text ? `${res.body.pix_qrcode_text.length} chars` : 'missing');

        paymentCode = res.body.payment_code;
        pixQrcode = res.body.pix_qrcode_text;

        if (res.body.gateway) {
          console.log(`    ℹ️  Gateway used: ${res.body.gateway}`);
        }
      }
    }
  } catch (e) {
    check('Payment generation reachable', false, e.message);
  }

  return { paymentCode, pixQrcode, paymentPayload };
}

async function testPaymentCheck(paymentCode) {
  console.log('\n── 6. Payment Status Check ──');

  if (!paymentCode) {
    warn('Skipping payment check', 'No payment_code from step 5');
    return;
  }

  try {
    const res = await request('GET', `/api/check-payment.php?code=${encodeURIComponent(paymentCode)}`);
    check('Check-payment endpoint responds', res.status === 200, `HTTP ${res.status}`);
    check('Returns valid JSON', res.body !== null);

    if (res.body) {
      check('Has status field', !!res.body.status, res.body.status);
      // New test payment should be 'pending'
      check('New payment is pending', res.body.status === 'pending',
        `status=${res.body.status}`);
    }
  } catch (e) {
    check('Check-payment reachable', false, e.message);
  }
}

async function testPixIdempotency(paymentPayload) {
  console.log('\n── 7. PIX Idempotency (Server-side) ──');

  if (!paymentPayload) {
    warn('Skipping idempotency test', 'No payload from step 5');
    return;
  }

  try {
    // Send same payment again — should reuse existing
    const res = await request('POST', '/api/payment.php', paymentPayload);
    check('Second payment request responds', res.status === 200, `HTTP ${res.status}`);

    if (res.body && res.body.success) {
      const isReused = res.body.reused === true;
      check('Server-side idempotency works', isReused,
        isReused ? 'Reused existing PIX (no duplicate created)' : 'Created new PIX (idempotency may be disabled)');

      if (!isReused) {
        // Check if pix_idempotency flag is enabled
        try {
          const flagsRes = await request('GET', '/api/feature-flags.php');
          if (flagsRes.body && flagsRes.body.flags && flagsRes.body.flags.pix_idempotency) {
            if (!flagsRes.body.flags.pix_idempotency.enabled) {
              warn('pix_idempotency flag is disabled', 'Idempotency won\'t work until enabled');
            }
          }
        } catch(e) {}
      }
    } else {
      check('Idempotency response valid', false, res.body?.error || 'no success');
    }
  } catch (e) {
    check('Idempotency test reachable', false, e.message);
  }
}

// ══════════════════════════════════
//  MAIN
// ══════════════════════════════════

async function main() {
  console.log('╔══════════════════════════════════════════╗');
  console.log('║   ML Funnel Validation                   ║');
  console.log('╚══════════════════════════════════════════╝');
  console.log(`Target: ${BASE_URL}`);
  console.log(`Session: ${SESSION_ID}`);
  console.log(`Time: ${new Date().toISOString()}`);

  await testHealthCheck();
  await testFeatureFlags();
  await testExperiments();
  await testEventTracking();

  const { paymentCode, pixQrcode, paymentPayload } = await testPaymentGeneration();
  await testPaymentCheck(paymentCode);
  await testPixIdempotency(paymentPayload);

  // ── Summary ──
  console.log('\n══════════════════════════════════════════');
  console.log(`Results: ${passed} passed, ${failed} failed, ${warnings} warnings`);
  console.log('══════════════════════════════════════════');

  if (failed > 0) {
    console.log('\n⛔ VALIDATION FAILED — Fix failures before deploying.');
    const failedTests = results.filter(r => r.status === 'FAIL');
    console.log('\nFailed tests:');
    failedTests.forEach(t => {
      console.log(`  • ${t.name}${t.detail ? ': ' + t.detail : ''}`);
    });
    process.exit(1);
  } else if (warnings > 0) {
    console.log('\n⚠️  VALIDATION PASSED WITH WARNINGS — Review warnings above.');
    process.exit(0);
  } else {
    console.log('\n✅ ALL CHECKS PASSED — Funnel is healthy.');
    process.exit(0);
  }
}

main().catch(err => {
  console.error('\n⛔ Unexpected error:', err.message);
  process.exit(2);
});
