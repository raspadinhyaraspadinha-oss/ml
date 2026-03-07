#!/usr/bin/env python3
"""
PostHog Data Analyzer — Funnel Conversion Analysis
====================================================
Processes a PostHog CSV export and generates a compact JSON report
for conversion bottleneck analysis.

Usage:
    python posthog-analyzer.py <path_to_csv_export>

Output:
    posthog-report.json  (compact analysis file, ~50KB)
"""

import csv
import json
import sys
import os
from collections import defaultdict, Counter
from datetime import datetime, timezone
from urllib.parse import urlparse

# ═══════════════════════════════════════
# CONFIGURATION
# ═══════════════════════════════════════

# Ordered funnel stages
FUNNEL_ORDER = ['vsl', 'questionario', 'roleta', 'recompensas', 'produto', 'checkout', 'upsell']

# Events we track (from ml-analytics.js)
TRACKED_EVENTS = [
    'funnel_view', 'ViewContent', 'AddToCart', 'InitiateCheckout',
    'AddPaymentInfo', 'GeneratePixCode', 'CopyPixCode', 'Purchase',
    'checkout_step', 'form_error'
]

csv.field_size_limit(10 * 1024 * 1024)  # 10MB per field (long URLs)

# ═══════════════════════════════════════
# COLUMN MAPPING — matches real PostHog export format
# ═══════════════════════════════════════

def find_columns(header):
    """Map PostHog CSV columns to internal keys."""
    col_map = {}
    for i, col in enumerate(header):
        c = col.strip()
        cl = c.lower()

        # ── Core fields ──
        if c == 'event':
            col_map['event'] = i
        elif c == 'timestamp':
            col_map['timestamp'] = i

        # ── Person fields (PostHog format: "person_display_name -- Person.X") ──
        elif 'Person.distinct_id' in c or c == 'distinct_id':
            col_map['distinct_id'] = i
        elif 'Person.id' in c:
            col_map['person_id'] = i

        # ── URL (coalesce format) ──
        elif 'Url / Screen' in c or '$current_url' in c or '$screen_name' in c:
            col_map['current_url'] = i

        # ── Our custom properties ──
        elif c == 'properties.ml_session_id':
            col_map['ml_session_id'] = i
        elif c == 'properties.session_id':
            col_map['session_id'] = i
        elif c == 'properties.funnel_stage':
            col_map['funnel_stage'] = i
        elif c == 'properties.page':
            col_map['page'] = i
        elif c == 'properties.product_id':
            col_map['product_id'] = i
        elif c == 'properties.product_name':
            col_map['product_name'] = i
        elif c == 'properties.payment_code':
            col_map['payment_code'] = i
        elif c == 'properties.value':
            col_map['value'] = i
        elif c == 'properties.step':
            col_map['step'] = i
        elif c == 'properties.step_name':
            col_map['step_name'] = i
        elif c == 'properties.event_id':
            col_map['event_id'] = i

        # ── UTMs ──
        elif c == 'properties.utm_source':
            col_map['utm_source'] = i
        elif c == 'properties.utm_campaign':
            col_map['utm_campaign'] = i
        elif c == 'properties.utm_medium':
            col_map['utm_medium'] = i
        elif c == 'properties.utm_content':
            col_map['utm_content'] = i

        # ── Device/Browser/OS ──
        elif c == 'properties.$browser':
            col_map['browser'] = i
        elif c == 'properties.$os':
            col_map['os'] = i
        elif c == 'properties.$device_type':
            col_map['device_type'] = i

        # ── Geography ──
        elif c == 'properties.$geoip_subdivision_1_name':
            col_map['state_name'] = i
        elif c == 'properties.$geoip_subdivision_1_code':
            col_map['state_code'] = i
        elif c == 'properties.$geoip_country_code':
            col_map['country_code'] = i
        elif c == 'properties.$geoip_city_name':
            col_map['city'] = i

        # ── Extra properties (if present in future exports) ──
        elif c == 'properties.$referring_domain':
            col_map['referring_domain'] = i
        elif c == 'properties.num_items':
            col_map['num_items'] = i
        elif c == 'properties.field':
            col_map['field'] = i
        elif c == 'properties.message':
            col_map['message'] = i
        elif c == 'properties.content_ids':
            col_map['content_ids'] = i

    return col_map


