<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

// ------------------------------------------------------------- auth
$routes->post('api/auth/login', 'Auth::login');
$routes->post('api/auth/logout', 'Auth::logout');
$routes->get('api/auth/me', 'Auth::me');
$routes->put('api/auth/password', 'Auth::password');

// ------------------------------------------------------------- user management
$routes->get('api/users', 'Users::index');
$routes->post('api/users', 'Users::create');
$routes->put('api/users/(:num)', 'Users::update/$1');
$routes->delete('api/users/(:num)', 'Users::delete/$1');

// ------------------------------------------------------------- data utama
$routes->get('api/db', 'Api::db');
$routes->post('api/upload', 'Api::upload');
$routes->post('api/assets', 'Api::createAsset');
$routes->put('api/assets/(:segment)', 'Api::updateAsset/$1');
$routes->post('api/assets/(:segment)/docs', 'Api::addDoc/$1');
$routes->delete('api/assets/(:segment)/docs/(:num)', 'Api::delDoc/$1/$2');
$routes->post('api/mutations', 'Api::createMutation');
$routes->put('api/mutations/(:segment)', 'Api::decideMutation/$1');
$routes->post('api/opname-sessions', 'Api::createOpname');
$routes->post('api/opname-sessions/(:segment)/start', 'Api::startOpname/$1');
$routes->post('api/opname-sessions/(:segment)/end', 'Api::endOpname/$1');
$routes->post('api/opname-sessions/(:segment)/cancel', 'Api::cancelOpname/$1');
$routes->put('api/opname-sessions/(:segment)/items/(:segment)', 'Api::countOpnameItem/$1/$2');
$routes->put('api/settings', 'Api::settings');
$routes->get('api/lists/(:segment)', 'Api::listGet/$1');
$routes->post('api/lists/(:segment)', 'Api::listAdd/$1');
$routes->delete('api/lists/(:segment)', 'Api::listDelete/$1');
$routes->post('api/logs', 'Api::logs');
$routes->post('api/admin/reset', 'Api::reset');
$routes->post('api/admin/import', 'Api::import');

// ------------------------------------------------------------- unggahan (tanpa login)
$routes->get('uploads/(:segment)', 'Files::serve/$1');

// ------------------------------------------------------------- fallback404 JSON (wajib terakhir)
$routes->match(['GET', 'POST', 'PUT', 'DELETE'], 'api/(:any)', 'Api::notFound');
