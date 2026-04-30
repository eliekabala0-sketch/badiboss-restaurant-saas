<?php

declare(strict_types=1);

use App\Support\RailwayDbTools;

function railway_seed_minimal_report(array $appConfig): array
{
    return RailwayDbTools::seedMinimal($appConfig);
}

function railway_seed_minimal_render_report(array $report): string
{
    return RailwayDbTools::renderSeedReport($report);
}
