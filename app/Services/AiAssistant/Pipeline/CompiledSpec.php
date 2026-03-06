<?php

namespace App\Services\AiAssistant\Pipeline;

final class CompiledSpec
{
    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     * @param  array<string, list<string>>  $storeValidation
     * @param  array<string, list<string>>  $updateValidation
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $blueprint,
        public readonly string $sourcePrompt,
        public readonly string $moduleName,
        public readonly string $moduleNamePlural,
        public readonly string $resource,
        public readonly string $resourcePlural,
        public readonly string $routeSlug,
        public readonly string $table,
        public readonly string $modelClass,
        public readonly string $controllerClass,
        public readonly string $policyClass,
        public readonly string $storeRequestClass,
        public readonly string $updateRequestClass,
        public readonly string $factoryClass,
        public readonly string $migrationFile,
        public readonly array $fields,
        public readonly array $storeValidation,
        public readonly array $updateValidation,
        public readonly bool $confident = true,
        public readonly array $warnings = [],
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'blueprint' => $this->blueprint,
            'source_prompt' => $this->sourcePrompt,
            'module' => [
                'name' => $this->moduleName,
                'name_plural' => $this->moduleNamePlural,
                'resource' => $this->resource,
                'resource_plural' => $this->resourcePlural,
                'route_slug' => $this->routeSlug,
                'table' => $this->table,
                'model_class' => $this->modelClass,
                'controller_class' => $this->controllerClass,
                'policy_class' => $this->policyClass,
                'store_request_class' => $this->storeRequestClass,
                'update_request_class' => $this->updateRequestClass,
                'factory_class' => $this->factoryClass,
                'migration_file' => $this->migrationFile,
            ],
            'fields' => $this->fields,
            'validation' => [
                'store' => $this->storeValidation,
                'update' => $this->updateValidation,
            ],
            'confident' => $this->confident,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacy
     */
    public static function fromLegacyArray(array $legacy): self
    {
        /** @var array<string, mixed> $module */
        $module = is_array($legacy['module'] ?? null) ? $legacy['module'] : [];
        /** @var array<string, mixed> $validation */
        $validation = is_array($legacy['validation'] ?? null) ? $legacy['validation'] : [];

        /** @var list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}> $fields */
        $fields = is_array($legacy['fields'] ?? null) ? $legacy['fields'] : [];
        /** @var array<string, list<string>> $store */
        $store = is_array($validation['store'] ?? null) ? $validation['store'] : [];
        /** @var array<string, list<string>> $update */
        $update = is_array($validation['update'] ?? null) ? $validation['update'] : [];

        /** @var list<string> $warnings */
        $warnings = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            is_array($legacy['warnings'] ?? null) ? $legacy['warnings'] : []
        ), static fn (string $item): bool => $item !== ''));

        return new self(
            blueprint: (string) ($legacy['blueprint'] ?? 'crud_resource'),
            sourcePrompt: (string) ($legacy['source_prompt'] ?? ''),
            moduleName: (string) ($module['name'] ?? 'Record'),
            moduleNamePlural: (string) ($module['name_plural'] ?? 'Records'),
            resource: (string) ($module['resource'] ?? 'record'),
            resourcePlural: (string) ($module['resource_plural'] ?? 'records'),
            routeSlug: (string) ($module['route_slug'] ?? 'records'),
            table: (string) ($module['table'] ?? 'records'),
            modelClass: (string) ($module['model_class'] ?? 'Record'),
            controllerClass: (string) ($module['controller_class'] ?? 'RecordController'),
            policyClass: (string) ($module['policy_class'] ?? 'RecordPolicy'),
            storeRequestClass: (string) ($module['store_request_class'] ?? 'StoreRecordRequest'),
            updateRequestClass: (string) ($module['update_request_class'] ?? 'UpdateRecordRequest'),
            factoryClass: (string) ($module['factory_class'] ?? 'RecordFactory'),
            migrationFile: (string) ($module['migration_file'] ?? now()->format('Y_m_d_His').'_create_records_table.php'),
            fields: $fields,
            storeValidation: $store,
            updateValidation: $update,
            confident: (bool) ($legacy['confident'] ?? true),
            warnings: $warnings,
        );
    }
}
