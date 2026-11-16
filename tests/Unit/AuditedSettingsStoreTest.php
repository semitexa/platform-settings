<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\AuditedSettingsStore;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * orm-gap-2-settings-store-audit: an admin page shows "changed at X by Y", so a
 * consumer kept five private settings tables with updated_by/updated_at beside
 * the store. The store now says who changed a key and when, and writes a pair
 * of keys all or none.
 */
final class AuditedSettingsStoreTest extends TestCase
{
    private SettingsStore $settings;
    private AuditedSettingsStore $audited;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE platform_settings (
                id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT,
                module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL,
                created_at TEXT, updated_at TEXT, updated_by TEXT
            )',
        );

        $this->settings = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($this->settings, $orm);

        $this->audited = new AuditedSettingsStore();
        (new \ReflectionProperty(AuditedSettingsStore::class, 'orm'))->setValue($this->audited, $orm);
        (new \ReflectionProperty(AuditedSettingsStore::class, 'settings'))->setValue($this->audited, $this->settings);
    }

    #[Test]
    public function a_write_that_names_its_author_is_described_with_it(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $this->audited->setBy('payments', 'liqpay_public', ['key' => 'pk_1'], 'user-42');

        $record = $this->audited->describe('payments', 'liqpay_public');

        self::assertNotNull($record);
        self::assertSame(['key' => 'pk_1'], $record->value);
        self::assertSame('user-42', $record->updatedBy);
        self::assertGreaterThanOrEqual($before, $record->updatedAt);
        self::assertSame(['key' => 'pk_1'], $this->settings->get('payments', 'liqpay_public'), 'the plain store reads the same value');
    }

    #[Test]
    public function the_next_author_replaces_the_previous_one(): void
    {
        $this->audited->setBy('payments', 'liqpay_public', 'pk_1', 'user-42');
        $this->audited->setBy('payments', 'liqpay_public', 'pk_2', 'user-7');

        $record = $this->audited->describe('payments', 'liqpay_public');

        self::assertNotNull($record);
        self::assertSame('pk_2', $record->value);
        self::assertSame('user-7', $record->updatedBy);
    }

    #[Test]
    public function a_plain_set_names_nobody_so_the_previous_author_is_not_kept(): void
    {
        $this->audited->setBy('payments', 'liqpay_public', 'pk_1', 'user-42');
        $this->settings->set('payments', 'liqpay_public', 'pk_from_a_script');

        $record = $this->audited->describe('payments', 'liqpay_public');

        self::assertNotNull($record);
        self::assertSame('pk_from_a_script', $record->value);
        self::assertNull($record->updatedBy, 'user-42 did not make this change');
    }

    #[Test]
    public function a_pair_is_written_whole(): void
    {
        $this->audited->setManyBy('payments', ['liqpay_public' => 'pk_1', 'liqpay_private' => 'sk_1'], 'user-42');

        self::assertSame(['liqpay_private' => 'sk_1', 'liqpay_public' => 'pk_1'], $this->sorted($this->settings->getAll('payments')));
        self::assertSame('user-42', $this->audited->describe('payments', 'liqpay_private')?->updatedBy);
        self::assertSame('user-42', $this->audited->describe('payments', 'liqpay_public')?->updatedBy);
    }

    #[Test]
    public function a_pair_whose_second_key_fails_leaves_nothing_written(): void
    {
        try {
            $this->audited->setManyBy('payments', ['liqpay_public' => 'pk_1', str_repeat('k', 256) => 'sk_1'], 'user-42');
            self::fail('a 256-character key must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('key must be 1-255 characters', $e->getMessage());
        }

        self::assertNull($this->audited->describe('payments', 'liqpay_public'), 'the first key rolled back with the second');
        self::assertSame([], $this->settings->getAll('payments'));
    }

    #[Test]
    public function an_unset_key_is_described_as_null(): void
    {
        self::assertNull($this->audited->describe('payments', 'never_set'));
    }

    #[Test]
    public function an_empty_author_is_refused_rather_than_stored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('byUserId must be null or 1-64 characters');

        $this->audited->setBy('payments', 'liqpay_public', 'pk_1', '');
    }

    #[Test]
    public function an_author_is_measured_in_characters_not_bytes(): void
    {
        $author = str_repeat('é', 64); // 64 characters, 128 bytes

        $this->audited->setBy('payments', 'liqpay_public', 'pk_1', $author);

        self::assertSame($author, $this->audited->describe('payments', 'liqpay_public')?->updatedBy);
    }

    #[Test]
    public function an_author_longer_than_its_column_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->audited->setBy('payments', 'liqpay_public', 'pk_1', str_repeat('u', 65));
            self::fail('a 65-character author must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('byUserId must be null or 1-64 characters', $e->getMessage());
        }

        self::assertNull($this->audited->describe('payments', 'liqpay_public'));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
