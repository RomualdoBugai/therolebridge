<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/TokenGuard.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Controllers/CampaignController.php';
require_once __DIR__ . '/Controllers/LeadController.php';
require_once __DIR__ . '/Controllers/RevenueController.php';
require_once __DIR__ . '/Controllers/TrafficSplitController.php';
require_once __DIR__ . '/Controllers/EspController.php';
require_once __DIR__ . '/Controllers/EspScheduleController.php';
require_once __DIR__ . '/Controllers/ClicksController.php';
require_once __DIR__ . '/Controllers/LeadClicksController.php';
require_once __DIR__ . '/Controllers/TokenController.php';
require_once __DIR__ . '/Controllers/DocsController.php';

// ─── Headers ────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Global error handler ────────────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    $isDev = (getenv('APP_ENV') ?: 'production') === 'local';
    Response::error(
        $isDev ? $e->getMessage() : 'Internal server error',
        500,
        $isDev ? ['file' => $e->getFile() . ':' . $e->getLine()] : []
    );
});

// ─── Routes ─────────────────────────────────────────────────────────────────
$request = new Request();
$router  = new Router($request);

$router->get('/campaigns-report',  [CampaignController::class,    'index'],  'campaigns:read');
$router->get('/leads',             [LeadController::class,         'index'],  'leads:read');
$router->post('/leads',            [LeadController::class,         'store'],  'leads:write');
$router->get('/revenue',           [RevenueController::class,      'index'],  'revenue:read');
$router->get('/traffic-split',     [TrafficSplitController::class, 'index'],  'traffic-split:read');
$router->put('/traffic-split',     [TrafficSplitController::class, 'update'], 'traffic-split:write');
$router->get('/esps',              [EspController::class,          'index'],  'esps:read');
$router->get('/esp-schedule',      [EspScheduleController::class,  'index'],  'esp-schedule:read');
$router->get('/clicks',            [ClicksController::class,       'index'],  'clicks:read');
$router->get('/lead-clicks',       [LeadClicksController::class,   'index'],  'lead-clicks:read');
$router->post('/tokens',           [TokenController::class,        'store'],  'tokens:write');
$router->get('/docs',              [DocsController::class,         'index'],  'docs:read');

$router->dispatch();
