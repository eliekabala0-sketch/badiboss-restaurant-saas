<?php

declare(strict_types=1);

use App\Support\RailwayDbTools;

function railway_cleanup_test_request_report(array $appConfig, string $serviceReference): array
{
    return RailwayDbTools::cleanupTestServerRequest($appConfig, $serviceReference);
}

function railway_cleanup_test_request_render_report(array $report): string
{
    return RailwayDbTools::renderCleanupTestServerRequestReport($report);
}
