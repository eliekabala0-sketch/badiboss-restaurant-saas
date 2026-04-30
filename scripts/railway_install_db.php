<?php

declare(strict_types=1);

use App\Support\RailwayDbTools;

function railway_install_db_report(array $appConfig): array
{
    return RailwayDbTools::install($appConfig);
}

function railway_install_db_render_report(array $report): string
{
    return RailwayDbTools::renderInstallReport($report);
}
