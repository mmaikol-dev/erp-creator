<?php

namespace App\Services\AiAssistant\Pipeline;

use Illuminate\Support\Str;

class CrudBlueprintGenerator
{
    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    public function buildStepArtifacts(array $spec, string $stepKey): array
    {
        return match ($stepKey) {
            'model_migration' => $this->modelMigrationArtifacts($spec),
            'http_layer' => $this->httpLayerArtifacts($spec),
            'inertia_pages' => $this->inertiaPageArtifacts($spec),
            'factory_tests' => $this->factoryTestArtifacts($spec),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function modelMigrationArtifacts(array $spec): array
    {
        $module = $this->module($spec);
        $fields = $this->fields($spec);

        $modelClass = (string) $module['model_class'];
        $table = (string) $module['table'];
        $migrationFile = (string) $module['migration_file'];

        $fillable = implode("\n", array_map(
            static fn (array $field): string => "        '".$field['name']."',",
            $fields
        ));

        $casts = $this->modelCasts($fields);
        $castsBlock = $casts === []
            ? '        return [];' : "        return [\n".implode("\n", $casts)."\n        ];";

        $modelContent = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {$modelClass} extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected \$fillable = [
{$fillable}
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
{$castsBlock}
    }
}
PHP;

        $migrationColumns = implode("\n", array_map(
            fn (array $field): string => '            '.$this->migrationColumnForField($field),
            $fields
        ));

        $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table): void {
            \$table->id();
{$migrationColumns}
            \$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;

        return [
            ['path' => "app/Models/{$modelClass}.php", 'content' => $modelContent],
            ['path' => "database/migrations/{$migrationFile}", 'content' => $migrationContent],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function httpLayerArtifacts(array $spec): array
    {
        $module = $this->module($spec);
        $validation = $this->validation($spec);

        $modelClass = (string) $module['model_class'];
        $controllerClass = (string) $module['controller_class'];
        $storeRequest = (string) $module['store_request_class'];
        $updateRequest = (string) $module['update_request_class'];
        $policyClass = (string) $module['policy_class'];
        $routeSlug = (string) $module['route_slug'];
        $pagePrefix = Str::lower($routeSlug);
        $modelVar = Str::camel($modelClass);

        $controllerContent = <<<PHP
<?php

namespace App\Http\Controllers;

use App\Http\Requests\{$storeRequest};
use App\Http\Requests\{$updateRequest};
use App\Models\{$modelClass};
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class {$controllerClass} extends Controller
{
    public function index(): Response
    {
        return Inertia::render('{$pagePrefix}/index', [
            '{$routeSlug}' => {$modelClass}::query()->latest('id')->paginate(15),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('{$pagePrefix}/create');
    }

    public function store({$storeRequest} \$request): RedirectResponse
    {
        {$modelClass}::query()->create(\$request->validated());

        return to_route('{$routeSlug}.index');
    }

    public function show({$modelClass} \${$modelVar}): Response
    {
        return Inertia::render('{$pagePrefix}/show', [
            '{$modelVar}' => \${$modelVar},
        ]);
    }

    public function edit({$modelClass} \${$modelVar}): Response
    {
        return Inertia::render('{$pagePrefix}/edit', [
            '{$modelVar}' => \${$modelVar},
        ]);
    }

    public function update({$updateRequest} \$request, {$modelClass} \${$modelVar}): RedirectResponse
    {
        \${$modelVar}->update(\$request->validated());

        return to_route('{$routeSlug}.index');
    }

    public function destroy({$modelClass} \${$modelVar}): RedirectResponse
    {
        \${$modelVar}->delete();

        return to_route('{$routeSlug}.index');
    }
}
PHP;

        $storeRules = $this->rulesAsPhpArray($validation['store'] ?? []);
        $updateRules = $this->rulesAsPhpArray($validation['update'] ?? []);

        $storeRequestContent = <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$storeRequest} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
{$storeRules}
        ];
    }
}
PHP;

        $updateRequestContent = <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$updateRequest} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
{$updateRules}
        ];
    }
}
PHP;

        $policyContent = <<<PHP
<?php

namespace App\Policies;

use App\Models\{$modelClass};
use App\Models\User;

class {$policyClass}
{
    public function viewAny(User \$user): bool
    {
        return true;
    }

    public function view(User \$user, {$modelClass} \$model): bool
    {
        return true;
    }

