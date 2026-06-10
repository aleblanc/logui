<?php

declare(strict_types=1);

use Aleblanc\LogUi\Bridge\Symfony\Controller\RawLogController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\UiController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('logui_list', '/_logui')->controller([UiController::class, 'list']);
    // Static "logs" routes must come BEFORE the {id} catch-all so they aren't swallowed.
    $routes->add('logui_logs', '/_logui/logs')->controller([RawLogController::class, 'list']);
    $routes->add('logui_log_view', '/_logui/logs/view')->controller([RawLogController::class, 'view']);
    $routes->add('logui_detail', '/_logui/{id}')->controller([UiController::class, 'detail'])->requirements(['id' => '[A-Za-z0-9]+']);
};
