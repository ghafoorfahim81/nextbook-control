<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports. The money ones (revenue, receivables) need billing.view; the
 * ones about customers staying and using what they bought need reports.view.
 * Each can be taken to Excel with ?export=csv.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index()
    {
        return redirect()->route(Gate::allows('billing.view') ? 'reports.revenue' : 'reports.renewals');
    }

    public function revenue(Request $request)
    {
        Gate::authorize('reports.view');
        Gate::authorize('billing.view');

        $data = $this->reports->revenue(app()->getLocale());

        if ($request->query('export') === 'csv') {
            $rows = [];
            foreach ($data['months'] as $month) {
                foreach ($data['currencies'] as $currency) {
                    $rows[] = [$month['from'], $month['to'], $currency, $month['invoiced'][$currency] ?? '0', $month['collected'][$currency] ?? '0'];
                }
            }

            return $this->csv('revenue', ['from', 'to', 'currency', 'invoiced', 'collected'], $rows);
        }

        return inertia('Reports/Revenue', $data);
    }

    public function receivables(Request $request)
    {
        Gate::authorize('reports.view');
        Gate::authorize('billing.view');

        $data = $this->reports->receivables();

        if ($request->query('export') === 'csv') {
            return $this->csv('receivables', ['customer', 'currency', 'not_yet_due', '1_30_days', '31_60_days', 'over_60_days', 'total', 'oldest_days_late'],
                array_map(fn ($r) => [$r['customer'], $r['currency'], $r['current'], $r['d30'], $r['d60'], $r['d60plus'], $r['total'], $r['oldest_days']], $data['rows']));
        }

        return inertia('Reports/Receivables', $data);
    }

    public function renewals(Request $request)
    {
        Gate::authorize('reports.view');

        $data = $this->reports->renewals(app()->getLocale());

        if ($request->query('export') === 'csv') {
            return $this->csv('renewals', ['from', 'to', 'renewed', 'lapsed', 'renewal_rate_percent'],
                array_map(fn ($m) => [$m['from'], $m['to'], $m['renewed'], $m['lapsed'], $m['rate']], $data['months']));
        }

        return inertia('Reports/Renewals', $data);
    }

    public function usage(Request $request)
    {
        Gate::authorize('reports.view');

        $data = $this->reports->usage();

        if ($request->query('export') === 'csv') {
            return $this->csv('usage', ['package', 'customers', 'reported', 'average_users', 'average_branches'],
                array_map(fn ($p) => [$p['package'], $p['customers'], $p['reported'], $p['avg_users'], $p['avg_branches']], $data['packages']));
        }

        return inertia('Reports/Usage', $data + ['unused' => $this->reports->unusedPackages()]);
    }

    /**
     * A CSV that Excel opens correctly: UTF-8 with a byte order mark, so Dari
     * and Pashto names are not turned into question marks.
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(string $name, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                // A cell that starts like a formula would be run by Excel; keep it as text.
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell, $row));
            }
            fclose($out);
        }, "nextbook-{$name}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
