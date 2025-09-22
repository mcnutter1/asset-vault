<?php

class PropertyScraper
{
    private static function norm(string $s): string
    {
        $s = strtolower($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/i', '', $s);
        return $s;
    }
    private static function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Connection: keep-alive',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return null;
        return $body;
    }

    private static function buildSearchQuery(array $house): string
    {
        $parts = [];
        foreach (['address_full','address','city','state','zip'] as $k) {
            if (!empty($house[$k])) $parts[] = $house[$k];
        }
        return trim(implode(', ', array_unique($parts)));
    }

    private static function parseZillowDetail(string $html): array
    {
        $facts = [];
        // Try embedded Next.js data
        if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/is', $html, $m)) {
            $json = trim($m[1]);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $walker = function($node, callable $visit) use (&$walker) {
                    if (is_array($node)) { $visit($node); foreach ($node as $v) { $walker($v, $visit); } }
                };
                $walker($data, function($n) use (&$facts){
                    foreach (['zestimate','homeZestimate'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['zestimate_usd'] = (float)$n[$k];
                    foreach (['livingArea','finishedSqFt','floorSize'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['sq_ft'] = (int)$n[$k];
                    if (isset($n['bedrooms']) && is_numeric($n['bedrooms'])) $facts['beds'] = (float)$n['bedrooms'];
                    if (isset($n['bathrooms']) && is_numeric($n['bathrooms'])) $facts['baths'] = (float)$n['bathrooms'];
                    if (isset($n['yearBuilt']) && is_numeric($n['yearBuilt'])) $facts['year_built'] = (int)$n['yearBuilt'];
                    if (isset($n['lotAreaValue']) && is_numeric($n['lotAreaValue'])) {
                        $val = (float)$n['lotAreaValue']; $unit = strtolower((string)($n['lotAreaUnits'] ?? 'acres'));
                        $facts['lot_size_acres'] = $unit === 'acres' ? $val : ($unit === 'sqft' ? $val/43560.0 : null);
                    }
                });
            }
        }
        if (preg_match('/Zestimate[^$]*\$([0-9,]+)/i', $html, $m)) {
            $facts['zestimate_usd'] = (float)str_replace(',', '', $m[1]);
        }
        if (preg_match('/"zestimate"\s*:\s*(\d+)/i', $html, $m)) {
            $facts['zestimate_usd'] = (float)$m[1];
        }
        if (preg_match('/"livingArea"\s*:\s*(\d+)/i', $html, $m)) {
            $facts['sq_ft'] = (int)$m[1];
        } elseif (preg_match('/([0-9,]+)\s*sq\s*ft/i', $html, $m)) {
            $facts['sq_ft'] = (int)str_replace(',', '', $m[1]);
        }
        if (preg_match('/"bedrooms"\s*:\s*(\d+(?:\.\d+)?)/i', $html, $m)) {
            $facts['beds'] = (float)$m[1];
        } elseif (preg_match('/(\d+)\s*beds?/i', $html, $m)) {
            $facts['beds'] = (int)$m[1];
        }
        if (preg_match('/"bathrooms(?:TotalInteger)?"\s*:\s*(\d+(?:\.\d+)?)/i', $html, $m)) {
            $facts['baths'] = (float)$m[1];
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*baths?/i', $html, $m)) {
            $facts['baths'] = (float)$m[1];
        }
        if (preg_match('/"yearBuilt"\s*:\s*(\d{4})/i', $html, $m)) {
            $facts['year_built'] = (int)$m[1];
        }
        if (preg_match('/"lotAreaValue"\s*:\s*(\d+(?:\.\d+)?).*?"lotAreaUnits"\s*:\s*"(acres|sqft)"/is', $html, $m)) {
            $val = (float)$m[1]; $unit = strtolower($m[2]);
            $facts['lot_size_acres'] = $unit === 'acres' ? $val : ($val/43560.0);
        }
        return $facts;
    }

    public static function zillow(array $house, ?string $directUrl = null): array
    {
        $facts = [];
        $targetUrl = $directUrl;
        // Validate provided Zillow homedetails URL
        if ($targetUrl) {
            $p = parse_url($targetUrl);
            $host = strtolower($p['host'] ?? '');
            $path = $p['path'] ?? '';
            if (!preg_match('/(^|\.)zillow\.com$/', $host) || stripos($path, '/homedetails/') === false) {
                $targetUrl = null;
            } else {
                $targetUrl = 'https://www.zillow.com' . $path; // strip params
            }
        }
        if (!$targetUrl) {
            $q = urlencode(self::buildSearchQuery($house));
            $searchUrl = "https://www.zillow.com/homes/{$q}_rb/";
            $html = self::fetch($searchUrl);
            if ($html) {
                $candidates = [];
                // Extract from JSON blobs: "detailUrl":"\/homedetails\/..."
                if (preg_match_all('/\"detailUrl\"\s*:\s*\"(\\\/homedetails\\\/[^"]+)\"/i', $html, $mm)) {
                    foreach ($mm[1] as $u) { $candidates[] = stripslashes($u); }
                }
                // Fallback: plain links
                if (preg_match_all('/https:\\/\\/www\\.zillow\\.com\\/homedetails\\/[A-Za-z0-9\\-_,.%]+/i', addslashes($html), $mm2)) {
                    foreach ($mm2[0] as $u) { $candidates[] = stripcslashes($u); }
                }
                $candidates = array_values(array_unique($candidates));
                // Score candidates by address/city/zip
                $wantedStreet = self::norm($house['address'] ?? '');
                $wantedCity = self::norm($house['city'] ?? '');
                $wantedZip = self::norm($house['zip'] ?? '');
                $best = null; $bestScore = -1;
                foreach ($candidates as $u) {
                    $full = (strpos($u, 'http') === 0) ? $u : ('https://www.zillow.com' . $u);
                    $n = self::norm($full);
                    $score = 0;
                    if ($wantedStreet && strpos($n, $wantedStreet) !== false) $score += 5;
                    if ($wantedCity && strpos($n, $wantedCity) !== false) $score += 3;
                    if ($wantedZip && strpos($n, $wantedZip) !== false) $score += 4;
                    if ($score > $bestScore) { $best = $full; $bestScore = $score; }
                }
                if ($best) {
                    $targetUrl = $best;
                } elseif (preg_match('/https:\/\/www\.zillow\.com\/homedetails\/[A-Za-z0-9\-_,.%]+/i', $html, $m)) {
                    $targetUrl = html_entity_decode($m[0]);
                }
            }
        }
        if ($targetUrl) {
            $detail = self::fetch($targetUrl);
            if ($detail) {
                $facts = array_merge($facts, self::parseZillowDetail($detail));
                $facts['zillow_url'] = $targetUrl;
            }
        }
        return $facts;
    }

    public static function gather(array $house, array $options = []): array
    {
        $facts = [];
        try { $facts = array_merge($facts, self::zillow($house, $options['zillow_url'] ?? null)); } catch (\Throwable $e) {}
        return $facts;
    }
}
