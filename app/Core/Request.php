<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private array  $routeParams = [];
    private ?array $parsedJson  = null;
    private bool   $jsonParsed  = false;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri       = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->uri    = '/' . trim((string) $rawUri, '/');
        if ($this->uri === '') {
            $this->uri = '/';
        }
    }

    // -------------------------------------------------------------------------
    // Basic accessors
    // -------------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isJson(): bool
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($ct, 'application/json');
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    /**
     * Get a value from POST body, JSON body, or query string — in that order.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // JSON body.
        if ($this->isJson()) {
            $json = $this->json();
            if (is_array($json) && array_key_exists($key, $json)) {
                return $json[$key];
            }
        }

        // POST fields.
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        // Query string.
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }

    /**
     * Return all input as an array (merged from all sources).
     */
    public function all(): array
    {
        $base = array_merge($_GET, $_POST);

        if ($this->isJson()) {
            $json = $this->json();
            if (is_array($json)) {
                $base = array_merge($base, $json);
            }
        }

        return $base;
    }

    /**
     * Decode JSON body. Returns null if not JSON or parse failure.
     */
    public function json(): mixed
    {
        if ($this->jsonParsed) {
            return $this->parsedJson;
        }

        $this->jsonParsed = true;

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded          = json_decode($raw, true);
        $this->parsedJson = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;

        return $this->parsedJson;
    }

    /**
     * Get an uploaded file from $_FILES.
     */
    public function file(string $key): ?array
    {
        if (!isset($_FILES[$key])) {
            return null;
        }
        $f = $_FILES[$key];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    // -------------------------------------------------------------------------
    // Route params
    // -------------------------------------------------------------------------

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    // -------------------------------------------------------------------------
    // IP
    // -------------------------------------------------------------------------

    /**
     * Client IP — Cloudflare-aware.
     */
    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }

    /**
     * User-Agent header.
     */
    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Authorization header value (full string including "Bearer " prefix).
     */
    public function authorizationHeader(): string
    {
        return $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }

    /**
     * Extract the Bearer token from the Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = $this->authorizationHeader();
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validate input against rules.
     *
     * Supported rules (colon-separated): required, email, min:n, max:n, confirmed
     *
     * @param  array<string, string> $rules  e.g. ['email' => 'required|email', 'password' => 'required|min:8|confirmed']
     * @return array<string, list<string>>   Errors keyed by field name; empty = valid.
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $data   = $this->all();

        foreach ($rules as $field => $ruleString) {
            $fieldRules = array_map('trim', explode('|', $ruleString));
            $value      = $data[$field] ?? null;
            $strValue   = is_string($value) ? $value : ($value !== null ? (string) $value : '');

            foreach ($fieldRules as $rule) {
                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                } else {
                    $ruleName  = $rule;
                    $ruleParam = null;
                }

                switch ($ruleName) {
                    case 'required':
                        if ($value === null || $strValue === '') {
                            $errors[$field][] = "The {$field} field is required.";
                        }
                        break;

                    case 'email':
                        if ($strValue !== '' && !filter_var($strValue, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "The {$field} field must be a valid email address.";
                        }
                        break;

                    case 'min':
                        $min = (int) $ruleParam;
                        if ($strValue !== '' && mb_strlen($strValue) < $min) {
                            $errors[$field][] = "The {$field} field must be at least {$min} characters.";
                        }
                        break;

                    case 'max':
                        $max = (int) $ruleParam;
                        if ($strValue !== '' && mb_strlen($strValue) > $max) {
                            $errors[$field][] = "The {$field} field must not exceed {$max} characters.";
                        }
                        break;

                    case 'confirmed':
                        $confirmValue = $data["{$field}_confirmation"] ?? null;
                        $confirmStr   = is_string($confirmValue) ? $confirmValue : '';
                        if ($strValue !== $confirmStr) {
                            $errors[$field][] = "The {$field} confirmation does not match.";
                        }
                        break;
                }
            }
        }

        return $errors;
    }
}
