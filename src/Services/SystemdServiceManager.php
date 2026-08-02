<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Manage allowlisted systemd units via deploy/sbin/media-manager-svc (sudo -n).
 */
final class SystemdServiceManager
{
    public const HELPER_PATH = '/usr/local/sbin/media-manager-svc';

    /** @var list<array{id: string, label: string, group: string, actions: list<string>}> */
    public const UNITS = [
        [
            'id'      => 'media-manager-scan',
            'label'   => 'Scan worker',
            'group'   => 'workers',
            'actions' => ['start', 'stop', 'restart', 'enable', 'disable'],
        ],
        [
            'id'      => 'media-manager-caption-extract',
            'label'   => 'Caption extract worker',
            'group'   => 'workers',
            'actions' => ['start', 'stop', 'restart', 'enable', 'disable'],
        ],
        [
            'id'      => 'media-manager-split-audio',
            'label'   => 'Split audio worker',
            'group'   => 'workers',
            'actions' => ['start', 'stop', 'restart', 'enable', 'disable'],
        ],
        [
            'id'      => 'apache2',
            'label'   => 'Apache',
            'group'   => 'infrastructure',
            'actions' => ['restart', 'reload'],
        ],
        [
            'id'      => 'postgresql',
            'label'   => 'PostgreSQL',
            'group'   => 'infrastructure',
            'actions' => ['restart'],
        ],
    ];

    public function helperInstalled(): bool
    {
        return is_file(self::HELPER_PATH) && is_executable(self::HELPER_PATH);
    }

