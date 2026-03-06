<?php

namespace App\Services\AiAssistant\Pipeline;

use Illuminate\Support\Str;

class SpecCompiler
{
    /**
     * Compile a natural-language CRUD request into a typed DTO.
     */
    public function compileCrud(string $prompt): CompiledSpec
    {
        $normalized = trim($prompt);
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

        $migrationFile = now()->format('Y_m_d_His')."_create_{$table}_table.php";

        $warnings = [];
        if ($normalized === '' || ! preg_match('/\b(crud|resource|module|create)\b/i', $normalized)) {
            $warnings[] = 'The request did not include a strong CRUD signal; defaults may have been applied.';
        }

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
            confident: $warnings === [],
            warnings: $warnings,
        );
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
