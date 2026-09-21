<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Frontend;

use KLXM\Scheduler\I18n;

use rex;
use rex_file;
use rex_sql;

/**
 * Installiert und aktualisiert die mitgelieferten Module. Wiedererkannt werden sie am Modul-Schlüssel.
 */
final class ModuleInstaller
{
    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * @return array<string, array{name: string, description: string, installedId: ?int, outdated: bool}>
     */
    public function modules(): array
    {
        $modules = [];
        foreach (glob(rtrim($this->path, '/') . '/*/module.yml') ?: [] as $file) {
            $key = basename(dirname($file));
            $meta = rex_file::getConfig($file);
            $existing = rex_sql::factory()->getArray('SELECT id, input, output FROM ' . rex::getTable('module') . ' WHERE `key` = ?', [$key])[0] ?? null;

            $modules[$key] = [
                'name' => self::text('module_' . $key . '_name', (string) ($meta['name'] ?? $key)),
                'description' => self::text('module_' . $key . '_description', (string) ($meta['description'] ?? '')),
                'installedId' => null !== $existing ? (int) $existing['id'] : null,
                'outdated' => null !== $existing && ($existing['input'] !== $this->read($key, 'input') || $existing['output'] !== $this->read($key, 'output')),
            ];
        }

        return $modules;
    }

    /**
     * @return int ID des Moduls
     */
    public function install(string $key): int
    {
        $module = $this->modules()[$key] ?? throw new \InvalidArgumentException(sprintf('Unbekanntes Modul "%s".', $key));

        $sql = rex_sql::factory()->setTable(rex::getTable('module'));
        $sql->setValue('name', $module['name']);
        $sql->setValue('input', $this->read($key, 'input'));
        $sql->setValue('output', $this->read($key, 'output'));
        $sql->addGlobalUpdateFields();

        if (null !== $module['installedId']) {
            $sql->setWhere(['id' => $module['installedId']])->update();
            // Der Modul-Cache der Artikel muss den neuen Code bekommen.
            \rex_delete_cache();

            return $module['installedId'];
        }

        $sql->setValue('key', $key);
        $sql->addGlobalCreateFields();
        $sql->insert();

        return (int) $sql->getLastId();
    }

    private function read(string $key, string $part): string
    {
        return (string) rex_file::get(rtrim($this->path, '/') . '/' . $key . '/' . $part . '.php', '');
    }

    /** Übersetzter Text, sonst die Angabe aus der module.yml. */
    private static function text(string $key, string $fallback): string
    {
        return \rex_i18n::hasMsg('scheduler_' . $key) ? I18n::t($key) : $fallback;
    }
}
