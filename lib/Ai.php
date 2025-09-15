<?php

class AiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(?string $apiKey = null, string $model = 'gpt-4.1', string $baseUrl = 'https://api.openai.com/v1')
    {
        $apiKey = $apiKey ?? getenv('OPENAI_API_KEY') ?: '';
        if (!$apiKey) {
            throw new RuntimeException('OPENAI_API_KEY not set');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
    }

    /**
     * Calls the Responses API with JSON schema response_format and returns parsed JSON array.
     * @param array $systemMessages array of strings or single string for system guidance
     * @param array $userPayload arbitrary array payload to send as the user message
     * @param array $schema JSON schema object as array (the schema value within response_format)
     * @param float $temperature
     * @return array
     */
    public function callJson(array $systemMessages, array $userPayload, array $schema, float $temperature = 0.2): array
    {
        $url = $this->baseUrl . '/responses';

        // Normalize system messages to array of role/content
        $msgs = [];
        foreach ($systemMessages as $s) {
            $msgs[] = ['role' => 'system', 'content' => (string)$s];
        }
        $msgs[] = ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_SLASHES)];

        // Prefer new Responses API parameter: text.format (json_schema)
        // New format expects name/schema/strict at the top level of format (not nested)
        $jsonFormat = array_merge(['type' => 'json_schema'], $schema);

        $payload = [
            'model' => $this->model,
            'input' => $msgs,
            'temperature' => $temperature,
            'text' => [ 'format' => $jsonFormat ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = json_decode($raw, true);
        if ($code >= 400) {
            $msg = $resp['error']['message'] ?? substr($raw, 0, 400);
            throw new RuntimeException('API error (' . $code . '): ' . $msg);
        }

        // Try to extract model JSON output
        $jsonText = $resp['output'][0]['content'][0]['text']
            ?? $resp['output_text']
            ?? ($resp['output'][0]['text'] ?? null);

        if (!$jsonText) {
            throw new RuntimeException('Unexpected API response: ' . substr($raw, 0, 400));
        }

        $parsed = json_decode($jsonText, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Model did not return JSON. Got: ' . $jsonText);
        }
        return $parsed;
    }
}

class ValueEstimators
{
    /**
     * House valuation. Accepts available fields; optional fields may be omitted.
     */
    public static function valueHouse(AiClient $ai, array $house): array
    {
        $schema = [
            'name' => 'HouseValuation',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'valuation' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'market_value_usd' => ['type' => 'number'],
                            'replacement_cost_usd' => ['type' => 'number'],
                            'assumptions' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['low','medium','high']],
                            'sources' => ['type' => 'array', 'items' => ['type' => 'string']]
                        ],
                        'required' => ['market_value_usd','replacement_cost_usd','assumptions','confidence','sources']
                    ]
                ],
                'required' => ['valuation']
            ],
            'strict' => true
        ];

        $system = [
            "You are a property valuation assistant.",
            "Use the EXACT street address provided to estimate this specific property — do not use city-level averages.",
            "Use platforms like Zillow and Realtor.com to pull the current values and details about the SPECIFIC property.",
            "Return both current MARKET VALUE from Zillow or Realtor and REPLACEMENT COST (rebuild cost) based on the sq foot of the house, bedrooms and bathrooms learned.",
                    ];
        $user = [
            'task' => 'value_house',
            'house' => $house,
        ];
        return $ai->callJson($system, $user, $schema);
    }

    /**
     * Electronics valuation for devices (TVs, laptops, phones, etc.).
     */
    public static function valueElectronics(AiClient $ai, array $device): array
    {
        $schema = [
            'name' => 'ElectronicsValuation',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'valuation' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'market_value_usd' => ['type' => 'number'],
                            'replacement_cost_usd' => ['type' => 'number'],
                            'assumptions' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['low','medium','high']],
                            'sources' => ['type' => 'array', 'items' => ['type' => 'string']]
                        ],
                        'required' => ['market_value_usd','replacement_cost_usd','assumptions','confidence','sources']
                    ]
                ],
                'required' => ['valuation']
            ],
            'strict' => true
        ];

        $system = [
            "You are a consumer electronics resale assistant.",
            "Estimate CURRENT MARKET VALUE (resale) and REPLACEMENT COST (buy new) in USD for the described device.",
        ];
        $user = [
            'task' => 'value_electronics',
            'device' => $device,
        ];
        return $ai->callJson($system, $user, $schema);
    }
}
