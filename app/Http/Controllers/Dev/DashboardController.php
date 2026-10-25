<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\Authorization\Ability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Number of recent audit rows shown.
     */
    private const AUDIT_ROWS = 50;

    /**
     * The dev role's own landing page (admin may open it too): system health
     * (`diagnostics.view`) and a recent audit trail (`audit.view`).
     *
     * Deliberately free of personal data and secrets: no config values beyond
     * driver names and on/off flags, and audit rows without user ids, IP
     * addresses, user agents or before/after values. No action is offered here.
     */
    public function show(Request $request): View
    {
        $user = $request->user();
        $canDiagnose = $user->hasPermission(Ability::ViewDiagnostics);
        $canAudit = $user->hasPermission(Ability::ViewAuditLog);

        abort_unless($canDiagnose || $canAudit, 403);

        return view('dev.dashboard', [
            'health' => $canDiagnose ? $this->health() : null,
            'auditRows' => $canAudit
                ? DB::table('audit_logs')
                    ->select(['created_at', 'event', 'subject_type', 'subject_id', 'actor_type'])
                    ->orderByDesc('id')
                    ->limit(self::AUDIT_ROWS)
                    ->get()
                : null,
        ]);
    }

    /**
     * @return list<array{label: string, value: string, ok: bool|null}>
     */
    private function health(): array
    {
        $check = function (callable $probe): array {
            try {
                return [true, (string) $probe()];
            } catch (Throwable $exception) {
                return [false, 'Failed ('.class_basename($exception).')'];
            }
        };

        [$databaseOk, $database] = $check(function (): string {
            DB::connection()->getPdo();

            return 'Connected ('.DB::connection()->getDriverName().')';
        });

        [$cacheOk, $cache] = $check(function (): string {
            Cache::put('dev:health-check', 'ok', 10);
            $value = Cache::pull('dev:health-check');

            return $value === 'ok' ? 'Read/write OK ('.config('cache.default').')' : throw new RuntimeException('Cache value mismatch');
        });

        [$failedOk, $failed] = $check(fn (): string => (string) DB::table('failed_jobs')->count());
        [$pendingOk, $pending] = $check(fn (): string => (string) DB::table('jobs')->count());

        $writable = fn (string $path): array => ['value' => is_writable($path) ? 'Writable' : 'Not writable', 'ok' => is_writable($path)];

        return [
            ['label' => 'PHP version', 'value' => PHP_VERSION, 'ok' => null],
            ['label' => 'Laravel version', 'value' => app()->version(), 'ok' => null],
            ['label' => 'Environment', 'value' => (string) app()->environment(), 'ok' => null],
            ['label' => 'Debug mode', 'value' => config('app.debug') ? 'On' : 'Off', 'ok' => ! config('app.debug') || app()->environment('local')],
            ['label' => 'Maintenance mode', 'value' => app()->isDownForMaintenance() ? 'On' : 'Off', 'ok' => ! app()->isDownForMaintenance()],
            ['label' => 'Database', 'value' => $database, 'ok' => $databaseOk],
            ['label' => 'Cache', 'value' => $cache, 'ok' => $cacheOk],
            ['label' => 'Storage (framework)', ...$writable(storage_path('framework'))],
            ['label' => 'Storage (logs)', ...$writable(storage_path('logs'))],
            ['label' => 'Bootstrap cache', ...$writable(base_path('bootstrap/cache'))],
            ['label' => 'Queue connection', 'value' => (string) config('queue.default'), 'ok' => null],
            ['label' => 'Pending jobs', 'value' => $pending, 'ok' => $pendingOk],
            ['label' => 'Failed jobs', 'value' => $failed, 'ok' => $failedOk && $failed === '0'],
            ['label' => 'Mail driver', 'value' => (string) config('mail.default'), 'ok' => null],
            ['label' => 'Session driver', 'value' => (string) config('session.driver'), 'ok' => null],
        ];
    }
}