def get_val(row, col_map, key):
    """Safely get a value from a row."""
    if key not in col_map:
        return ''
    idx = col_map[key]
    if idx >= len(row):
        return ''
    return row[idx].strip()


def get_session_id(row, col_map):
    """Get session ID — prefer ml_session_id, fall back to session_id."""
    sid = get_val(row, col_map, 'ml_session_id')
    if not sid:
        sid = get_val(row, col_map, 'session_id')
    return sid


def extract_pathname(url):
    """Extract pathname from full URL."""
    if not url:
        return ''
    try:
        parsed = urlparse(url)
        return parsed.path or '/'
    except Exception:
        return ''


def classify_page_from_properties(page, funnel_stage, url):
    """Determine funnel stage from properties.page, funnel_stage, or URL."""
    # 1) Direct from properties.page (most reliable — set by ml-analytics.js)
    if page:
        p = page.lower().strip()
        if p in FUNNEL_ORDER:
            return p
        if p.startswith('produto:') or p.startswith('produto'):
            return 'produto'
        # Map alternative names
        aliases = {
            'prevsl': 'vsl', 'vsl': 'vsl',
            'questionario': 'questionario',
            'roleta': 'roleta',
            'recompensas': 'recompensas',
            'checkout': 'checkout',
            'upsell': 'upsell', 'up': 'upsell',
        }
        if p in aliases:
            return aliases[p]

    # 2) From funnel_stage number
    if funnel_stage:
        try:
            stage_num = int(float(funnel_stage))
            stage_map = {1: 'vsl', 2: 'vsl', 3: 'questionario', 4: 'roleta',
                         5: 'recompensas', 6: 'produto', 7: 'checkout', 8: 'upsell'}
            if stage_num in stage_map:
                return stage_map[stage_num]
        except (ValueError, TypeError):
            pass

    # 3) From URL pathname
    if url:
        path = extract_pathname(url).lower().rstrip('/')
        if '/vsl' in path or '/prevsl' in path:
            return 'vsl'
        if '/questionario' in path:
            return 'questionario'
        if '/roleta' in path:
            return 'roleta'
        if '/recompensas' in path:
            return 'recompensas'
        if '/produtos/' in path:
            return 'produto'
        if '/checkout' in path:
            return 'checkout'
        if '/up' in path and '/up' == path[-3:]:
            return 'upsell'

    return None


def parse_timestamp(ts_str):
    """Parse PostHog timestamp string to datetime."""
    if not ts_str:
        return None
    try:
        ts_str = ts_str.strip()
        # Format: 2026-03-07 05:08:26.182000+00:00
        for fmt in [
            '%Y-%m-%d %H:%M:%S.%f%z',
            '%Y-%m-%d %H:%M:%S%z',
            '%Y-%m-%dT%H:%M:%S.%f%z',
            '%Y-%m-%dT%H:%M:%S%z',
            '%Y-%m-%d %H:%M:%S.%f',
            '%Y-%m-%d %H:%M:%S',
        ]:
            try:
                return datetime.strptime(ts_str, fmt)
            except ValueError:
                continue
        # Last resort: just date part
        return datetime.strptime(ts_str[:19], '%Y-%m-%d %H:%M:%S')
    except Exception:
        return None


def extract_product_from_url(url):
    """Extract product name from URL like /produtos/camiseta-ml/"""
    if not url:
        return None
    path = extract_pathname(url).lower()
    if '/produtos/' in path:
        parts = path.split('/produtos/')
        if len(parts) > 1:
            name = parts[1].strip('/').split('/')[0]
            if name and name != 'index.html':
                return name
    return None


