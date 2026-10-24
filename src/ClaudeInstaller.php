<?php

namespace Drands\DeployerSync;

/**
 * Installs into the project the files that make Claude Code keep database/sync-schema.php
 * up to date: a Stop hook running "sync:check", a skill explaining how to fill it in and a
 * test running "sync:check". Idempotent; it only writes when something is missing or outdated.
 */
class ClaudeInstaller
{
    public const HOOK_COMMAND = 'php "$CLAUDE_PROJECT_DIR/artisan" sync:check --hook';

    protected const STUBS = __DIR__ . '/../stubs';

    public function __construct(protected string $basePath)
    {
    }

    /**
     * @param bool $force Also overwrite the test if it already exists.
     * @return string[] Description of every change made.
     */
    public function install(bool $force = false): array
    {
        return array_values(array_filter([
            $this->installHook(),
            $this->installSkill(),
            $this->installTest($force),
        ]));
    }

    protected function installHook(): ?string
    {
        $path = $this->basePath . '/.claude/settings.json';
        $settings = [];

        if (file_exists($path)) {
            $settings = json_decode(file_get_contents($path), true);
            if (!is_array($settings)) {
                return null; // Don't touch a file we can't parse.
            }
        }

        $hook = ['type' => 'command', 'command' => self::HOOK_COMMAND];

        foreach ($settings['hooks']['Stop'] ?? [] as $i => $group) {
            foreach ($group['hooks'] ?? [] as $j => $existing) {
                if (str_contains($existing['command'] ?? '', 'sync:check')) {
                    if ($existing === $hook) {
                        return null;
                    }
                    $settings['hooks']['Stop'][$i]['hooks'][$j] = $hook;

                    return $this->writeJson($path, $settings) ? 'Updated the sync:check Stop hook in .claude/settings.json.' : null;
                }
            }
        }

        $settings['hooks']['Stop'][] = ['hooks' => [$hook]];

        return $this->writeJson($path, $settings) ? 'Added a sync:check Stop hook to .claude/settings.json.' : null;
    }

    protected function installSkill(): ?string
    {
        $path = $this->basePath . '/.claude/skills/sync-schema/SKILL.md';
        $content = file_get_contents(self::STUBS . '/claude/SKILL.md');

        if (file_exists($path) && file_get_contents($path) === $content) {
            return null;
        }

        $existed = file_exists($path);

        return $this->write($path, $content)
            ? ($existed ? 'Updated' : 'Added') . ' the sync-schema skill in .claude/skills/sync-schema/SKILL.md.'
            : null;
    }

    protected function installTest(bool $force): ?string
    {
        if (!is_dir($this->basePath . '/tests/Feature')) {
            return null;
        }

        $path = $this->basePath . '/tests/Feature/SyncSchemaTest.php';
        if (file_exists($path) && !$force) {
            return null;
        }

        return $this->write($path, file_get_contents(self::STUBS . '/tests/SyncSchemaTest.php'))
            ? 'Added tests/Feature/SyncSchemaTest.php.'
            : null;
    }

    protected function writeJson(string $path, array $data): bool
    {
        return $this->write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    protected function write(string $path, string $content): bool
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
            return false;
        }

        return @file_put_contents($path, $content) !== false;
    }
}
