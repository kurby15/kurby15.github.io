<?php

$ch = curl_init('http://localhost:11434/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);

if ($response === false) {
    echo "ERROR: " . curl_error($ch);
} else {
    echo $response;
}

curl_close($ch);
