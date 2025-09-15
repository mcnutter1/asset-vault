<?php

class PropertyScraper
{
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
            ],
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
        if (!$targetUrl) {
            $q = urlencode(self::buildSearchQuery($house));
            $searchUrl = "https://www.zillow.com/homes/$q";
            $html = self::fetch($searchUrl);
            if ($html) {
                if (preg_match('/https:\/\/www\.zillow\.com\/homedetails\/[A-Za-z0-9\-_,.%]+/i', $html, $m)) {
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

    public static function redfin(array $house): array
    {
        $facts = [];
        $q = urlencode(self::buildSearchQuery($house));
        $searchUrl = "https://www.redfin.com/stingray/do/location-autocomplete?location=$q";
        $json = self::fetch($searchUrl);
        if ($json && ($data = json_decode($json, true)) && !empty($data['payload']['sections'][0]['rows'][0]['url'])) {
            $url = 'https://www.redfin.com' . $data['payload']['sections'][0]['rows'][0]['url'];
            $detail = self::fetch($url);
            if ($detail) {
                if (preg_match('/Redfin Estimate\s*\$([0-9,]+)/i', $detail, $m)) {
                    $facts['redfin_estimate_usd'] = (float)str_replace(',', '', $m[1]);
                }
                $facts['redfin_url'] = $url;
            }
        }
        return $facts;
    }

    public static function gather(array $house, array $options = []): array
    {
        $facts = [];
        try { $facts = array_merge($facts, self::zillow($house, $options['zillow_url'] ?? null)); } catch (\Throwable $e) {}
        try { $facts = array_merge($facts, self::redfin($house)); } catch (\Throwable $e) {}
        return $facts;
    }
}
