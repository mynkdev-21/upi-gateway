<?php

return [
    // Minutes a payment link stays valid before the order expires.
    'order_expiry_minutes' => (int) env('GATEWAY_ORDER_EXPIRY_MINUTES', 15),

    // Minimum seconds between two provider status checks for the same order.
    'check_interval_seconds' => 3,

    // Hours after expiry during which auto-verified orders are still re-checked,
    // because a customer can pay a UPI QR or PG checkout after the order expires.
    // 0 disables late-payment reconciliation.
    'late_payment_window_hours' => (int) env('GATEWAY_LATE_PAYMENT_WINDOW_HOURS', 24),

    // Unofficial Paytm for Business status endpoint. It may change without notice.
    'paytm_status_url' => env('PAYTM_STATUS_URL', 'https://securegw.paytm.in/merchant-status/getTxnStatus'),

    // Official PhonePe PG (Standard Checkout v2).
    'phonepe_pg' => [
        'sandbox' => [
            'token_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token',
            'base_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        ],
        'production' => [
            'token_url' => 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token',
            'base_url' => 'https://api.phonepe.com/apis/pg',
        ],
    ],

    // Official Paytm Payment Gateway.
    'paytm_pg' => [
        'sandbox' => env('PAYTM_PG_SANDBOX_URL', 'https://securestage.paytmpayments.com'),
        'production' => env('PAYTM_PG_PRODUCTION_URL', 'https://secure.paytmpayments.com'),
    ],

    // ── Webhook SSRF protection ───────────────────────────────────────────────
    // Allow http:// webhook URLs (for local dev only). Defaults to false.
    'webhook_allow_insecure' => env('GATEWAY_WEBHOOK_ALLOW_INSECURE', false),
    // Ports that webhook URLs may use. 443 always included; add 80 if insecure allowed.
    'webhook_allowed_ports' => [443],

    // ── Redirect URL security ─────────────────────────────────────────────────
    // Allow http:// redirect URLs (for local dev only). Defaults to false.
    'allow_insecure_redirects' => env('GATEWAY_ALLOW_INSECURE_REDIRECTS', false),

    // ── Content-Security-Policy ───────────────────────────────────────────────
    // Set to true to switch from Report-Only to enforcing CSP.
    // See App\Http\Middleware\SecurityHeaders for what must be fixed first.
    // Set to false to switch to Content-Security-Policy-Report-Only mode.
    'csp_enforce' => env('GATEWAY_CSP_ENFORCE', true),

    // ── API rate limiting ─────────────────────────────────────────────────────
    // Per-API-key request limit per minute. Lower this in tests via config([...]).
    'api_key_rate_limit' => (int) env('GATEWAY_API_KEY_RATE_LIMIT', 120),

    // Per-IP request limit per minute across all API keys.
    'api_ip_rate_limit' => (int) env('GATEWAY_API_IP_RATE_LIMIT', 600),

];
