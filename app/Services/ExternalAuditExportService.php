<?php

declare(strict_types=1);

namespace App\Services;

final class ExternalAuditExportService
{
    public function excel(array $data, array $restaurant): string
    {
        $sheets = [
            'Synthese generale' => $this->summaryRows($data, $restaurant),
            'Resultats journaliers' => $data['days'],
            'Rapports responsables' => array_values(array_filter($data['reports'], static fn (array $r): bool => $r['report_type'] !== 'serveur')),
            'Rapports serveurs' => array_values(array_filter($data['reports'], static fn (array $r): bool => $r['report_type'] === 'serveur')),
            'Stocks ventes calculees' => $this->reportRows($data),
            'Achats' => $this->metricRows($data, 'purchases'),
            'Depenses' => $this->metricRows($data, 'expenses'),
            'Credits' => $this->metricRows($data, 'credits'),
            'Incidents' => [],
            'Pertes expliquees' => array_values(array_filter($data['losses']['rows'], static fn (array $r): bool => in_array($r['status'], ['EXPLIQUE','RESOLU','ANNULE'], true))),
            'Pertes non expliquees' => array_values(array_filter($data['losses']['rows'], static fn (array $r): bool => !in_array($r['status'], ['EXPLIQUE','RESOLU','ANNULE'], true))),
            'Injections' => $this->metricRows($data, 'injection_amount'),
            'Montants suspects' => $this->metricRows($data, 'suspicious_amount'),
            'Manquants' => $this->metricRows($data, 'missing_amount'),
            'Confrontation resp-serveurs' => $data['internal_confrontation']['rows'],
            'Confrontation Audit-App' => $data['application_confrontation']['rows'],
            'Analyse des pertes' => $this->lossSummaryRows($data),
            'Corrections' => [],
            'Versions' => [],
            'Journal des actions' => [],
            'Formules moteur' => $this->formulaRows(),
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles><Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#8B0000" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00"/></Style></Styles>';
        foreach ($sheets as $name => $rows) {
            $xml .= '<Worksheet ss:Name="' . $this->x(substr($name, 0, 31)) . '"><Table>';
            $columns = $this->columns($rows);
            if ($columns === []) {
                $columns = ['information'];
                $rows = [['information' => 'Aucune donnee pour cette periode']];
            }
            $xml .= '<Row>';
            foreach ($columns as $column) {
                $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $this->x($column) . '</Data></Cell>';
            }
            $xml .= '</Row>';
            foreach ($rows as $row) {
                $xml .= '<Row>';
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $numeric = is_int($value) || is_float($value) || (is_string($value) && $value !== '' && is_numeric($value));
                    $xml .= '<Cell' . ($numeric ? ' ss:StyleID="Money"' : '') . '><Data ss:Type="' . ($numeric ? 'Number' : 'String') . '">'
                        . $this->x((string) $value) . '</Data></Cell>';
                }
                $xml .= '</Row>';
            }
            $xml .= '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/>'
                . '<FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane>'
                . '</WorksheetOptions></Worksheet>';
        }
        return $xml . '</Workbook>';
    }

