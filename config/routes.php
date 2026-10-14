<?php

declare(strict_types=1);

use Aleblanc\LogUi\Bridge\Symfony\Controller\ExploreController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\HealthController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\RawLogController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\UiController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // The explorer is the landing page: searching the logs is what people open LogUI for.
    // The request list keeps its own URL, and /_logui/explore still works for old links.
    $routes->add('logui_explore', '/_logui')->controller([ExploreController::class, 'index']);
    // Static routes must come BEFORE the {id} catch-all so they aren't swallowed.
    $routes->add('logui_list', '/_logui/requests')->controller([UiController::class, 'list']);
    $routes->add('logui_logs', '/_logui/logs')->controller([RawLogController::class, 'list']);
    $routes->add('logui_log_view', '/_logui/logs/view')->controller([RawLogController::class, 'view']);
    // Destructive: POST only, so a cross-site request carries no auth cookie (SameSite=Lax).
    $routes->add('logui_log_truncate', '/_logui/logs/truncate')->controller([RawLogController::class, 'truncate'])->methods(['POST']);
    // Log explorer JSON endpoints — what the histogram, the table and the live tail poll.
    $routes->add('logui_explore_legacy', '/_logui/explore')->controller([ExploreController::class, 'index']);
    $routes->add('logui_explore_query', '/_logui/explore/query')->controller([ExploreController::class, 'query']);
    $routes->add('logui_explore_histogram', '/_logui/explore/histogram')->controller([ExploreController::class, 'histogram']);
    $routes->add('logui_explore_stream', '/_logui/explore/stream')->controller([ExploreController::class, 'stream']);
    $routes->add('logui_explore_profile', '/_logui/explore/profile')->controller([ExploreController::class, 'profile']);
    $routes->add('logui_explore_context', '/_logui/explore/context')->controller([ExploreController::class, 'context']);
    $routes->add('logui_health', '/_logui/health')->controller([HealthController::class, 'show']);
    $routes->add('logui_detail', '/_logui/{id}')->controller([UiController::class, 'detail'])->requirements(['id' => '[A-Za-z0-9]+']);
};
