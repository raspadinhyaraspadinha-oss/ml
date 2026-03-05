<?php
/**
 * CLOAKER v3.0 — Anti-bot + GeoIP + Rate Limiting
 *
 * Otimizado para Hostinger (40 PHP workers):
 * - GeoLite2 local (ZERO cURL, ZERO external API)
 * - Rate limiting via APCu (ou fallback arquivo leve)
 * - Bot detection agressiva mas preserva ads crawlers
 * - Tempo de execução: <5ms (vs ~3000ms do cURL antigo)
 */

// ====================== CONFIGURAÇÕES ======================
$offer_url         = 'https://www.aniversariodomes.shop/vsl/';
$white_message     = 'Site em manutenção. Tente novamente em alguns instantes.';
$require_mobile    = false;       // false = libera mobile + desktop
$block_non_br      = false;       // false = libera todos os países (frouxo)
                                  // true = bloqueia IPs que COM CERTEZA não são BR
$rate_limit_max    = 30;          // máx requests por IP em 60 segundos
$rate_limit_window = 60;          // janela em segundos
// =============================================================

// ── IP real ──
function get_real_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Bot detection (agressiva mas preserva ad crawlers) ──
function detect_bot() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Sem UA ou UA muito curta = bot
    if (empty($ua) || strlen($ua) < 20) return 'empty_ua';

    $ua_lower = strtolower($ua);

    // PERMITIR crawlers de ads (essenciais para tracking)
    $ad_bots = ['facebookexternalhit', 'facebot', 'whatsapp', 'twitterbot',
                'telegrambot', 'linkedinbot', 'pinterestbot', 'slackbot',
                'discordbot', 'snapchat'];
    foreach ($ad_bots as $ab) {
        if (strpos($ua_lower, $ab) !== false) return false; // não é bot hostil
    }

    // BLOQUEAR bots conhecidos hostis
    $bad_bots = ['bot','crawl','spider','slurp','ahrefs','semrush','mj12','dotbot',
                 'blexbot','petalbot','yandex','bytespider','gptbot','claudebot','ccbot',
                 'dataforseo','sogou','baidu','megaindex','ltx71','nuclei','sqlmap',
                 'nikto','masscan','zmeu','zgrab','hakai','tsunami','loic','w3af',
                 'python-requests','python-urllib','java/','libwww','perl','ruby',
                 'go-http','httpx','axios','node-fetch','scrapy','phantomjs',
                 'headlesschrome','puppeteer','selenium','playwright','httrack',
                 'wget','curl/','mechanize','winhttp'];
    foreach ($bad_bots as $bb) {
        if (strpos($ua_lower, $bb) !== false) return $bb;
    }

    // UA sem parênteses (browsers reais sempre têm) = suspeito
    if (strpos($ua, '(') === false) return 'no_parens';

    // UA com formato "Mozilla/5.0" mas sem dados de plataforma = fake
    if (preg_match('/^Mozilla\/5\.0\s*$/', $ua)) return 'fake_mozilla';

    return false;
}

// ── Rate limiting (APCu > arquivo) ──
function check_rate_limit($ip, $max, $window) {
    $key = 'rl_' . $ip;

    // Tentar APCu primeiro (in-memory, ultra rápido, sem I/O)
    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($key);
        if ($count === false) {
            apcu_store($key, 1, $window);
            return ['allowed' => true, 'count' => 1];
        }
        $count = apcu_inc($key);
        return ['allowed' => $count <= $max, 'count' => $count];
    }

    // Fallback: arquivo leve (1 arquivo compartilhado, não 1 por IP)
    $file = sys_get_temp_dir() . '/ml_ratelimit.json';
    $now = time();
    $data = [];

    if (file_exists($file) && (filemtime($file) > ($now - $window * 2))) {
        $raw = @file_get_contents($file);
        if ($raw) $data = @json_decode($raw, true) ?: [];
    }

    // Limpar entradas expiradas (a cada ~100 requests)
    if (rand(1, 100) === 1) {
        foreach ($data as $k => $v) {
            if ($v['t'] < ($now - $window)) unset($data[$k]);
        }
    }

    $entry = $data[$ip] ?? null;
    if (!$entry || $entry['t'] < ($now - $window)) {
        $data[$ip] = ['c' => 1, 't' => $now];
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return ['allowed' => true, 'count' => 1];
    }

    $data[$ip]['c']++;
    $count = $data[$ip]['c'];
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return ['allowed' => $count <= $max, 'count' => $count];
}