    public function create(User \$user): bool
    {
        return true;
    }

    public function update(User \$user, {$modelClass} \$model): bool
    {
        return true;
    }

    public function delete(User \$user, {$modelClass} \$model): bool
    {
        return true;
    }
}
PHP;

        return [
            ['path' => "app/Http/Controllers/{$controllerClass}.php", 'content' => $controllerContent],
            ['path' => "app/Http/Requests/{$storeRequest}.php", 'content' => $storeRequestContent],
            ['path' => "app/Http/Requests/{$updateRequest}.php", 'content' => $updateRequestContent],
            ['path' => "app/Policies/{$policyClass}.php", 'content' => $policyContent],
            ['path' => 'routes/web.php', 'content' => '', 'kind' => 'route_resource'],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function inertiaPageArtifacts(array $spec): array
    {
        $module = $this->module($spec);
        $fields = $this->fields($spec);

        $modelClass = (string) $module['model_class'];
        $routeSlug = (string) $module['route_slug'];
        $pluralName = (string) $module['name_plural'];
        $pageDir = Str::lower($routeSlug);
        $resourceVar = Str::camel($modelClass);

        $rowType = $this->tsTypeFields($fields);
        $formDefaults = $this->tsFormDefaults($fields, 8);
        $editDefaults = $this->tsEditDefaults($fields, $resourceVar, 8);
        $inputFields = $this->tsInputFields($fields, 4);
        $viewFields = $this->tsViewFields($fields, $resourceVar, 4);

        $indexContent = <<<TSX
import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type Row = {
    id: number;
{$rowType}
};

type PageProps = {
    {$routeSlug}: {
        data: Row[];
    };
};

export default function {$pluralName}Index() {
    const page = usePage<PageProps>();
    const rows = page.props.{$routeSlug}.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: '{$pluralName}', href: '/{$routeSlug}' }]}>
            <Head title="{$pluralName}" />

            <div className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold">{$pluralName}</h1>
                    <Link href="/{$routeSlug}/create" className="rounded-md border px-3 py-2 text-sm">
                        Create {$modelClass}
                    </Link>
                </div>

                <div className="overflow-hidden rounded-md border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-3 py-2">ID</th>
                                <th className="px-3 py-2">Details</th>
                                <th className="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((item) => (
                                <tr key={item.id} className="border-t">
                                    <td className="px-3 py-2">{item.id}</td>
                                    <td className="px-3 py-2">{JSON.stringify(item)}</td>
                                    <td className="space-x-2 px-3 py-2 text-right">
                                        <Link href={`/{$routeSlug}/\${item.id}`} className="underline">View</Link>
                                        <Link href={`/{$routeSlug}/\${item.id}/edit`} className="underline">Edit</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
