<?php

declare(strict_types=1);

use App\Support\RailwayDbTools;

function railway_inspect_test_request_report(array $appConfig, string $serviceReference): array
{
    return RailwayDbTools::inspectTestServerRequest($appConfig, $serviceReference);
}

function railway_inspect_test_request_render_report(array $report): string
{
    return RailwayDbTools::renderInspectTestServerRequestReport($report);
}
