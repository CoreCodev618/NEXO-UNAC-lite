<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

// Login y registro (Shield)
service('auth')->routes($routes);

// App: inicio, apuntes
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Web\DashboardController::index');
    $routes->get('pages/nueva', 'Web\PageController::new');
    $routes->get('pages/(:num)', 'Web\PageController::show/$1');
});

// API usada por la app: apuntes, tutor y material
$routes->group('api/v1', [
    'namespace' => 'App\Controllers\Api\V1',
    'filter'    => ['apiauth', 'jsonpayload'],
], static function (RouteCollection $routes): void {
    $routes->get('me', 'MeController::show');
    $routes->post('pages', 'PageController::create');
    $routes->get('pages/(:num)', 'PageController::show/$1');
    $routes->put('pages/(:num)', 'PageController::update/$1');
    $routes->post('chat', 'AIChatController::send');
    $routes->get('documents', 'DocumentController::index');
    $routes->post('documents', 'DocumentController::create');
    $routes->delete('documents/(:num)', 'DocumentController::delete/$1');
});
