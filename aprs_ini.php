<?php
// Editing m17-gateway's [APRS] settings in place: comments, blank lines,
// order and the file's own "key = value" style are kept, so only what was
// changed changes. The gateway re-reads [APRS] when the file changes.

// A value is written verbatim, so it must not contain anything that would
// change how the line is parsed.
function aprsValidValue($v) {
    return !preg_match('/[\x00-\x1f\x7f;#"`\\\\]/', (string)$v);
}

// Return the lines of $section, and the index of its header, or -1.
function aprsIniSection(array $lines, $section) {
    $start = -1;
    $end = count($lines);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
            if ($start >= 0) { $end = $i; break; }
            if (trim($m[1]) === $section) $start = $i;
        }
    }
    return [$start, $end];
}

// Read the key/value pairs of $section.
function aprsIniValues(array $lines, $section) {
    [$start, $end] = aprsIniSection($lines, $section);
    $values = [];
    for ($i = $start + 1; $start >= 0 && $i < $end; $i++) {
        if (preg_match('/^\s*([^=;#\s][^=]*?)\s*=\s*(.*?)\s*$/', $lines[$i], $m)) {
            $values[$m[1]] = $m[2];
        }
    }
    return $values;
}

// Set the keys in $set and remove those in $del, in $section, which is
// added if missing. New keys go after the section's last setting.
function aprsIniUpdate(array $lines, $section, array $set, array $del) {
    [$start, $end] = aprsIniSection($lines, $section);
    if ($start < 0) {
        if (count($lines) && trim(end($lines)) !== '') $lines[] = '';
        $lines[] = '[' . $section . ']';
        $start = count($lines) - 1;
        $end = count($lines);
    }
    $sep = ' = ';
    $body = [];
    $last = -1; // index in $body of the last setting
    for ($i = $start + 1; $i < $end; $i++) {
        $line = $lines[$i];
        if (preg_match('/^\s*([^=;#\s][^=]*?)(\s*=\s*)/', $line, $m)) {
            $sep = $m[2];
            if (in_array($m[1], $del, true)) continue;
            if (array_key_exists($m[1], $set)) {
                $line = preg_replace('/^(\s*[^=]*?\s*=\s*).*$/', '${1}', $line) . $set[$m[1]];
                unset($set[$m[1]]);
            }
            $body[] = $line;
            $last = count($body) - 1;
            continue;
        }
        $body[] = $line;
    }
    $add = [];
    foreach ($set as $k => $v) $add[] = $k . $sep . $v;
    array_splice($body, $last + 1, 0, $add);
    return array_merge(array_slice($lines, 0, $start + 1), $body, array_slice($lines, $end));
}

// Parse a Watch value, as m17-gateway does; null if it is not one.
function aprsParseWatch($value) {
    $f = preg_split('/\s+/', trim((string)$value));
    $w = ['toRF' => false, 'until' => null];
    $kind = strtolower($f[0] ?? '');
    if ($kind === 'call' && isset($f[1]) && preg_match('/^[A-Za-z0-9-]{1,9}\*?$/', $f[1])) {
        $w += ['kind' => 'call', 'call' => strtoupper($f[1])];
        $rest = array_slice($f, 2);
    } else if ($kind === 'area' && count($f) >= 4 && is_numeric($f[1]) && is_numeric($f[2]) && is_numeric($f[3])) {
        $w += ['kind' => 'area', 'lat' => (float)$f[1], 'lon' => (float)$f[2], 'km' => (float)$f[3]];
        $rest = array_slice($f, 4);
    } else if ($kind === 'near' && isset($f[1]) && is_numeric($f[1])) {
        $w += ['kind' => 'near', 'km' => (float)$f[1]];
        $rest = array_slice($f, 2);
    } else {
        return null;
    }
    for ($i = 0; $i < count($rest); $i++) {
        $word = strtolower($rest[$i]);
        if ($word === 'rf') $w['toRF'] = true;
        else if ($word === 'dashboard') $w['toRF'] = false;
        else if ($word === 'until' && isset($rest[$i + 1]) && ($t = strtotime($rest[$i + 1])) !== false) { $w['until'] = $t; $i++; }
        else return null;
    }
    return $w;
}

// Build a Watch value, or return an error message.
function aprsWatchValue($kind, $call, $lat, $lon, $km, $toRF, $until) {
    $action = $toRF ? 'rf' : 'dashboard';
    $end = $until ? ' until ' . gmdate('Y-m-d\TH:i:s\Z', $until) : '';
    if ($kind === 'call') {
        $call = strtoupper(trim((string)$call));
        if (!preg_match('/^[A-Z0-9-]{1,9}\*?$/', $call)) return [null, 'A callsign is letters, digits and \'-\', at most 9, optionally ending in \'*\'.'];
        return ["call $call $action$end", null];
    }
    if (!is_numeric($km) || $km <= 0 || $km > 500) return [null, 'The radius must be from 0 to 500 km.'];
    $km = round((float)$km, 1);
    if ($kind === 'near') return ["near $km $action$end", null];
    if ($kind !== 'area') return [null, 'Unknown rule.'];
    if (!is_numeric($lat) || !is_numeric($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return [null, 'Pick the centre on the map, or enter latitude and longitude in decimal degrees.'];
    }
    return [sprintf('area %.4f %.4f %s %s%s', $lat, $lon, $km, $action, $end), null];
}
