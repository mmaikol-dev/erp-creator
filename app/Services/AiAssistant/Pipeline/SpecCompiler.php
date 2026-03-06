<?php

namespace App\Services\AiAssistant\Pipeline;

use Illuminate\Support\Str;

class SpecCompiler
{
    /**
     * Compile a natural-language request into a typed DTO.
     */
    public function compileCrud(string $prompt): CompiledSpec
    {
        $normalized = trim($prompt);

        return $this->compileGenericCrud($normalized);
    }

    private function compileGenericCrud(string $normalized): CompiledSpec
    {
        $resource = $this->inferResourceName($normalized);
        $singular = Str::singular($resource);
        $plural = Str::plural($singular);
        $classBase = Str::studly($singular);
        $routeSlug = Str::kebab($plural);
        $table = Str::snake($plural);

        $fields = $this->inferFields($normalized);

        if ($fields === []) {
            $fields = $this->defaultFields();
        }

        $migrationFile = $this->migrationFileForOffset($table, 0);
        $warnings = [];

        if ($normalized === '' || ! preg_match('/\b(crud|resource|module|create)\b/i', $normalized)) {
            $warnings[] = 'The request did not include a strong CRUD signal; defaults may have been applied.';
        }

        $module = $this->buildModuleDescriptor($singular, $fields, 0);

        return new CompiledSpec(
            blueprint: 'crud_resource',
            sourcePrompt: $normalized,
            moduleName: $classBase,
            moduleNamePlural: Str::studly($plural),
            resource: $singular,
            resourcePlural: $plural,
            routeSlug: $routeSlug,
            table: $table,
            modelClass: $classBase,
            controllerClass: "{$classBase}Controller",
            policyClass: "{$classBase}Policy",
            storeRequestClass: "Store{$classBase}Request",
            updateRequestClass: "Update{$classBase}Request",
            factoryClass: "{$classBase}Factory",
            migrationFile: $migrationFile,
            fields: $fields,
            storeValidation: $this->buildValidationRules($fields, false),
            updateValidation: $this->buildValidationRules($fields, true),
            modules: [$module],
            relationships: $this->inferRelationships([$module]),
            workflows: $this->inferWorkflows($normalized, [$module]),
            roles: $this->inferRoles($normalized),
            processEngines: $this->inferProcessEngines([$module]),
            routeGroupPrefix: null,
            routeGroupName: null,
            confident: $warnings === [],
            warnings: $warnings,
        );
    }


    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     * @return array<string, mixed>
     */
    private function buildModuleDescriptor(string $resource, array $fields, int $offset): array
    {
        $singular = Str::singular($resource);
        $plural = Str::plural($singular);
        $classBase = Str::studly($singular);
        $routeSlug = Str::kebab($plural);
        $table = Str::snake($plural);

        return [
            'name' => $classBase,
            'name_plural' => Str::studly($plural),
            'resource' => $singular,
            'resource_plural' => $plural,
            'route_slug' => $routeSlug,
            'table' => $table,
            'model_class' => $classBase,
            'controller_class' => "{$classBase}Controller",
            'policy_class' => "{$classBase}Policy",
            'store_request_class' => "Store{$classBase}Request",
            'update_request_class' => "Update{$classBase}Request",
            'factory_class' => "{$classBase}Factory",
            'migration_file' => $this->migrationFileForOffset($table, $offset),
            'fields' => $fields,
            'validation' => [
                'store' => $this->buildValidationRules($fields, false),
                'update' => $this->buildValidationRules($fields, true),
            ],
        ];
    }

