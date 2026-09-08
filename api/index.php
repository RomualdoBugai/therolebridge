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

$router->get('/campaigns-report',  [CampaignController::class,    'index']);
$router->get('/leads',             [LeadController::class,         'index']);
$router->post('/leads',            [LeadController::class,         'store']);
$router->get('/revenue',           [RevenueController::class,      'index']);
$router->get('/traffic-split',     [TrafficSplitController::class, 'index']);
$router->put('/traffic-split',     [TrafficSplitController::class, 'update']);
$router->get('/esps',              [EspController::class,          'index']);
$router->get('/esp-schedule',      [EspScheduleController::class,  'index']);
$router->get('/clicks',            [ClicksController::class,       'index']);
$router->get('/lead-clicks',       [LeadClicksController::class,   'index']);
$router->get('/docs',              [DocsController::class,         'index']);

$router->dispatch();