    public function systemdAvailable(): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return false;
        }

        return is_dir('/run/systemd/system') || is_file('/bin/systemctl');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function readiness(): array
    {
        if (!$this->systemdAvailable()) {
            return [
                'ok'      => false,
                'message' => 'systemd is not available on this host (expected on the Debian production server).',
            ];
        }
        if (!$this->helperInstalled()) {
            return [
                'ok'      => false,
                'message' => 'Helper missing: install via setup.sh (copies deploy/sbin/media-manager-svc → /usr/local/sbin and sudoers drop-in).',
            ];
        }

        // Probe sudo -n with a read-only status call.
        $probe = $this->runHelper(['status', 'media-manager-scan']);
        if ($probe['exit_code'] !== 0 && str_contains(strtolower($probe['stderr'] . $probe['stdout']), 'password')) {
            return [
                'ok'      => false,
                'message' => 'sudo is not configured for www-data. Install deploy/sudoers/media-manager to /etc/sudoers.d/media-manager (mode 0440).',
            ];
        }

        return ['ok' => true, 'message' => 'Ready'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allStatuses(): array
    {
        $out = [];
        foreach (self::UNITS as $meta) {
            $out[] = $this->status($meta['id']) + [
                'label'   => $meta['label'],
                'group'   => $meta['group'],
                'actions' => $meta['actions'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $unit): array
    {
        $unit = $this->normalizeUnit($unit);
        $meta = $this->unitMeta($unit);
        $result = $this->runHelper(['status', $unit]);
        $props = $this->parseKeyValues($result['stdout']);

        $active = strtolower((string) ($props['ActiveState'] ?? $props['IsActive'] ?? 'unknown'));
        $enabled = strtolower((string) ($props['UnitFileState'] ?? $props['IsEnabled'] ?? 'unknown'));
        $running = in_array($active, ['active', 'reactivating'], true);

        return [
            'id'              => $unit,
            'label'           => $meta['label'] ?? $unit,
            'group'           => $meta['group'] ?? 'other',
            'actions'         => $meta['actions'] ?? [],
            'available'       => $result['exit_code'] === 0 || isset($props['ActiveState']),
            'running'         => $running,
            'active_state'    => (string) ($props['ActiveState'] ?? $props['IsActive'] ?? 'unknown'),
            'sub_state'       => (string) ($props['SubState'] ?? ''),
            'enabled_state'   => (string) ($props['UnitFileState'] ?? $props['IsEnabled'] ?? 'unknown'),
            'enabled'         => in_array($enabled, ['enabled', 'enabled-runtime', 'static', 'linked'], true),
            'main_pid'        => (int) ($props['MainPID'] ?? 0),
            'description'     => (string) ($props['Description'] ?? ''),
            'since'           => (string) ($props['ActiveEnterTimestamp'] ?? ''),
            'fragment_path'   => (string) ($props['FragmentPath'] ?? ''),
            'error'           => $result['exit_code'] === 0 ? null : trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']),
        ];
    }

    /**
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function action(string $unit, string $action): array
    {
        $unit = $this->normalizeUnit($unit);
        $action = strtolower(trim($action));
        $meta = $this->unitMeta($unit);
        if ($meta === null) {
            return ['ok' => false, 'message' => 'Unknown unit.'];
        }
        $allowed = $meta['actions'];
        // status/journal are not "actions" buttons but always allowed via dedicated endpoints.
        if (!in_array($action, $allowed, true)) {
            return ['ok' => false, 'message' => "Action '{$action}' is not allowed for {$unit}."];
        }

        $result = $this->runHelper([$action, $unit]);
        if ($result['exit_code'] !== 0) {
            $msg = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            return [
                'ok'      => false,
                'message' => $msg !== '' ? $msg : "systemctl {$action} failed (exit {$result['exit_code']}).",
            ];
        }

        return [
            'ok'      => true,
            'message' => "{$action} issued for {$unit}.",
            'status'  => $this->status($unit) + [
                'label'   => $meta['label'],
                'group'   => $meta['group'],
                'actions' => $meta['actions'],
            ],
        ];
    }

    /**
     * @return array{ok: bool, lines: list<string>, cursor: ?string, error: ?string}
     */
    public function journal(string $unit, int $lines = 120, ?string $afterCursor = null): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($this->unitMeta($unit) === null) {
            return ['ok' => false, 'lines' => [], 'cursor' => null, 'error' => 'Unknown unit.'];
        }
        $lines = max(1, min(500, $lines));
        $args = ['journal', $unit, (string) $lines];
        if ($afterCursor !== null && $afterCursor !== '') {
            if (preg_match('/^[A-Za-z0-9+\/=_;.-]+$/', $afterCursor) !== 1) {
                return ['ok' => false, 'lines' => [], 'cursor' => null, 'error' => 'Invalid journal cursor.'];
            }
            $args[] = $afterCursor;
        }

        $result = $this->runHelper($args);
        if ($result['exit_code'] !== 0) {
            $msg = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            return [
                'ok'     => false,
                'lines'  => [],
                'cursor' => null,
                'error'  => $msg !== '' ? $msg : 'journalctl failed.',
            ];
        }

        $rawLines = preg_split("/\r\n|\n|\r/", $result['stdout']) ?: [];
        $cursor = null;
        $linesOut = [];
        foreach ($rawLines as $line) {
            if (preg_match('/^-- cursor:\s*(.+)\s*$/', $line, $m) === 1) {
                $cursor = trim($m[1]);
                continue;
            }
            if ($line === '' && $linesOut === []) {
                continue;
            }
            $linesOut[] = $line;
        }
        // Drop trailing empties.
        while ($linesOut !== [] && $linesOut[array_key_last($linesOut)] === '') {
            array_pop($linesOut);
        }

        return [
            'ok'     => true,
            'lines'  => array_values($linesOut),
            'cursor' => $cursor,
            'error'  => null,
        ];
    }

    /**
     * Host / app snapshot (no privileges required).
     *
     * @return array<string, mixed>
     */
    public function systemInfo(string $projectRoot): array
    {
        $load = [null, null, null];
        if (is_readable('/proc/loadavg')) {
            $parts = preg_split('/\s+/', trim((string) file_get_contents('/proc/loadavg'))) ?: [];
            $load = [
                isset($parts[0]) ? (float) $parts[0] : null,
                isset($parts[1]) ? (float) $parts[1] : null,
                isset($parts[2]) ? (float) $parts[2] : null,
            ];
        }

        $mem = null;
        if (is_readable('/proc/meminfo')) {
            $meminfo = (string) file_get_contents('/proc/meminfo');
            $total = null;
            $avail = null;
            if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) {
                $avail = (int) $m[1] * 1024;
            }
            if ($total !== null) {
                $mem = [
                    'total_bytes'     => $total,
                    'available_bytes' => $avail,
                ];
            }
        }

        $diskTotal = @disk_total_space($projectRoot);
        $diskFree = @disk_free_space($projectRoot);

        $uptimeSeconds = null;
        if (is_readable('/proc/uptime')) {
            $u = explode(' ', trim((string) file_get_contents('/proc/uptime')));
            $uptimeSeconds = isset($u[0]) ? (int) floor((float) $u[0]) : null;
        }

        return [
            'hostname'       => gethostname() ?: 'unknown',
            'php_version'    => PHP_VERSION,
            'os'             => PHP_OS . ' ' . php_uname('r'),
            'worker_mode'    => \MediaManager\Support\WorkerMode::mode(),
            'poll_seconds'   => \MediaManager\Support\WorkerMode::pollSeconds(),
            'loadavg'        => $load,
            'memory'         => $mem,
            'disk'           => [
                'path'         => $projectRoot,
                'total_bytes'  => $diskTotal !== false ? (int) $diskTotal : null,
                'free_bytes'   => $diskFree !== false ? (int) $diskFree : null,
            ],
            'uptime_seconds' => $uptimeSeconds,
            'time'           => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param list<string> $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runHelper(array $args): array
    {
        if (!$this->helperInstalled()) {
            return [
                'exit_code' => 127,
                'stdout'    => '',
                'stderr'    => 'Helper not installed at ' . self::HELPER_PATH,
            ];
        }

        $cmd = array_merge(['sudo', '-n', self::HELPER_PATH], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            return [
                'exit_code' => 127,
                'stdout'    => '',
                'stderr'    => 'Failed to execute helper.',
            ];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'exit_code' => is_int($code) ? $code : 1,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValues(string $text): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }

        return $out;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        $unit = preg_replace('/\.service$/', '', $unit) ?? $unit;

        return $unit;
    }

    /**
     * @return array{id: string, label: string, group: string, actions: list<string>}|null
     */
    private function unitMeta(string $unit): ?array
    {
        foreach (self::UNITS as $meta) {
            if ($meta['id'] === $unit) {
                return $meta;
            }
        }

        return null;
    }
}
