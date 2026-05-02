<?php
$apiKey = "AIzaSyA--Dv6SSf1A1lCoXwg9PW_a_Ydv1htVrA";
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

$payload = [
    'system_instruction' => [
        'parts' => [
            ['text' => 'Kamu adalah Finansialin AI.']
        ]
    ],
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => 'Halo']
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
