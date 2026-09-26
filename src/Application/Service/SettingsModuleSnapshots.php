<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

/**
 * Worker-local, short-lived snapshots of whole global-scope modules, as
 * {@see SettingsStore::getAll()} returns them.
 *
 * Why: getAll() of a site-configuration module sits on every request — the
 * tenant language pack (module `locale`, read twice during locale
 * resolution) and the site head (every page render). Without this each of
 * those is a database round trip per request, and when the database is
 * unreachable each one pays a failed connect (up to DB_CONNECT_TIMEOUT).
 *
 * Why a TTL and not an invalidation event: settings are edited at runtime
 * (admin UI, the `site-head` command) and there is no settings-changed
 * signal that reaches other workers or processes. So:
 *
 *  - a write through SettingsStore in THIS worker drops the module's snapshot
 *    at once ({@see forget()}), so the writer's next read sees its own write;
 *  - a write anywhere else (another worker, a CLI process) is seen within
 *    {@see TTL_SECONDS}. That bounded staleness is the price, and it is why
 *    only getAll() of the GLOBAL scope is cached here: per-user settings and
 *    single-key get() keep their per-request semantics ({@see SettingsReadCache});
 *  - a failed load is remembered for {@see FAILURE_TTL_SECONDS} and reported,
 *    so a database outage costs one connect attempt per module per worker per
 *    window instead of one per request. Callers already treat a throwing
 *    store as "use the defaults". Only the failure's description is kept, not
 *    the Throwable: rethrowing that one instance would log the first request's
 *    stack trace for every later request, and hold whatever its frames
 *    referenced for the whole window.
 *
 * Keyed by tenant: the store is a worker singleton serving every tenant.
 * Coroutine-safe: a load that yields (database I/O) and races a forget() is
 * not stored — the generation check keeps it from resurrecting the old rows.
 */
final class SettingsModuleSnapshots
{
    public const TTL_SECONDS = 2.0;

    public const FAILURE_TTL_SECONDS = 5.0;

    /** Bound on distinct tenant/module entries; beyond it the map starts over. */
    private const MAX_ENTRIES = 1024;

    /** @var array<string, array{expires: float, value: array<string, mixed>|string}> string = failure description */
    private array $entries = [];

    /** @var array<string, int> bumped by forget(), so an in-flight load cannot store stale rows */
    private array $generations = [];

    /** @var \Closure(): float monotonic seconds */
    private \Closure $clock;

    /** @param (\Closure(): float)|null $clock test seam; monotonic seconds */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @param \Closure(): array<string, mixed> $load
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $moduleKey, \Closure $load): array
    {
        $key = $tenantId . "\0" . $moduleKey;
        $now = ($this->clock)();

        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && $entry['expires'] > $now) {
            if (is_string($entry['value'])) {
                throw new \RuntimeException($entry['value']);
            }

            return $entry['value'];
        }

        $generation = $this->generations[$key] ?? 0;
        try {
            $value = $load();
        } catch (\Throwable $e) {
            $this->store($key, $generation, ($this->clock)() + self::FAILURE_TTL_SECONDS, sprintf(
                'Settings module "%s" is unavailable: its load failed less than %.0fs ago (%s: %s)',
                $moduleKey,
                self::FAILURE_TTL_SECONDS,
                $e::class,
                $e->getMessage(),
            ));
            throw $e;
        }
        $this->store($key, $generation, ($this->clock)() + self::TTL_SECONDS, $value);

        return $value;
    }

    /** Drop a module's snapshot after a write through the store. */
    public function forget(string $tenantId, string $moduleKey): void
    {
        $key = $tenantId . "\0" . $moduleKey;
        unset($this->entries[$key]);
        $this->generations[$key] = ($this->generations[$key] ?? 0) + 1;
    }

    /** @param array<string, mixed>|string $value */
    private function store(string $key, int $generation, float $expires, array|string $value): void
    {
        if (($this->generations[$key] ?? 0) !== $generation) {
            return;
        }
        if (count($this->entries) >= self::MAX_ENTRIES && !isset($this->entries[$key])) {
            $this->entries = [];
        }
        $this->entries[$key] = ['expires' => $expires, 'value' => $value];
    }
}
