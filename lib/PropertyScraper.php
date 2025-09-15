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

    public static function zillow(array $house): array
    {
        $facts = [];
        $q = urlencode(self::buildSearchQuery($house));
        $searchUrl = "https://www.zillow.com/homes/$q";
        $html = self::fetch($searchUrl);
        $targetUrl = null;
        if ($html) {
            if (preg_match('/https:\/\/www\.zillow\.com\/homedetails\/[A-Za-z0-9\-_,.%]+/i', $html, $m)) {
                $targetUrl = html_entity_decode($m[0]);
            }
        }
        if ($targetUrl) {
            $detail = self::fetch($targetUrl);
            if ($detail) {
                if (preg_match('/Zestimate\s*:\s*\$([0-9,]+)/i', $detail, $m)) {
                    $facts['zestimate_usd'] = (float)str_replace(',', '', $m[1]);
                }
                if (preg_match('/([0-9,]+)\s*sq\s*ft/i', $detail, $m)) {
                    $facts['sq_ft'] = (int)str_replace(',', '', $m[1]);
                }
                if (preg_match('/(\d+)\s*beds?/i', $detail, $m)) {
                    $facts['beds'] = (int)$m[1];
                }
                if (preg_match('/(\d+(?:\.\d+)?)\s*baths?/i', $detail, $m)) {
                    $facts['baths'] = (float)$m[1];
                }
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

    public static function gather(array $house): array
    {
        $facts = [];
        try { $facts = array_merge($facts, self::zillow($house)); } catch (\Throwable $e) {}
        try { $facts = array_merge($facts, self::redfin($house)); } catch (\Throwable $e) {}
        return $facts;
    }
}

