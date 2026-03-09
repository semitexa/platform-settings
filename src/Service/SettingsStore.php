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
        return $this->getByScope($moduleKey, $key, null);
    }

    public function getForUser(string $moduleKey, string $key, string $userId): mixed
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        return $this->getByScope($moduleKey, $key, $userId);
    }

    private function getByScope(string $moduleKey, string $key, ?string $userId): mixed
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $setting = OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key, $userId) {
            $repo = new SettingRepository($orm->getAdapter());
            return $repo->findByModuleAndKey($moduleKey, $key, $userId);
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
        $this->setByScope($moduleKey, $key, $value, null);
    }

    public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        $this->setByScope($moduleKey, $key, $value, $userId);
    }

    private function setByScope(string $moduleKey, string $key, mixed $value, ?string $userId): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $encoded = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key, $encoded, $userId) {
            $repo = new SettingRepository($orm->getAdapter());
            $existing = $repo->findResourceByModuleAndKey($moduleKey, $key, $userId);

            if ($existing !== null) {
                $existing->value = $encoded;
                $repo->save($existing);
                return;
            }

            $resource = new SettingResource();
            $resource->user_id = $userId;
            $resource->module_key = $moduleKey;
            $resource->key = $key;
            $resource->value = $encoded;
            $resource->ensureUuid();
            $repo->save($resource);
        });
    }

    public function getAll(string $moduleKey): array
    {
        return $this->getAllByScope($moduleKey, null);
    }

    public function getAllForUser(string $moduleKey, string $userId): array
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        return $this->getAllByScope($moduleKey, $userId);
    }

    private function getAllByScope(string $moduleKey, ?string $userId): array
    {
        $this->validateModuleKey($moduleKey);

        $list = OrmManager::run(function (OrmManager $orm) use ($moduleKey, $userId) {
            $repo = new SettingRepository($orm->getAdapter());
            return $repo->findAllByModule($moduleKey, $userId);
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
        $this->removeByScope($moduleKey, $key, null);
    }

    public function removeForUser(string $moduleKey, string $key, string $userId): void
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        $this->removeByScope($moduleKey, $key, $userId);
    }

    private function removeByScope(string $moduleKey, string $key, ?string $userId): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key, $userId) {
            $repo = new SettingRepository($orm->getAdapter());
            $existing = $repo->findResourceByModuleAndKey($moduleKey, $key, $userId);
            if ($existing !== null) {
                $repo->delete($existing);
            }
        });
    }

    public function has(string $moduleKey, string $key): bool
    {
        return $this->existsByScope($moduleKey, $key, null);
    }

    public function hasForUser(string $moduleKey, string $key, string $userId): bool
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        return $this->existsByScope($moduleKey, $key, $userId);
    }

    private function existsByScope(string $moduleKey, string $key, ?string $userId): bool
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        return OrmManager::run(function (OrmManager $orm) use ($moduleKey, $key, $userId) {
            $repo = new SettingRepository($orm->getAdapter());
            return $repo->findResourceByModuleAndKey($moduleKey, $key, $userId) !== null;
        });
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
