<?php

declare(strict_types=1);

/**
 * WhatsApp Business Platform configuration (owner request 2026-11-15).
 *
 * Estate Admins already enter their own estate Cloud API credentials in
 * Admin > Settings. These values are the platform-level Meta app used to run the
 * "Connect WhatsApp with Meta" Embedded Signup flow (OAuth redirect based, no
 * third-party JavaScript, so the strict CSP stays intact):
 *
 *  - app_id / app_secret: the DashPASS Meta app (Facebook Login for Business).
 *  - es_config_id: the Embedded Signup configuration id created in the Meta app.
 *  - graph_version: Cloud API Graph version.
 *
 * When these are not configured the button explains that manual credentials are
 * still available; the broadcast composer continues to use the estate credentials.
 */
return [
    'app_id' => (string) env('WHATSAPP_APP_ID', ''),
    'app_secret' => (string) env('WHATSAPP_APP_SECRET', ''),
    'es_config_id' => (string) env('WHATSAPP_ES_CONFIG_ID', ''),
    'graph_version' => (string) env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
];
