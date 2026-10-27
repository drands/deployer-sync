<?php

namespace Drands\DeployerSync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class SyncLocalAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:local-admin
        {--create : Create a new admin instead of updating an existing one}
        {--id= : Primary key of the user to update (defaults to deployer-sync.local_admin.id)}
        {--field=* : Value for one of the configured fields, as "name=value" (defaults to deployer-sync.local_admin.fields)}
        {--defaults : Print the configured fields and their defaults as JSON (used by the Deployer recipe) and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates or updates a local admin user after importing production data. Never runs in production.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('defaults')) {
            $this->line(json_encode([
                'id' => (string) $this->config('id', 1),
                'ask_defaults' => (bool) $this->config('ask_defaults', true),
                'fields' => $this->config('fields', []),
            ]));

            return self::SUCCESS;
        }

        if ($this->laravel->environment('production')) {
            $this->error('sync:local-admin cannot run in production.');

            return self::FAILURE;
        }

        $modelClass = $this->config('model');
        if (!is_string($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            $this->error('deployer-sync.local_admin.model must be an Eloquent model class.');

            return self::FAILURE;
        }

        $values = $this->values();
        if ($values === null) {
            return self::FAILURE;
        }

        return $this->option('create')
            ? $this->create(new $modelClass(), $values)
            : $this->update($modelClass, $this->option('id') ?: $this->config('id', 1), $values);
    }

    /**
     * @param array<string, string> $values
     */
    protected function create(Model $user, array $values)
    {
        $missing = array_diff(array_keys($this->config('fields', [])), array_keys($values));
        if ($missing) {
            $this->error('Missing values to create an admin: ' . implode(', ', $missing) . '.');

            return self::FAILURE;
        }

        if ($this->emailIsTaken($user, $values)) {
            return self::FAILURE;
        }

        $this->persist($user, $values);
        $this->info('Admin #' . $user->getKey() . ' created.');

        return self::SUCCESS;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, string> $values
     */
    protected function update(string $modelClass, $id, array $values)
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
        $user = ($softDeletes ? $modelClass::withTrashed() : $modelClass::query())->find($id);

        if (!$user) {
            $this->error("User #{$id} does not exist. Use --create to add a new admin.");

            return self::FAILURE;
        }

        if ($this->emailIsTaken($user, $values)) {
            return self::FAILURE;
        }

        if ($softDeletes) {
            $user->setAttribute($user->getDeletedAtColumn(), null);
        }

        $this->persist($user, $values);
        $this->info('Admin #' . $user->getKey() . ' updated.');

        return self::SUCCESS;
    }

    /**
     * Saves the field values (hashing the password), the "attributes" forced on every admin
     * and nulls the "reset" columns (remember tokens, 2FA secrets...).
     *
     * @param array<string, string> $values
     */
    protected function persist(Model $user, array $values)
    {
        if (isset($values['password'])) {
            $values['password'] = Hash::make($values['password']);
        }

        $user->forceFill(array_merge(
            $values,
            $this->config('attributes', []),
            array_fill_keys($this->config('reset', []), null)
        ))->save();
    }

    /**
     * @param array<string, string> $values
     */
    protected function emailIsTaken(Model $user, array $values)
    {
        if (!isset($values['email'])) {
            return false;
        }

        $taken = $user->newQueryWithoutScopes()
            ->where('email', $values['email'])
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();

        if ($taken) {
            $this->error("Another user already uses {$values['email']}.");
        }

        return $taken;
    }

    /**
     * Non-empty values of the configured fields: from --field, falling back to the config defaults.
     *
     * @return array<string, string>|null Null if --field names a field that isn't configured.
     */
    protected function values()
    {
        $fields = $this->config('fields', []);

        foreach ($this->option('field') as $option) {
            [$name, $value] = array_pad(explode('=', $option, 2), 2, '');
            if (!array_key_exists($name, $fields)) {
                $this->error("Unknown field \"{$name}\". Configured fields: " . implode(', ', array_keys($fields)) . '.');

                return null;
            }
            $fields[$name] = $value;
        }

        return array_filter($fields, fn ($value) => $value !== null && $value !== '');
    }

    protected function config(string $key, $default = null)
    {
        return config('deployer-sync.local_admin.' . $key, $default);
    }
}