    private function migrationFileForOffset(string $table, int $offset): string
    {
        return now()->copy()->addSeconds($offset)->format('Y_m_d_His')."_create_{$table}_table.php";
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return list<array{name: string, from: string, to: string, type: string}>
     */
    private function inferRelationships(array $modules): array
    {
        $resources = array_map(
            static fn (array $module): string => (string) ($module['resource'] ?? ''),
            $modules
        );

        $relationships = [];

        if (in_array('appointment', $resources, true) && in_array('patient', $resources, true)) {
            $relationships[] = [
                'name' => 'appointment_patient',
                'from' => 'appointment.patient_id',
                'to' => 'patient.id',
                'type' => 'belongs_to',
            ];
        }

        if (in_array('appointment', $resources, true) && in_array('doctor', $resources, true)) {
            $relationships[] = [
                'name' => 'appointment_doctor',
                'from' => 'appointment.doctor_id',
                'to' => 'doctor.id',
                'type' => 'belongs_to',
            ];
        }

        if (in_array('medical_record', $resources, true) && in_array('patient', $resources, true)) {
            $relationships[] = [
                'name' => 'medical_record_patient',
                'from' => 'medical_record.patient_id',
                'to' => 'patient.id',
                'type' => 'belongs_to',
            ];
        }

        if (in_array('ward', $resources, true) && in_array('department', $resources, true)) {
            $relationships[] = [
                'name' => 'ward_department',
                'from' => 'ward.department_id',
                'to' => 'department.id',
                'type' => 'belongs_to',
            ];
        }

        if (in_array('billing_invoice', $resources, true) && in_array('patient', $resources, true)) {
            $relationships[] = [
                'name' => 'billing_invoice_patient',
                'from' => 'billing_invoice.patient_id',
                'to' => 'patient.id',
                'type' => 'belongs_to',
            ];
        }

        return $relationships;
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return list<array{name: string, trigger: string, steps: list<string>}>
     */
    private function inferWorkflows(string $prompt, array $modules): array
    {
        $resources = array_map(
            static fn (array $module): string => (string) ($module['resource'] ?? ''),
            $modules
        );

        $workflows = [];

        if (in_array('appointment', $resources, true)) {
            $workflows[] = [
                'name' => 'appointment_lifecycle',
                'trigger' => 'appointment.created',
                'steps' => ['schedule', 'check_in', 'consultation', 'complete'],
            ];
        }

        if (in_array('billing_invoice', $resources, true)) {
            $workflows[] = [
                'name' => 'billing_collection',
                'trigger' => 'invoice.issued',
                'steps' => ['issue', 'payment_tracking', 'reconcile'],
            ];
        }

        if ($workflows === [] && str_contains(Str::lower($prompt), 'workflow')) {
            $workflows[] = [
                'name' => 'default_record_lifecycle',
                'trigger' => 'record.created',
                'steps' => ['review', 'approve', 'close'],
            ];
        }

        return $workflows;
    }

    /**
     * @return list<string>
     */
    private function inferRoles(string $prompt): array
    {
        $matches = [];

        if (preg_match('/\broles?\s*[:\-]\s*(.+)$/im', $prompt, $matches) === 1) {
            $tail = trim((string) ($matches[1] ?? ''));
            $parts = preg_split('/,|\band\b/i', $tail) ?: [];
            $roles = [];

            foreach ($parts as $part) {
                $normalized = Str::of((string) $part)->trim()->snake()->replace('_', ' ')->value();
                if ($normalized !== '') {
                    $roles[] = $normalized;
                }
            }

            if ($roles !== []) {
                return array_values(array_unique($roles));
            }
        }

        return ['super admin', 'admin', 'manager', 'staff'];
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return list<array{name: string, entity: string, states: list<string>, transitions: list<string>}>
     */
    private function inferProcessEngines(array $modules): array
    {
        $resources = array_map(
            static fn (array $module): string => (string) ($module['resource'] ?? ''),
            $modules
        );

        $engines = [];

        if (in_array('appointment', $resources, true)) {
            $engines[] = [
                'name' => 'appointment_state_machine',
                'entity' => 'appointment',
                'states' => ['scheduled', 'checked_in', 'completed', 'cancelled'],
                'transitions' => [
                    'scheduled->checked_in',
                    'checked_in->completed',
                    'scheduled->cancelled',
                    'checked_in->cancelled',
                ],
            ];
        }

        if (in_array('billing_invoice', $resources, true)) {
            $engines[] = [
                'name' => 'invoice_state_machine',
                'entity' => 'billing_invoice',
                'states' => ['draft', 'issued', 'paid', 'void'],
                'transitions' => [
                    'draft->issued',
                    'issued->paid',
                    'draft->void',
                    'issued->void',
                ],
            ];
        }

        return $engines;
    }

    private function inferResourceName(string $prompt): string
    {
        $lower = Str::lower($prompt);

        if (preg_match('/(?:^|\b)([a-z][a-z0-9_\- ]{2,40})\s+module\b/i', $prompt, $match) === 1) {
            $candidate = trim((string) $match[1]);
            if ($candidate !== '') {
                return $this->sanitizeResourceName($candidate);
            }
        }

        if (preg_match('/\b(?:crud|resource|create)\s+(?:for\s+)?([a-z][a-z0-9_\- ]{2,40})\b/i', $prompt, $match) === 1) {
            $candidate = trim((string) $match[1]);
            if ($candidate !== '') {
                return $this->sanitizeResourceName($candidate);
            }
        }

        if (str_contains($lower, 'invoice')) {
            return 'invoice';
        }

        return 'record';
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>
     */
    private function inferFields(string $prompt): array
    {
        $matches = [];
        if (preg_match('/\bwith\s+fields?\s*[:\-]?\s*(.+)$/i', $prompt, $matches) !== 1) {
            return [];
        }

        $tail = trim((string) ($matches[1] ?? ''));
        if ($tail === '') {
            return [];
        }

        $segments = preg_split('/,|\band\b/i', $tail) ?: [];
        $fields = [];

        foreach ($segments as $segment) {
            $token = trim((string) $segment);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^([a-zA-Z][a-zA-Z0-9_\-]*)\s*[:\-]\s*([a-zA-Z]+)(\??)$/', $token, $parts) === 1) {
                $name = Str::snake((string) $parts[1]);
                $type = $this->normalizeType((string) $parts[2]);
                $nullable = (string) ($parts[3] ?? '') === '?';
                $fields[] = $this->makeField($name, $type, $nullable);
                continue;
            }

            $fields[] = $this->makeField(Str::snake($token), 'string', false);
        }

        return $this->filterFields($fields);
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     * @return list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>
     */
    private function filterFields(array $fields): array
    {
        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];

        return array_values(array_filter($fields, static function (array $field) use ($reserved): bool {
            $name = (string) ($field['name'] ?? '');

            return $name !== '' && ! in_array($name, $reserved, true);
        }));
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>
     */
    private function defaultFields(): array
    {
        return [
            $this->makeField('name', 'string', false),
            $this->makeField('description', 'text', true),
            $this->makeField('status', 'string', false, ['in:active,inactive']),
        ];
    }

    /**
     * @param  list<string>  $extraRules
     * @return array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}
     */
    private function makeField(string $name, string $type, bool $nullable, array $extraRules = []): array
    {
        $baseRules = match ($type) {
            'integer' => ['integer'],
            'decimal' => ['numeric'],
            'boolean' => ['boolean'],
            'date' => ['date'],
            'text' => ['string'],
            default => ['string', 'max:255'],
        };

        $rules = [
            ...($nullable ? ['nullable'] : ['required']),
            ...$baseRules,
            ...$extraRules,
        ];

        return [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'rules' => $rules,
            'fillable' => true,
        ];
    }

    private function sanitizeResourceName(string $resource): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9\-\_ ]+/', ' ', $resource) ?? '';
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return 'record';
        }

        $tokens = preg_split('/\s+/', $cleaned) ?: [];

        return Str::kebab(implode('-', array_slice($tokens, -2)));
    }

    private function normalizeType(string $type): string
    {
        $normalized = Str::lower(trim($type));

        return match ($normalized) {
            'int', 'integer', 'bigint', 'smallint' => 'integer',
            'float', 'double', 'decimal', 'number', 'numeric' => 'decimal',
            'bool', 'boolean' => 'boolean',
            'date', 'datetime' => 'date',
            'text', 'longtext' => 'text',
            default => 'string',
        };
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     * @return array<string, list<string>>
     */
    private function buildValidationRules(array $fields, bool $updating): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $fieldRules = array_map(
                static fn (string $rule): string => $updating && $rule === 'required' ? 'sometimes' : $rule,
                $field['rules']
            );

            $rules[$name] = $fieldRules;
        }

        return $rules;
    }
}
