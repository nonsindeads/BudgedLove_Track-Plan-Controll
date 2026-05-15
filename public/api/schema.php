<?php
// Serve the OpenAPI schema for CustomGPT integration
// This endpoint allows the GPT Builder to fetch the latest schema via URL
// instead of copy-pasting YAML manually.

header('Content-Type: application/yaml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$schema_file = dirname(__DIR__) . '/../docs/api/customgpt-openapi.yaml';

if (!file_exists($schema_file)) {
    http_response_code(404);
    echo "# Error: OpenAPI schema file not found\n";
    exit;
}

readfile($schema_file);