# ═══════════════════════════════════════
# MAIN ANALYSIS
# ═══════════════════════════════════════

def analyze(csv_path):
    print(f"\n{'='*60}")
    print(f"  PostHog Funnel Analyzer v2.0")
    print(f"  File: {csv_path}")
    print(f"  Size: {os.path.getsize(csv_path) / (1024*1024):.1f} MB")
    print(f"{'='*60}\n")

    # ── Accumulators ──
    total_rows = 0
    skipped_rows = 0

    # Event counts
    event_counts = Counter()

    # Funnel: unique persons per stage
    users_per_stage = defaultdict(set)       # stage -> set of distinct_ids
    sessions_per_stage = defaultdict(set)    # stage -> set of session_ids

    # Session tracking
    session_stages = defaultdict(set)        # session_id -> set of stages
    session_utm = {}                         # session_id -> {source, campaign, medium, content}
    session_device = {}                      # session_id -> device_type
    session_first_ts = {}                    # session_id -> first timestamp
    session_last_ts = {}                     # session_id -> last timestamp
    session_events = defaultdict(list)       # session_id -> list of (timestamp, event, stage)

    # UTM tracking
    utm_source_sessions = defaultdict(set)
    utm_campaign_sessions = defaultdict(set)

    # Device breakdown
    device_counts = Counter()
    browser_counts = Counter()
    os_counts = Counter()

    # Geographic
    state_counts = Counter()

    # Hourly pattern (BRT = UTC-3)
    hourly_events = Counter()
    hourly_sessions = defaultdict(set)

    # Daily pattern
    daily_events = Counter()
    daily_sessions = defaultdict(set)

    # Checkout step tracking
    checkout_steps = Counter()
    checkout_step_sessions = defaultdict(set)

    # Product tracking
    product_sessions = defaultdict(set)     # product_name -> set of session_ids
    product_event_counts = defaultdict(Counter)  # product_name -> {event -> count}

    # PIX tracking
    pix_generated_sessions = set()
    pix_copied_sessions = set()
    pix_confirmed_sessions = set()          # Purchase = confirmed

    # Payment tracking
    payment_values = []                      # list of amounts
    payment_sessions = set()

    # Form errors
    form_errors = Counter()                  # field -> count

    # ── Process CSV ──
    print("Processing CSV...")

    with open(csv_path, 'r', encoding='utf-8', errors='replace') as f:
        reader = csv.reader(f)
        header = next(reader)
        col_map = find_columns(header)

        print(f"  CSV columns: {len(header)}")
        print(f"  Mapped keys: {sorted(col_map.keys())}")
        mapped_critical = ['event', 'timestamp', 'session_id' if 'session_id' in col_map else 'ml_session_id', 'page', 'utm_source']
        print(f"  Critical: event={'event' in col_map}, timestamp={'timestamp' in col_map}, "
              f"session={'session_id' in col_map or 'ml_session_id' in col_map}, "
              f"page={'page' in col_map}, utm_source={'utm_source' in col_map}")
        print()

        for row in reader:
            total_rows += 1

            if total_rows % 50000 == 0:
                print(f"  {total_rows:,} rows...")

            try:
                event = get_val(row, col_map, 'event')
                if not event:
                    skipped_rows += 1
                    continue

                distinct_id = get_val(row, col_map, 'distinct_id')
                session_id = get_session_id(row, col_map)
                page = get_val(row, col_map, 'page')
                funnel_stage = get_val(row, col_map, 'funnel_stage')
                url = get_val(row, col_map, 'current_url')
                device_type = get_val(row, col_map, 'device_type')
                browser = get_val(row, col_map, 'browser')
                os_name = get_val(row, col_map, 'os')
                utm_source = get_val(row, col_map, 'utm_source')
                utm_campaign = get_val(row, col_map, 'utm_campaign')
                utm_medium = get_val(row, col_map, 'utm_medium')
                utm_content = get_val(row, col_map, 'utm_content')
                state_name = get_val(row, col_map, 'state_name')
                timestamp_str = get_val(row, col_map, 'timestamp')
                product_name = get_val(row, col_map, 'product_name')
                step_name = get_val(row, col_map, 'step_name')
                step = get_val(row, col_map, 'step')
                payment_code = get_val(row, col_map, 'payment_code')
                value_str = get_val(row, col_map, 'value')

                # Parse timestamp
                ts = parse_timestamp(timestamp_str)

                # Apply BRT offset (UTC-3) for hourly analysis
                ts_brt = None
                if ts:
                    from datetime import timedelta
                    ts_brt = ts - timedelta(hours=3) if ts.tzinfo else ts

                # ── Count events ──
                event_counts[event] += 1

                # ── Classify funnel stage ──
                stage = classify_page_from_properties(page, funnel_stage, url)

                # Also infer stage from event name
                if not stage:
                    event_stage_map = {
                        'ViewContent': 'produto',
                        'AddToCart': 'produto',
                        'InitiateCheckout': 'checkout',
                        'AddPaymentInfo': 'checkout',
                        'GeneratePixCode': 'checkout',
                        'CopyPixCode': 'checkout',
                        'Purchase': 'checkout',
                    }
                    stage = event_stage_map.get(event)

                if stage and stage in FUNNEL_ORDER:
                    if distinct_id:
                        users_per_stage[stage].add(distinct_id)
                    if session_id:
                        sessions_per_stage[stage].add(session_id)
                        session_stages[session_id].add(stage)

                # ── Session tracking ──
                if session_id:
                    if utm_source and session_id not in session_utm:
                        session_utm[session_id] = {
                            'source': utm_source,
                            'campaign': utm_campaign,
                            'medium': utm_medium,
                            'content': utm_content,
                        }
                    if device_type and session_id not in session_device:
                        session_device[session_id] = device_type

                    if ts:
                        if session_id not in session_first_ts or ts < session_first_ts[session_id]:
                            session_first_ts[session_id] = ts
                        if session_id not in session_last_ts or ts > session_last_ts[session_id]:
                            session_last_ts[session_id] = ts

                    # Track event sequence per session
                    session_events[session_id].append((timestamp_str, event, stage or ''))

                # ── UTM aggregation ──
                if utm_source and session_id:
                    utm_source_sessions[utm_source].add(session_id)
                if utm_campaign and session_id:
                    utm_campaign_sessions[utm_campaign].add(session_id)

                # ── Device/Browser/OS ──
                if device_type:
                    device_counts[device_type] += 1
                if browser:
                    browser_counts[browser] += 1
                if os_name:
                    os_counts[os_name] += 1

                # ── Geography ──
                if state_name:
                    state_counts[state_name] += 1

                # ── Hourly/Daily patterns (BRT) ──
                if ts_brt:
                    hour = ts_brt.hour
                    hourly_events[hour] += 1
                    if session_id:
                        hourly_sessions[hour].add(session_id)

                    day = ts_brt.strftime('%Y-%m-%d')
                    daily_events[day] += 1
                    if session_id:
                        daily_sessions[day].add(session_id)

                # ── Checkout steps ──
                if event == 'checkout_step':
                    sn = step_name or f'step_{step}'
                    checkout_steps[sn] += 1
                    if session_id:
                        checkout_step_sessions[sn].add(session_id)

                # ── Products ──
                pname = product_name or extract_product_from_url(url)
                if pname and event in ('ViewContent', 'AddToCart', 'funnel_view'):
                    product_event_counts[pname][event] += 1
                    if session_id:
                        product_sessions[pname].add(session_id)

                # ── PIX tracking ──
                if event == 'GeneratePixCode' and session_id:
                    pix_generated_sessions.add(session_id)
                if event == 'CopyPixCode' and session_id:
                    pix_copied_sessions.add(session_id)
                if event == 'Purchase' and session_id:
                    pix_confirmed_sessions.add(session_id)
                    payment_sessions.add(session_id)
                    if value_str:
                        try:
                            val = float(value_str)
                            if val > 0:
                                payment_values.append(val)
                        except (ValueError, TypeError):
                            pass

                # ── Form errors ──
                if event == 'form_error':
                    field = get_val(row, col_map, 'field') or get_val(row, col_map, 'message') or 'unknown'
                    form_errors[field] += 1

            except Exception as e:
                skipped_rows += 1
                if skipped_rows <= 5:
                    print(f"  Warning row {total_rows}: {e}")
                continue

    print(f"\n  Total rows: {total_rows:,}")
    print(f"  Skipped: {skipped_rows:,}")
    print(f"  Unique sessions: {len(session_stages):,}")

    # ═══════════════════════════════════════
    # BUILD REPORT
    # ═══════════════════════════════════════

    print("\nBuilding report...")

    all_users = set()
    for s in users_per_stage.values():
        all_users |= s

    report = {
        'meta': {
            'file': os.path.basename(csv_path),
            'file_size_mb': round(os.path.getsize(csv_path) / (1024*1024), 1),
            'total_rows': total_rows,
            'skipped_rows': skipped_rows,
            'unique_users': len(all_users),
            'unique_sessions': len(session_stages),
            'date_range': {
                'min': min(daily_events.keys()) if daily_events else None,
                'max': max(daily_events.keys()) if daily_events else None,
            },
            'generated_at': datetime.now().isoformat(),
        },
    }

    # ── 1. EVENT COUNTS ──
    report['event_counts'] = dict(event_counts.most_common(50))

    # ── 2. FUNNEL (main metric) ──
    funnel_data = {}
    first_stage_sessions = len(sessions_per_stage.get(FUNNEL_ORDER[0], set()))
    prev_sessions = None
    for stage in FUNNEL_ORDER:
        sessions = len(sessions_per_stage.get(stage, set()))
        users = len(users_per_stage.get(stage, set()))

        entry = {
            'unique_users': users,
            'unique_sessions': sessions,
        }

        if prev_sessions is not None and prev_sessions > 0:
            entry['drop_from_prev_pct'] = round((1 - sessions / prev_sessions) * 100, 1)
            entry['conv_from_prev_pct'] = round(sessions / prev_sessions * 100, 1)

        if first_stage_sessions > 0:
            entry['conv_from_vsl_pct'] = round(sessions / first_stage_sessions * 100, 2)

        funnel_data[stage] = entry
        prev_sessions = sessions

    report['funnel'] = funnel_data

    # ── 3. SESSION DEPTH ──
    depth_counter = Counter()
    for sid, stages in session_stages.items():
        max_idx = 0
        for s in stages:
            if s in FUNNEL_ORDER:
                idx = FUNNEL_ORDER.index(s)
                max_idx = max(max_idx, idx)
        depth_counter[FUNNEL_ORDER[max_idx]] += 1

    report['session_depth'] = dict(depth_counter.most_common())

    # ── 4. UTM SOURCE FUNNEL ──
    utm_source_funnel = {}
    for source, sids in sorted(utm_source_sessions.items(), key=lambda x: -len(x[1]))[:20]:
        sf = {}
        for stage in FUNNEL_ORDER:
            sf[stage] = len(sids & sessions_per_stage.get(stage, set()))
        total = len(sids)
        vsl = sf.get('vsl', total)
        checkout = sf.get('checkout', 0)
        utm_source_funnel[source] = {
            'total_sessions': total,
            'funnel': sf,
            'vsl_to_checkout_pct': round(checkout / vsl * 100, 2) if vsl > 0 else 0,
        }
    report['utm_by_source'] = utm_source_funnel

    # ── 5. TOP CAMPAIGNS ──
    campaign_data = {}
    for campaign, sids in sorted(utm_campaign_sessions.items(), key=lambda x: -len(x[1]))[:30]:
        if not campaign:
            continue
        total = len(sids)
        funnel = {}
        for stage in FUNNEL_ORDER:
            funnel[stage] = len(sids & sessions_per_stage.get(stage, set()))
        checkout = funnel.get('checkout', 0)
        purchase = len(sids & pix_confirmed_sessions)
        campaign_data[campaign] = {
            'sessions': total,
            'funnel': funnel,
            'checkout_rate_pct': round(checkout / total * 100, 2) if total > 0 else 0,
            'purchase_rate_pct': round(purchase / total * 100, 2) if total > 0 else 0,
        }
    report['utm_by_campaign'] = campaign_data

    # ── 6. DEVICES ──
    report['devices'] = {
        'type': dict(device_counts.most_common(10)),
        'browser': dict(browser_counts.most_common(10)),
        'os': dict(os_counts.most_common(10)),
    }

    # ── 7. DEVICE FUNNEL (conversion by device) ──
    device_funnel = {}
    for device in set(session_device.values()):
        sids = {s for s, d in session_device.items() if d == device}
        if len(sids) < 5:
            continue
        df = {}
        for stage in FUNNEL_ORDER:
            df[stage] = len(sids & sessions_per_stage.get(stage, set()))
        vsl = df.get('vsl', len(sids))
        checkout = df.get('checkout', 0)
        device_funnel[device] = {
            'sessions': len(sids),
            'funnel': df,
            'vsl_to_checkout_pct': round(checkout / vsl * 100, 2) if vsl > 0 else 0,
        }
    report['device_funnel'] = device_funnel

    # ── 8. GEOGRAPHY ──
    report['geography_states'] = dict(state_counts.most_common(27))

    # ── 9. HOURLY PATTERNS (BRT) ──
    hourly = {}
    for hour in range(24):
        hourly[f'{hour:02d}:00'] = {
            'events': hourly_events.get(hour, 0),
            'sessions': len(hourly_sessions.get(hour, set())),
        }
    report['hourly_brt'] = hourly

    # ── 10. DAILY PATTERNS ──
    daily = {}
    for day in sorted(daily_events.keys()):
        daily[day] = {
            'events': daily_events[day],
            'sessions': len(daily_sessions.get(day, set())),
        }
    report['daily'] = daily

    # ── 11. CHECKOUT STEPS ──
    checkout_data = {}
    for sn in sorted(checkout_step_sessions.keys(), key=lambda x: -len(checkout_step_sessions[x])):
        checkout_data[sn] = {
            'events': checkout_steps.get(sn, 0),
            'unique_sessions': len(checkout_step_sessions[sn]),
        }
    report['checkout_steps'] = checkout_data

    # ── 12. PRODUCTS ──
    products = {}
    for pname in sorted(product_sessions.keys(), key=lambda x: -len(product_sessions[x])):
        products[pname] = {
            'sessions': len(product_sessions[pname]),
            'events': dict(product_event_counts[pname]),
        }
    report['products'] = products

    # ── 13. PIX CONVERSION ──
    report['pix'] = {
        'generated': len(pix_generated_sessions),
        'copied': len(pix_copied_sessions),
        'confirmed_purchase': len(pix_confirmed_sessions),
        'copy_rate_pct': round(len(pix_copied_sessions) / len(pix_generated_sessions) * 100, 1) if pix_generated_sessions else 0,
        'purchase_rate_pct': round(len(pix_confirmed_sessions) / len(pix_generated_sessions) * 100, 1) if pix_generated_sessions else 0,
    }

    # ── 14. PAYMENT VALUES ──
    if payment_values:
        payment_values.sort()
        report['payments'] = {
            'count': len(payment_values),
            'total_brl': round(sum(payment_values), 2),
            'avg_brl': round(sum(payment_values) / len(payment_values), 2),
            'median_brl': round(payment_values[len(payment_values)//2], 2),
            'min_brl': round(payment_values[0], 2),
            'max_brl': round(payment_values[-1], 2),
        }

    # ── 15. FORM ERRORS ──
    if form_errors:
        report['form_errors'] = dict(form_errors.most_common(20))

    # ── 16. SESSION DURATION ──
    durations = []
    for sid in session_stages:
        if sid in session_first_ts and sid in session_last_ts:
            delta = (session_last_ts[sid] - session_first_ts[sid]).total_seconds()
            if 0 < delta < 3600:
                durations.append(delta)

    if durations:
        durations.sort()
        report['session_duration'] = {
            'avg_seconds': round(sum(durations) / len(durations), 1),
            'median_seconds': round(durations[len(durations)//2], 1),
            'p90_seconds': round(durations[int(len(durations)*0.9)], 1),
            'sessions_measured': len(durations),
        }

    # ── 17. CONVERSION BOTTLENECK SUMMARY ──
    # Identify the biggest drop-off
    bottlenecks = []
    for i in range(1, len(FUNNEL_ORDER)):
        prev_s = FUNNEL_ORDER[i-1]
        curr_s = FUNNEL_ORDER[i]
        prev_count = len(sessions_per_stage.get(prev_s, set()))
        curr_count = len(sessions_per_stage.get(curr_s, set()))
        if prev_count > 0:
            drop_pct = round((1 - curr_count / prev_count) * 100, 1)
            lost = prev_count - curr_count
            bottlenecks.append({
                'from': prev_s,
                'to': curr_s,
                'drop_pct': drop_pct,
                'sessions_lost': lost,
            })

    bottlenecks.sort(key=lambda x: -x['sessions_lost'])
    report['bottlenecks'] = bottlenecks

    return report


# ═══════════════════════════════════════
# ENTRY POINT
# ═══════════════════════════════════════

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python posthog-analyzer.py <path_to_csv_export>")
        sys.exit(1)

    csv_path = sys.argv[1]

    if not os.path.exists(csv_path):
        print(f"Error: File not found: {csv_path}")
        sys.exit(1)

    report = analyze(csv_path)

    # Save report
    output_dir = os.path.dirname(csv_path) or '.'
    output_path = os.path.join(output_dir, 'posthog-report.json')

    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False, default=str)

    output_size = os.path.getsize(output_path) / 1024
    print(f"\n{'='*60}")
    print(f"  Report saved: {output_path}")
    print(f"  Report size: {output_size:.1f} KB")
    print(f"{'='*60}")

    # Print summary
    print(f"\n RESUMO:")
    print(f"  Total eventos: {report['meta']['total_rows']:,}")
    print(f"  Usuarios unicos: {report['meta']['unique_users']:,}")
    print(f"  Sessoes unicas: {report['meta']['unique_sessions']:,}")
    print(f"  Periodo: {report['meta']['date_range']['min']} a {report['meta']['date_range']['max']}")

    if report['funnel']:
        print(f"\n FUNIL:")
        for stage in FUNNEL_ORDER:
            if stage in report['funnel']:
                d = report['funnel'][stage]
                drop = f"  (drop {d['drop_from_prev_pct']}%)" if 'drop_from_prev_pct' in d else ''
                print(f"  {stage:15s}  {d['unique_sessions']:>8,} sessoes{drop}")

    if report.get('pix'):
        p = report['pix']
        print(f"\n PIX:")
        print(f"  Gerados: {p['generated']}  |  Copiados: {p['copied']}  |  Pagos: {p['confirmed_purchase']}")
        print(f"  Taxa copia: {p['copy_rate_pct']}%  |  Taxa pagamento: {p['purchase_rate_pct']}%")

    if report.get('bottlenecks'):
        print(f"\n MAIORES GARGALOS:")
        for b in report['bottlenecks'][:3]:
            print(f"  {b['from']} -> {b['to']}: {b['drop_pct']}% drop ({b['sessions_lost']:,} sessoes perdidas)")

    print(f"\n Envie o arquivo '{os.path.basename(output_path)}' para analise.")
