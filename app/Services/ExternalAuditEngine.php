<?php

declare(strict_types=1);

namespace App\Services;

final class ExternalAuditEngine
{
    public const VERSION = 'external-audit-1.0.0';

    public function product(array $line): array
    {
        $available = $this->n($line['previous_stock'] ?? 0)
            + $this->n($line['purchased_quantity'] ?? 0)
            + $this->n($line['explained_entries'] ?? 0)
            - $this->n($line['explained_outputs'] ?? 0);
        $remaining = $this->n($line['remaining_stock'] ?? 0);
        $gross = $available - $remaining;
        $sold = max(0.0, $gross);
        $injection = max(0.0, $remaining - $available);
        $price = $this->n($line['sale_price_snapshot'] ?? 0);

        return [
            'available' => $available,
            'sold_quantity' => $sold,
            'injection_quantity' => $injection,
            'sale_amount' => $sold * $price,
            'injection_amount' => $injection * $price,
        ];
    }

    public function report(array $lines, float $declaredSales, float $presentedCash, float $validatedAdjustments = 0): array
    {
        $totals = ['calculated_sales' => 0.0, 'purchases' => 0.0, 'expenses' => 0.0, 'credits' => 0.0, 'injection_amount' => 0.0];
        foreach ($lines as $line) {
            $calculated = $this->product($line);
            $totals['calculated_sales'] += $calculated['sale_amount'];
            $totals['injection_amount'] += $calculated['injection_amount'];
            $totals['purchases'] += $this->n($line['purchase_total'] ?? 0);
            $totals['expenses'] += $this->n($line['expense_amount'] ?? 0);
            $totals['credits'] += $this->n($line['credit_amount'] ?? 0);
        }

        $totals['declared_sales'] = max(0.0, $declaredSales);
        $totals['missing_amount'] = max(0.0, $totals['calculated_sales'] - $totals['declared_sales']);
        $totals['suspicious_amount'] = max(0.0, $totals['declared_sales'] - $totals['calculated_sales']);
        $totals['prudent_base'] = max($totals['calculated_sales'], $totals['declared_sales']);
        $totals['expected_amount'] = max(0.0, $totals['prudent_base'] - $totals['purchases'] - $totals['expenses'] - max(0.0, $validatedAdjustments));
        $totals['presented_cash'] = max(0.0, $presentedCash);
        $totals['cash_gap'] = $totals['expected_amount'] - $totals['presented_cash'];
        $totals['engine_version'] = self::VERSION;

        return $totals;
    }

    public function period(array $dailyResults): array
    {
        $keys = ['calculated_sales', 'declared_sales', 'purchases', 'expenses', 'credits', 'missing_amount', 'suspicious_amount', 'injection_amount', 'expected_amount', 'presented_cash', 'cash_gap'];
        $out = array_fill_keys($keys, 0.0);
        foreach ($dailyResults as $day) {
            foreach ($keys as $key) {
                $out[$key] += $this->n($day[$key] ?? 0);
            }
        }
        return $out;
    }

    public function purchaseUnitPrice(float $total, float $quantity): float
    {
        return $quantity > 0 ? max(0.0, $total) / $quantity : 0.0;
    }

    public function caseUnits(float $cases, float $halfCases, float $units, float $unitsPerCase, float $unitsPerHalfCase): float
    {
        return max(0.0, $cases) * max(0.0, $unitsPerCase)
            + max(0.0, $halfCases) * max(0.0, $unitsPerHalfCase)
            + max(0.0, $units);
    }

    public function confrontation(array $responsibleLines, array $serverLines): array
    {
        $group = static function (array $lines): array {
            $out = [];
            foreach ($lines as $line) {
                $key = (string) ($line['product_id'] ?? '0');
                $out[$key] ??= [
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'product' => (string) ($line['product'] ?? ''),
                    'category' => (string) ($line['category'] ?? ''),
                    'quantity' => 0.0,
                    'amount' => 0.0,
                    'credits' => 0.0,
                    'people' => [],
                ];
                $out[$key]['quantity'] += (float) ($line['quantity'] ?? 0);
                $out[$key]['amount'] += (float) ($line['amount'] ?? 0);
                $out[$key]['credits'] += (float) ($line['credits'] ?? 0);
                $person = trim((string) ($line['person'] ?? ''));
                if ($person !== '') {
                    $out[$key]['people'][] = $person;
                }
            }
            return $out;
        };
        $responsibles = $group($responsibleLines);
        $servers = $group($serverLines);
        $rows = [];
        foreach (array_unique(array_merge(array_keys($responsibles), array_keys($servers))) as $key) {
            $r = $responsibles[$key] ?? ['product_id' => (int) $key, 'product' => '', 'category' => '', 'quantity' => 0.0, 'amount' => 0.0, 'credits' => 0.0, 'people' => []];
            $s = $servers[$key] ?? ['product_id' => (int) $key, 'product' => '', 'category' => '', 'quantity' => 0.0, 'amount' => 0.0, 'credits' => 0.0, 'people' => []];
            $rows[] = [
                'product_id' => (int) $key,
                'product' => $r['product'] !== '' ? $r['product'] : $s['product'],
                'category' => $r['category'] !== '' ? $r['category'] : $s['category'],
                'responsible_quantity' => $r['quantity'],
                'server_quantity' => $s['quantity'],
                'quantity_gap' => $r['quantity'] - $s['quantity'],
                'responsible_amount' => $r['amount'],
                'server_amount' => $s['amount'],
                'amount_gap' => $r['amount'] - $s['amount'],
                'credit_gap' => $r['credits'] - $s['credits'],
                'responsible_people' => array_values(array_unique($r['people'])),
                'server_people' => array_values(array_unique($s['people'])),
                'status' => abs($r['amount'] - $s['amount']) < 0.001 && abs($r['quantity'] - $s['quantity']) < 0.001
                    ? 'COHERENT'
                    : 'JUSTIFICATION_EN_ATTENTE',
            ];
        }
        return [
            'rows' => $rows,
            'responsible_total' => array_sum(array_column($responsibles, 'amount')),
            'server_total' => array_sum(array_column($servers, 'amount')),
            'global_gap' => array_sum(array_column($responsibles, 'amount')) - array_sum(array_column($servers, 'amount')),
        ];
    }

    private function n(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