TSX;

        $createContent = <<<TSX
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function Create{$modelClass}() {
    const form = useForm({
{$formDefaults}
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/{$routeSlug}');
    };

    return (
        <AppLayout breadcrumbs={[{ title: '{$pluralName}', href: '/{$routeSlug}' }, { title: 'Create', href: '/{$routeSlug}/create' }]}> 
            <Head title="Create {$modelClass}" />
            <form onSubmit={submit} className="space-y-4 p-4">
{$inputFields}
                <button className="rounded-md border px-3 py-2 text-sm" type="submit" disabled={form.processing}>
                    Save
                </button>
            </form>
        </AppLayout>
    );
}
TSX;

        $editContent = <<<TSX
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';

type PageProps = {
    {$resourceVar}: {
        id: number;
{$rowType}
    };
};

export default function Edit{$modelClass}() {
    const page = usePage<PageProps>();
    const form = useForm({
{$editDefaults}
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(`/{$routeSlug}/\${page.props.{$resourceVar}.id}`);
    };

    return (
        <AppLayout breadcrumbs={[{ title: '{$pluralName}', href: '/{$routeSlug}' }, { title: 'Edit', href: `/{$routeSlug}/\${page.props.{$resourceVar}.id}/edit` }]}> 
            <Head title="Edit {$modelClass}" />
            <form onSubmit={submit} className="space-y-4 p-4">
{$inputFields}
                <button className="rounded-md border px-3 py-2 text-sm" type="submit" disabled={form.processing}>
                    Update
                </button>
            </form>
        </AppLayout>
    );
}
TSX;

        $showContent = <<<TSX
import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type PageProps = {
    {$resourceVar}: {
        id: number;
{$rowType}
    };
};

export default function Show{$modelClass}() {
    const page = usePage<PageProps>();

    return (
        <AppLayout breadcrumbs={[{ title: '{$pluralName}', href: '/{$routeSlug}' }, { title: 'Details', href: `/{$routeSlug}/\${page.props.{$resourceVar}.id}` }]}> 
            <Head title="{$modelClass} Details" />
            <div className="space-y-3 p-4">
{$viewFields}
                <Link href={`/{$routeSlug}/\${page.props.{$resourceVar}.id}/edit`} className="underline">
                    Edit {$modelClass}
                </Link>
            </div>
        </AppLayout>
    );
}
TSX;

        return [
            ['path' => "resources/js/pages/{$pageDir}/index.tsx", 'content' => $indexContent],
            ['path' => "resources/js/pages/{$pageDir}/create.tsx", 'content' => $createContent],
            ['path' => "resources/js/pages/{$pageDir}/edit.tsx", 'content' => $editContent],
            ['path' => "resources/js/pages/{$pageDir}/show.tsx", 'content' => $showContent],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function factoryTestArtifacts(array $spec): array
    {
        $module = $this->module($spec);
        $fields = $this->fields($spec);

        $modelClass = (string) $module['model_class'];
        $factoryClass = (string) $module['factory_class'];
        $routeSlug = (string) $module['route_slug'];
        $table = (string) $module['table'];

        $factoryPairs = implode("\n", array_map(
            fn (array $field): string => "            '".$field['name']."' => ".$this->fakerValueForField($field).',',
            $fields
        ));

        $factoryContent = <<<PHP
<?php

namespace Database\Factories;

use App\Models\{$modelClass};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<{$modelClass}>
 */
class {$factoryClass} extends Factory
{
    /**
     * @var class-string<{$modelClass}>
     */
    protected \$model = {$modelClass}::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
{$factoryPairs}
        ];
    }
}
PHP;

        $payload = implode("\n", array_map(
            fn (array $field): string => "        '".$field['name']."' => ".$this->testValueForField($field).',',
            $fields
        ));

        $testContent = <<<PHP
<?php

use App\Models\{$modelClass};
use App\Models\User;

it('can list {$routeSlug}', function (): void {
    \$user = User::factory()->create();
    {$modelClass}::factory()->count(2)->create();

    \$this->actingAs(\$user)
        ->get('/{$routeSlug}')
        ->assertOk();
});

it('can create {$routeSlug}', function (): void {
    \$user = User::factory()->create();

    \$response = \$this->actingAs(\$user)
        ->post('/{$routeSlug}', [
{$payload}
        ]);

    \$response->assertRedirect(route('{$routeSlug}.index'));
    \$this->assertDatabaseCount('{$table}', 1);
});
PHP;

        return [
            ['path' => "database/factories/{$factoryClass}.php", 'content' => $factoryContent],
            ['path' => "tests/Feature/{$modelClass}CrudTest.php", 'content' => $testContent],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function module(array $spec): array
    {
        /** @var array<string, mixed> $module */
        $module = is_array($spec['module'] ?? null) ? $spec['module'] : [];

        return $module;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>
     */
    private function fields(array $spec): array
    {
        /** @var list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}> $fields */
        $fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function validation(array $spec): array
    {
        /** @var array<string, mixed> $validation */
        $validation = is_array($spec['validation'] ?? null) ? $spec['validation'] : [];

        return $validation;
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     * @return list<string>
     */
    private function modelCasts(array $fields): array
    {
        $casts = [];

        foreach ($fields as $field) {
            $cast = match ($field['type']) {
                'integer' => 'integer',
                'decimal' => 'decimal:2',
                'boolean' => 'boolean',
                'date' => 'date',
                default => null,
            };

            if ($cast !== null) {
                $casts[] = "            '".$field['name']."' => '{$cast}',";
            }
        }

        return $casts;
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}  $field
     */
    private function migrationColumnForField(array $field): string
    {
        $name = (string) $field['name'];

        $column = match ($field['type']) {
            'integer' => "\\$table->integer('{$name}')",
            'decimal' => "\\$table->decimal('{$name}', 10, 2)",
            'boolean' => "\\$table->boolean('{$name}')",
            'date' => "\\$table->date('{$name}')",
            'text' => "\\$table->text('{$name}')",
            default => "\\$table->string('{$name}')",
        };

        if ((bool) $field['nullable']) {
            $column .= '->nullable()';
        }

        return $column.';';
    }

    /**
     * @param  array<string, list<string>>  $rules
     */
    private function rulesAsPhpArray(array $rules): string
    {
        $lines = [];

        foreach ($rules as $field => $ruleSet) {
            $joined = implode("', '", $ruleSet);
            $lines[] = "            '{$field}' => ['{$joined}'],";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     */
    private function tsTypeFields(array $fields): string
    {
        return implode("\n", array_map(static function (array $field): string {
            $type = match ($field['type']) {
                'integer', 'decimal' => 'number',
                'boolean' => 'boolean',
                default => 'string',
            };

            return "    {$field['name']}: {$type};";
        }, $fields));
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     */
    private function tsFormDefaults(array $fields, int $indentSpaces): string
    {
        $indent = str_repeat(' ', $indentSpaces);

        return implode("\n", array_map(static function (array $field) use ($indent): string {
            $value = $field['type'] === 'boolean' ? 'false' : "''";

            return "{$indent}{$field['name']}: {$value},";
        }, $fields));
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     */
    private function tsEditDefaults(array $fields, string $resourceVar, int $indentSpaces): string
    {
        $indent = str_repeat(' ', $indentSpaces);

        return implode("\n", array_map(static function (array $field) use ($indent, $resourceVar): string {
            $fallback = $field['type'] === 'boolean' ? 'false' : "''";

            return "{$indent}{$field['name']}: page.props.{$resourceVar}.{$field['name']} ?? {$fallback},";
        }, $fields));
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     */
    private function tsInputFields(array $fields, int $indentSpaces): string
    {
        $indent = str_repeat(' ', $indentSpaces);

        return implode("\n", array_map(static function (array $field) use ($indent): string {
            $label = Str::headline((string) $field['name']);

            if ($field['type'] === 'boolean') {
                return <<<TSX
{$indent}<label className="flex items-center gap-2">
{$indent}    <input
{$indent}        type="checkbox"
{$indent}        checked={form.data.{$field['name']} as boolean}
{$indent}        onChange={(event) => form.setData('{$field['name']}', event.target.checked)}
{$indent}    />
{$indent}    <span>{$label}</span>
{$indent}</label>
TSX;
            }

            $inputType = match ($field['type']) {
                'integer', 'decimal' => 'number',
                'date' => 'date',
                default => 'text',
            };

            return <<<TSX
{$indent}<label className="block space-y-1">
{$indent}    <span className="text-sm">{$label}</span>
{$indent}    <input
{$indent}        className="w-full rounded-md border px-3 py-2"
{$indent}        type="{$inputType}"
{$indent}        value={form.data.{$field['name']} as string | number}
{$indent}        onChange={(event) => form.setData('{$field['name']}', event.target.value)}
{$indent}    />
{$indent}</label>
TSX;
        }, $fields));
    }

    /**
     * @param  list<array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}>  $fields
     */
    private function tsViewFields(array $fields, string $resourceVar, int $indentSpaces): string
    {
        $indent = str_repeat(' ', $indentSpaces);

        return implode("\n", array_map(static function (array $field) use ($indent, $resourceVar): string {
            $label = Str::headline((string) $field['name']);

            return "{$indent}<div><span className=\"font-medium\">{$label}:</span> {String(page.props.{$resourceVar}.{$field['name']} ?? '')}</div>";
        }, $fields));
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}  $field
     */
    private function fakerValueForField(array $field): string
    {
        return match ($field['type']) {
            'integer' => 'fake()->numberBetween(1, 999)',
            'decimal' => 'fake()->randomFloat(2, 1, 9999)',
            'boolean' => 'fake()->boolean()',
            'date' => 'fake()->date()',
            'text' => 'fake()->sentence(10)',
            default => 'fake()->words(3, true)',
        };
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, rules: list<string>, fillable: bool}  $field
     */
    private function testValueForField(array $field): string
    {
        return match ($field['type']) {
            'integer' => '12',
            'decimal' => '125.50',
            'boolean' => 'true',
            'date' => "'2026-03-06'",
            'text' => "'Sample text content'",
            default => "'Sample {$field['name']}'",
        };
    }
}