    public function pdf(array $data, array $restaurant): string
    {
        $totals = $data['totals'];
        $lines = [
            'BADIBOSS - RAPPORT AUDIT EXTERNE',
            (string) ($restaurant['name'] ?? 'Restaurant'),
            'Periode: ' . $data['from'] . ' au ' . $data['to'],
            'Moteur: ' . ExternalAuditEngine::VERSION,
            '',
            'SYNTHESE GLOBALE',
            'Ventes calculees: ' . number_format((float) $totals['calculated_sales'], 2, '.', ' '),
            'Ventes declarees: ' . number_format((float) $totals['declared_sales'], 2, '.', ' '),
            'Achats: ' . number_format((float) $totals['purchases'], 2, '.', ' '),
            'Depenses: ' . number_format((float) $totals['expenses'], 2, '.', ' '),
            'Manquants: ' . number_format((float) $totals['missing_amount'], 2, '.', ' '),
            'Montants suspects: ' . number_format((float) $totals['suspicious_amount'], 2, '.', ' '),
            'Injections: ' . number_format((float) $totals['injection_amount'], 2, '.', ' '),
            'Argent attendu: ' . number_format((float) $totals['expected_amount'], 2, '.', ' '),
            'Argent presente: ' . number_format((float) $totals['presented_cash'], 2, '.', ' '),
            'Ecart caisse: ' . number_format((float) $totals['cash_gap'], 2, '.', ' '),
            '',
            'CONFRONTATION RESPONSABLES / SERVEURS',
            'Responsables: ' . number_format((float) $data['internal_confrontation']['responsible_total'], 2, '.', ' '),
            'Serveurs: ' . number_format((float) $data['internal_confrontation']['server_total'], 2, '.', ' '),
            'Ecart a expliquer: ' . number_format((float) $data['internal_confrontation']['global_gap'], 2, '.', ' '),
            '',
            'ANALYSE DES PERTES',
            'Total: ' . number_format((float) $data['losses']['summary']['total'], 2, '.', ' '),
            'Expliquees: ' . number_format((float) $data['losses']['summary']['explained'], 2, '.', ' '),
            'Non expliquees: ' . number_format((float) $data['losses']['summary']['unexplained'], 2, '.', ' '),
            '',
            'Conclusion: les ecarts sont des anomalies a justifier; aucune qualification automatique de vol.',
            'Validation numerique: ' . hash('sha256', json_encode($data['totals']) . ExternalAuditEngine::VERSION),
        ];
        return $this->simplePdf($lines);
    }

    private function simplePdf(array $lines): string
    {
        $content = "BT\n/F1 10 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -16 Td\n";
            }
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line) ?: (string) $line);
            $content .= '(' . $safe . ") Tj\n";
        }
        $content .= "ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }

    private function summaryRows(array $data, array $restaurant): array
    {
        $rows = [['indicateur' => 'Restaurant', 'valeur' => $restaurant['name'] ?? ''], ['indicateur' => 'Periode', 'valeur' => $data['from'] . ' - ' . $data['to']]];
        foreach ($data['totals'] as $key => $value) {
            $rows[] = ['indicateur' => $key, 'valeur' => $value];
        }
        return $rows;
    }

    private function reportRows(array $data): array
    {
        return array_map(static fn (array $r): array => [
            'date' => $r['activity_date'], 'rapport' => $r['id'], 'auteur' => $r['author_name'],
            'type' => $r['report_type'], 'vente_calculee' => $r['calculated_sales'] ?? 0,
            'vente_declaree' => $r['declared_sales'] ?? 0, 'version' => $r['version_no'],
            'moteur' => $r['engine_version'] ?? ExternalAuditEngine::VERSION,
        ], $data['reports']);
    }

    private function metricRows(array $data, string $metric): array
    {
        return array_map(static fn (array $r): array => [
            'date' => $r['activity_date'], 'rapport' => $r['id'], 'auteur' => $r['author_name'],
            'type' => $r['report_type'], 'montant' => $r[$metric] ?? 0,
        ], array_values(array_filter($data['reports'], static fn (array $r): bool => (float) ($r[$metric] ?? 0) !== 0.0)));
    }

    private function lossSummaryRows(array $data): array
    {
        $rows = [];
        foreach (['by_category','by_product','by_person','by_cause'] as $dimension) {
            foreach ($data['losses']['summary'][$dimension] as $name => $amount) {
                $rows[] = ['dimension' => $dimension, 'element' => $name, 'montant' => $amount];
            }
        }
        return $rows;
    }

    private function formulaRows(): array
    {
        return [
            ['formule' => 'Disponible', 'expression' => 'stock precedent + achats + entrees expliquees - sorties expliquees'],
            ['formule' => 'Qvente', 'expression' => 'MAX(0, Disponible - stock restant)'],
            ['formule' => 'Qinj', 'expression' => 'MAX(0, stock restant - Disponible)'],
            ['formule' => 'Manquant', 'expression' => 'MAX(0, Vcalc - Vdecl)'],
            ['formule' => 'Montant suspect', 'expression' => 'MAX(0, Vdecl - Vcalc)'],
            ['formule' => 'Version moteur', 'expression' => ExternalAuditEngine::VERSION],
            ['formule' => 'Non-compensation', 'expression' => 'Somme des resultats journaliers positifs separes'],
        ];
    }

    private function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return $columns;
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
