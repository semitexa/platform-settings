<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Service;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;
use Semitexa\Platform\Settings\Application\Db\MySQL\Repository\SettingRepository;
use Semitexa\Platform\Settings\Contract\SettingsStoreInterface;

#[SatisfiesServiceContract(of: SettingsStoreInterface::class)]
final class SettingsStore implements SettingsStoreInterface
{
    private const MODULE_KEY_MAX = 128;
    private const KEY_MAX = 255;

    public function get(string $moduleKey, string $key): mixed
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $setting = OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key) {
            $repo = new SettingRepository($orm->getAdapter());
            return $repo->findByModuleAndKey($moduleKey, $key);
        });

        if ($setting === null) {
            return null;
        }

        $value = $setting->value ?? '';
        if ($value === '') {
            return null;
        }

        return json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function set(string $moduleKey, string $key, mixed $value): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $encoded = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key, $encoded) {
            $repo = new SettingRepository($orm->getAdapter());
            $existing = $repo->findResourceByModuleAndKey($moduleKey, $key);

            if ($existing !== null) {
                $existing->value = $encoded;
                $repo->save($existing);
                return;
            }

            $resource = new SettingResource();
            $resource->module_key = $moduleKey;
            $resource->key = $key;
            $resource->value = $encoded;
            $resource->ensureUuid();
            $repo->save($resource);
        });
    }

    public function getAll(string $moduleKey): array
    {
        $this->validateModuleKey($moduleKey);

        $list = OrmManager::run(function (OrmManager $orm) use ($moduleKey) {
            $repo = new SettingRepository($orm->getAdapter());
            return $repo->findAllByModule($moduleKey);
        });

        $out = [];
        foreach ($list as $setting) {
            if ($setting instanceof \Semitexa\Platform\Settings\Domain\Model\Setting) {
                $decoded = $setting->value === '' ? null : json_decode($setting->value, true, 512, \JSON_THROW_ON_ERROR);
                $out[$setting->key] = $decoded;
            }
        }
        return $out;
    }

    public function remove(string $moduleKey, string $key): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key) {
            $repo = new SettingRepository($orm->getAdapter());
            $existing = $repo->findResourceByModuleAndKey($moduleKey, $key);
            if ($existing !== null) {
                $repo->delete($existing);
            }
        });
    }

    public function has(string $moduleKey, string $key): bool
    {
        return $this->get($moduleKey, $key) !== null;
    }

    private function validateModuleKey(string $moduleKey): void
    {
        if ($moduleKey === '' || strlen($moduleKey) > self::MODULE_KEY_MAX) {
            throw new \InvalidArgumentException('moduleKey must be 1-' . self::MODULE_KEY_MAX . ' characters');
        }
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::KEY_MAX) {
            throw new \InvalidArgumentException('key must be 1-' . self::KEY_MAX . ' characters');
        }
    }
}
