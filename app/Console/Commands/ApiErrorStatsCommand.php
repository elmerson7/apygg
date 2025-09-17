<?php

namespace App\Console\Commands;

use App\Services\Logging\ApiProblemLogger;
use Illuminate\Console\Command;

class ApiErrorStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'api:error-stats 
                            {--days=7 : Number of days to analyze}
                            {--format=table : Output format (table, json)}
                            {--endpoint= : Filter by specific endpoint}
                            {--status= : Filter by HTTP status code}';

    /**
     * The console command description.
     */
    protected $description = 'Show API error statistics from problem details logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $format = $this->option('format');
        $endpoint = $this->option('endpoint');
        $status = $this->option('status');

        $this->info("📊 API Error Statistics (Last {$days} days)");
        $this->newLine();

        try {
            // Obtener estadísticas generales
            $stats = ApiProblemLogger::getErrorStats($days);
            
            $this->displayGeneralStats($stats, $format);
            $this->newLine();
            
            $this->displayStatusBreakdown($stats, $format);
            $this->newLine();
            
            $this->displayTopErrorTypes($stats, $format);
            $this->newLine();
            
            $this->displayProblematicEndpoints($days, $format);
            $this->newLine();
            
            $this->displayErrorTrends($format);

            if ($format === 'json') {
                $this->info('✅ Complete data exported as JSON above');
            } else {
                $this->info('✅ Error statistics analysis completed');
            }

        } catch (\Exception $e) {
            $this->error("❌ Error generating statistics: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Display general statistics.
     */
    private function displayGeneralStats(array $stats, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode([
                'section' => 'general_stats',
                'total_errors' => $stats['total_errors'],
                'error_rate_by_day' => $stats['error_rate_by_day']
            ], JSON_PRETTY_PRINT));
            return;
        }

        $this->info('📈 General Statistics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Errors', number_format($stats['total_errors'])],
                ['Daily Average', number_format($stats['total_errors'] / max(1, $this->option('days')))],
            ]
        );
    }

    /**
     * Display HTTP status breakdown.
     */
    private function displayStatusBreakdown(array $stats, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode([
                'section' => 'status_breakdown',
                'data' => $stats['by_status']
            ], JSON_PRETTY_PRINT));
            return;
        }

        $this->info('🔢 Errors by HTTP Status');
        if (empty($stats['by_status'])) {
            $this->warn('No error data found');
            return;
        }

        $tableData = [];
        foreach ($stats['by_status'] as $statusStat) {
            $status = $statusStat['status'];
            $count = $statusStat['count'];
            $percentage = round(($count / $stats['total_errors']) * 100, 1);
            
            $emoji = $this->getStatusEmoji($status);
            $tableData[] = [$emoji . ' ' . $status, number_format($count), $percentage . '%'];
        }

        $this->table(['Status Code', 'Count', 'Percentage'], $tableData);
    }

    /**
     * Display top error types.
     */
    private function displayTopErrorTypes(array $stats, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode([
                'section' => 'error_types',
                'data' => $stats['by_type']
            ], JSON_PRETTY_PRINT));
            return;
        }

        $this->info('🚨 Top Error Types');
        if (empty($stats['by_type'])) {
            $this->warn('No error type data found');
            return;
        }

        $tableData = [];
        foreach (array_slice($stats['by_type'], 0, 10) as $typeStat) {
            $type = basename($typeStat['type']);
            $count = $typeStat['count'];
            $percentage = round(($count / $stats['total_errors']) * 100, 1);
            
            $tableData[] = [$type, number_format($count), $percentage . '%'];
        }

        $this->table(['Error Type', 'Count', 'Percentage'], $tableData);
    }

    /**
     * Display problematic endpoints.
     */
    private function displayProblematicEndpoints(int $days, string $format): void
    {
        $endpoints = ApiProblemLogger::getProblematicEndpoints($days, 10);
        
        if ($format === 'json') {
            $this->line(json_encode([
                'section' => 'problematic_endpoints',
                'data' => $endpoints
            ], JSON_PRETTY_PRINT));
            return;
        }

        $this->info('🎯 Most Problematic Endpoints (Server Errors 5xx)');
        if (empty($endpoints)) {
            $this->warn('No problematic endpoints found (good news!)');
            return;
        }

        $tableData = [];
        foreach ($endpoints as $endpoint) {
            $tableData[] = [
                $endpoint['endpoint'] ?: 'Unknown',
                number_format($endpoint['error_count']),
                round($endpoint['avg_status'], 0)
            ];
        }

        $this->table(['Endpoint', 'Error Count', 'Avg Status'], $tableData);
    }

    /**
     * Display error trends.
     */
    private function displayErrorTrends(string $format): void
    {
        $trends = ApiProblemLogger::getErrorTrends(24);
        
        if ($format === 'json') {
            $this->line(json_encode([
                'section' => 'error_trends',
                'data' => $trends
            ], JSON_PRETTY_PRINT));
            return;
        }

        $this->info('📊 Error Trends (Last 24 Hours)');
        
        $clientErrorCount = array_sum(array_column($trends['client_errors'], 'count'));
        $serverErrorCount = array_sum(array_column($trends['server_errors'], 'count'));
        
        $this->table(
            ['Error Type', 'Count (24h)', 'Hourly Average'],
            [
                ['🟡 Client Errors (4xx)', number_format($clientErrorCount), number_format($clientErrorCount / 24, 1)],
                ['🔴 Server Errors (5xx)', number_format($serverErrorCount), number_format($serverErrorCount / 24, 1)],
            ]
        );
    }

    /**
     * Get emoji for HTTP status code.
     */
    private function getStatusEmoji(int $status): string
    {
        if ($status >= 200 && $status < 300) return '🟢';
        if ($status >= 300 && $status < 400) return '🟡';
        if ($status >= 400 && $status < 500) return '🟠';
        if ($status >= 500) return '🔴';
        return '⚪';
    }
}
