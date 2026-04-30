<?php

declare(strict_types=1);

use App\Support\RailwayDbTools;

function railway_runtime_repair_report(array $appConfig): array
{
    return RailwayDbTools::runtimeRepair($appConfig);
}

function railway_runtime_repair_render_report(array $report): string
{
    return RailwayDbTools::renderRuntimeRepairReport($report);
}
