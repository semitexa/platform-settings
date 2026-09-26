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
 *
 * Coroutine-safe, per key:
 *  - one load at a time. Coroutines that miss a key while its load is in
 *    flight wait for it and then read what it stored, so an outage costs ONE
 *    failed connect per window, not one per concurrent request, and a later
 *    failed load cannot overwrite an earlier good one;
 *  - a load that yields (database I/O) and races a forget() is not stored:
 *    forget() detaches the in-flight load, so it cannot resurrect old rows.
 * Bookkeeping lives only as long as a load is in flight, so nothing but the
 * bounded snapshot map outlives a request.
 */
final class SettingsModuleSnapshots
{
    public const TTL_SECONDS = 2.0;

    public const FAILURE_TTL_SECONDS = 5.0;

    /** A waiter gives up on a stuck load after this long and loads on its own. */
    public const WAIT_TIMEOUT_SECONDS = 10.0;

    /** Bound on distinct tenant/module entries; beyond it the map starts over. */
    private const MAX_ENTRIES = 1024;

    /** @var array<string, array{expires: float, value: array<string, mixed>|string}> string = failure description */
    private array $entries = [];

    /**
     * Loads in flight, keyed like $entries. forget() removes a key's load, which
     * is how that load learns it must not store; the entry is gone once it lands.
     *
     * @var array<string, array{token: int, gate: \Swoole\Coroutine\Channel|null}>
     */
    private array $loads = [];

    private int $nextToken = 0;

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

        while (true) {
            $entry = $this->entries[$key] ?? null;
            if ($entry !== null && $entry['expires'] > ($this->clock)()) {
                if (is_string($entry['value'])) {
                    throw new \RuntimeException($entry['value']);
                }

                return $entry['value'];
            }

            $inFlight = $this->loads[$key] ?? null;
            if ($inFlight === null || $inFlight['gate'] === null) {
                break;
            }

            // Another coroutine is loading this key: wait for it to land, then
            // re-read. Closed gate = landed (stored, or detached by a forget, in
            // which case this coroutine becomes the next loader).
            $inFlight['gate']->pop(self::WAIT_TIMEOUT_SECONDS);
            if (($this->loads[$key]['token'] ?? null) === $inFlight['token']) {
                // Still the same load after the timeout: stuck. Do not queue
                // behind it forever, and do not disturb it either.
                return $load();
            }
        }

        $token = ++$this->nextToken;
        $gate = self::inCoroutine() ? new \Swoole\Coroutine\Channel(1) : null;
        $this->loads[$key] = ['token' => $token, 'gate' => $gate];

        try {
            $value = $load();
        } catch (\Throwable $e) {
            $this->land($key, $token, $gate, ($this->clock)() + self::FAILURE_TTL_SECONDS, sprintf(
                'Settings module "%s" is unavailable: its load failed less than %.0fs ago (%s: %s)',
                $moduleKey,
                self::FAILURE_TTL_SECONDS,
                $e::class,
                $e->getMessage(),
            ));
            throw $e;
        }
        $this->land($key, $token, $gate, ($this->clock)() + self::TTL_SECONDS, $value);

        return $value;
    }

    /** Drop a module's snapshot after a write through the store. */
    public function forget(string $tenantId, string $moduleKey): void
    {
        $key = $tenantId . "\0" . $moduleKey;
        unset($this->entries[$key], $this->loads[$key]);
    }

    /** @param array<string, mixed>|string $value */
    private function land(string $key, int $token, ?\Swoole\Coroutine\Channel $gate, float $expires, array|string $value): void
    {
        // Detached by a forget() while loading (or superseded after one): what
        // this load read predates the write, so it must not become the snapshot.
        if (($this->loads[$key]['token'] ?? null) === $token) {
            unset($this->loads[$key]);
            $this->store($key, $expires, $value);
        }
        $gate?->close();
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }

    /** @param array<string, mixed>|string $value */
    private function store(string $key, float $expires, array|string $value): void
    {
        if (count($this->entries) >= self::MAX_ENTRIES && !isset($this->entries[$key])) {
            $this->entries = [];
        }
        $this->entries[$key] = ['expires' => $expires, 'value' => $value];
    }
}