// ── GeoIP com GeoLite2 (ZERO network calls) ──
// Carrega o arquivo INTEIRO em memória (~6MB) para seguir ponteiros corretamente
function get_country_geolite2($ip) {
    // 1) Tentar extensão oficial MaxMind (mais rápida, se disponível)
    if (class_exists('MaxMind\\Db\\Reader')) {
        try {
            $reader = new \MaxMind\Db\Reader(__DIR__ . '/GeoLite2-Country.mmdb');
            $record = $reader->get($ip);
            $reader->close();
            return $record['country']['iso_code'] ?? 'XX';
        } catch (\Throwable $e) { /* fallback abaixo */ }
    }

    // 2) Pure PHP reader (sem dependências)
    $dbFile = __DIR__ . '/GeoLite2-Country.mmdb';
    if (!file_exists($dbFile)) return 'XX';

    return mmdb_country_lookup($dbFile, $ip);
}

/**
 * Pure PHP MaxMind MMDB Country reader v2 — segue ponteiros corretamente
 * Carrega data section inteira em memória para resolver pointers
 */
function mmdb_country_lookup($dbFile, $ip) {
    try {
        $raw = @file_get_contents($dbFile);
        if (!$raw) return 'XX';

        // Encontrar metadata marker
        $marker = "\xAB\xCD\xEFMaxMind.com";
        $metaPos = strrpos($raw, $marker);
        if ($metaPos === false) return 'XX';

        $metaData = substr($raw, $metaPos + strlen($marker));
        $meta = mmdb_dec($metaData, 0, $metaData);
        if (!$meta) return 'XX';
        $metadata = $meta[0];

        $nodeCount  = $metadata['node_count'] ?? 0;
        $recordSize = $metadata['record_size'] ?? 0;
        $nodeBytes  = (int)(($recordSize * 2) / 8);
        $treeSize   = $nodeCount * $nodeBytes;
        $dataStart  = $treeSize + 16; // 16 = data section separator

        // Data section para resolver ponteiros
        $dataSection = substr($raw, $dataStart);

        // Converter IP para bits
        $packed = @inet_pton($ip);
        if (!$packed) return 'XX';
        $ipBits = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $ipBits .= str_pad(decbin(ord($packed[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bitCount = strlen($packed) === 16 ? 128 : 32;

        // Se DB IPv6 mas IP IPv4, pular 96 bits de zeros
        $ipVersion = $metadata['ip_version'] ?? 4;
        $node = 0;
        if ($ipVersion === 6 && $bitCount === 32) {
            for ($i = 0; $i < 96; $i++) {
                if ($node >= $nodeCount) break;
                $rec = mmdb_read_rec($raw, $node, $recordSize, $nodeBytes);
                if (!$rec) return 'XX';
                $node = $rec[0]; // bit 0 = left
            }
            if ($node >= $nodeCount && $node !== $nodeCount) {
                // Já encontrou resultado nos 96 bits de zeros
                $offset = $node - $nodeCount - 16;
                $decoded = mmdb_dec($dataSection, $offset, $dataSection);
                if ($decoded && isset($decoded[0]['country']['iso_code'])) {
                    return $decoded[0]['country']['iso_code'];
                }
                return 'XX';
            }
        }

        // Percorrer search tree bit a bit
        for ($i = 0; $i < $bitCount; $i++) {
            if ($node >= $nodeCount) break;
            $bit = (int)($ipBits[$i] ?? 0);
            $rec = mmdb_read_rec($raw, $node, $recordSize, $nodeBytes);
            if (!$rec) return 'XX';
            $node = $rec[$bit];
        }

        if ($node === $nodeCount) return 'XX'; // IP não encontrado
        if ($node <= $nodeCount) return 'XX';   // Ainda na árvore

        // Decodificar data record
        $offset = $node - $nodeCount - 16;
        if ($offset < 0 || $offset >= strlen($dataSection)) return 'XX';
        $decoded = mmdb_dec($dataSection, $offset, $dataSection);
        if ($decoded && isset($decoded[0]['country']['iso_code'])) {
            return $decoded[0]['country']['iso_code'];
        }
        return 'XX';

    } catch (\Throwable $e) {
        return 'XX';
    }
}

function mmdb_read_rec($raw, $node, $recordSize, $nodeBytes) {
    $off = $node * $nodeBytes;
    if ($off + $nodeBytes > strlen($raw)) return null;
    $chunk = substr($raw, $off, $nodeBytes);

    if ($recordSize === 24) {
        return [
            unpack('N', "\x00" . substr($chunk, 0, 3))[1],
            unpack('N', "\x00" . substr($chunk, 3, 3))[1]
        ];
    }
    if ($recordSize === 28) {
        $m = ord($chunk[3]);
        return [
            (($m >> 4) << 24) | unpack('N', "\x00" . substr($chunk, 0, 3))[1],
            (($m & 0x0F) << 24) | unpack('N', "\x00" . substr($chunk, 4, 3))[1]
        ];
    }
    if ($recordSize === 32) {
        return [
            unpack('N', substr($chunk, 0, 4))[1],
            unpack('N', substr($chunk, 4, 4))[1]
        ];
    }
    return null;
}

/**
 * MMDB data decoder — segue ponteiros via $fullData
 * $data = buffer atual, $offset = posição, $fullData = data section completa
 */
function mmdb_dec($data, $offset, $fullData) {
    if ($offset >= strlen($data)) return null;
    $ctrlByte = ord($data[$offset]);
    $type = $ctrlByte >> 5;
    $off = $offset + 1;

    if ($type === 0) {
        if ($off >= strlen($data)) return null;
        $type = ord($data[$off]) + 7;
        $off++;
    }

    // Calcular tamanho
    $size = $ctrlByte & 0x1F;
    if ($type !== 1) { // ponteiros não usam size normal
        if ($size === 29 && $off < strlen($data)) {
            $size = 29 + ord($data[$off]); $off++;
        } elseif ($size === 30 && $off + 1 < strlen($data)) {
            $size = 285 + (ord($data[$off]) << 8) + ord($data[$off + 1]); $off += 2;
        } elseif ($size === 31 && $off + 2 < strlen($data)) {
            $size = 65821 + (ord($data[$off]) << 16) + (ord($data[$off + 1]) << 8) + ord($data[$off + 2]); $off += 3;
        }
    }

    switch ($type) {
        case 1: // POINTER — segue o ponteiro na data section
            $pSize = ($ctrlByte >> 3) & 0x03;
            $pointer = 0;
            if ($pSize === 0) {
                $pointer = (($ctrlByte & 0x07) << 8) + ord($data[$off]);
                $off++;
            } elseif ($pSize === 1 && $off + 1 < strlen($data)) {
                $pointer = 2048 + (($ctrlByte & 0x07) << 16) + (ord($data[$off]) << 8) + ord($data[$off + 1]);
                $off += 2;
            } elseif ($pSize === 2 && $off + 2 < strlen($data)) {
                $pointer = 526336 + (($ctrlByte & 0x07) << 24) + (ord($data[$off]) << 16) + (ord($data[$off + 1]) << 8) + ord($data[$off + 2]);
                $off += 3;
            } elseif ($off + 3 < strlen($data)) {
                $pointer = unpack('N', substr($data, $off, 4))[1];
                $off += 4;
            }
            // SEGUIR o ponteiro na data section completa
            $pointed = mmdb_dec($fullData, $pointer, $fullData);
            $val = $pointed ? $pointed[0] : null;
            return [$val, $off]; // offset avança no buffer ATUAL (não no ponteiro)

        case 2: // UTF-8 string
            return [substr($data, $off, $size), $off + $size];

        case 3: // double
            if ($off + 8 > strlen($data)) return [0.0, $off];
            return [unpack('E', substr($data, $off, 8))[1], $off + 8];

        case 5: // unsigned 16
            $v = 0;
            for ($i = 0; $i < $size; $i++) $v = ($v << 8) + ord($data[$off + $i]);
            return [$v, $off + $size];

        case 6: // unsigned 32
            $v = 0;
            for ($i = 0; $i < $size; $i++) $v = ($v << 8) + ord($data[$off + $i]);
            return [$v, $off + $size];

        case 7: // map
            $map = [];
            $p = $off;
            for ($i = 0; $i < $size; $i++) {
                $kr = mmdb_dec($data, $p, $fullData);
                if (!$kr) return [$map, $p];
                $p = $kr[1];
                $vr = mmdb_dec($data, $p, $fullData);
                if (!$vr) return [$map, $p];
                $map[$kr[0]] = $vr[0];
                $p = $vr[1];
            }
            return [$map, $p];

        case 8: // signed 32
            $v = 0;
            for ($i = 0; $i < $size; $i++) $v = ($v << 8) + ord($data[$off + $i]);
            if ($size === 4 && $v >= 0x80000000) $v -= 0x100000000;
            return [$v, $off + $size];

        case 9: case 10: // unsigned 64/128
            $v = 0;
            for ($i = 0; $i < $size; $i++) $v = ($v << 8) + ord($data[$off + $i]);
            return [$v, $off + $size];

        case 11: // array
            $arr = [];
            $p = $off;
            for ($i = 0; $i < $size; $i++) {
                $r = mmdb_dec($data, $p, $fullData);
                if (!$r) break;
                $arr[] = $r[0]; $p = $r[1];
            }
            return [$arr, $p];

        case 14: // boolean
            return [$size !== 0, $off];

        default:
            return [null, $off + $size];
    }
}

// ── GeoIP com fallback em cascata ──
function get_country($ip) {
    // 1) GeoLite2 local (instantâneo, sem rede)
    $country = get_country_geolite2($ip);
    if ($country !== 'XX') return $country;

    // 2) Fallback: Accept-Language header (heurístico)
    $lang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (strpos($lang, 'pt-br') !== false || strpos($lang, 'pt_br') !== false) return 'BR';

    // 3) Se tudo falhar, LIBERA (frouxo — não queremos perder venda)
    return 'XX';
}

// ── Log de bloqueio (append async, max 500KB) ──
function log_block($ip, $reason, $ua) {
    $logFile = __DIR__ . '/api/data/cloaker_blocks.log';
    // Só loga se arquivo < 500KB (evitar encher disco)
    if (file_exists($logFile) && filesize($logFile) > 512000) return;
    $line = date('Y-m-d H:i:s') . "\t{$ip}\t{$reason}\t" . substr($ua, 0, 120) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ====================== EXECUÇÃO ======================
$ip = get_real_ip();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 1) Bot detection (mais rápido, sem I/O)
$bot_type = detect_bot();
if ($bot_type !== false) {
    log_block($ip, "bot:{$bot_type}", $ua);
    // Resposta leve — não gasta recurso
    header('HTTP/1.1 200 OK');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>Carregando...</title></head><body><p>' . htmlspecialchars($white_message) . '</p></body></html>';
    exit;
}

// 2) Rate limiting (protege contra flood)
$rl = check_rate_limit($ip, $rate_limit_max, $rate_limit_window);
if (!$rl['allowed']) {
    log_block($ip, "ratelimit:{$rl['count']}", $ua);
    header('HTTP/1.1 429 Too Many Requests');
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="30"><title>Aguarde</title></head><body><p>Muitos acessos. Aguarde um momento.</p></body></html>';
    exit;
}

// 3) GeoIP check (ZERO network calls — tudo local)
if ($block_non_br) {
    $country = get_country($ip);
    // Só bloqueia se temos CERTEZA que não é BR (XX = incerto = libera)
    if ($country !== 'BR' && $country !== 'XX') {
        log_block($ip, "geo:{$country}", $ua);
        header('HTTP/1.1 200 OK');
        echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>Carregando...</title></head><body><p>' . htmlspecialchars($white_message) . '</p></body></html>';
        exit;
    }
}

// 4) Tudo OK — redireciona preservando UTMs
$query = trim($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $url_final = rtrim($offer_url, '?&') . '?' . $query;
} else {
    $url_final = $offer_url;
}

header("Location: " . $url_final, true, 302);
exit;
