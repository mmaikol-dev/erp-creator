<?php

use App\Models\AiConversation;
use App\Models\AiTaskRun;
use App\Models\User;
use App\Services\AiAssistant\Pipeline\CompiledSpec;
use App\Services\AiAssistant\Pipeline\CrudBlueprintGenerator;
use App\Services\AiAssistant\Pipeline\GenerationPipelineService;
use App\Services\AiAssistant\Pipeline\IO\FileWriter;
use App\Services\AiAssistant\Pipeline\IO\RouteInserter;
use App\Services\AiAssistant\Pipeline\PipelineValidator;
use App\Services\AiAssistant\Pipeline\SpecCompiler;

uses(Tests\TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

function sampleCompiledSpec(): CompiledSpec
{
    return CompiledSpec::fromLegacyArray([
        'blueprint' => 'crud_resource',
        'source_prompt' => 'create product module',
        'module' => [
            'name' => 'Product',
            'name_plural' => 'Products',
            'resource' => 'product',
            'resource_plural' => 'products',
            'route_slug' => 'products',
            'table' => 'products',
            'model_class' => 'Product',
            'controller_class' => 'ProductController',
            'policy_class' => 'ProductPolicy',
            'store_request_class' => 'StoreProductRequest',
            'update_request_class' => 'UpdateProductRequest',
            'factory_class' => 'ProductFactory',
            'migration_file' => '2026_01_01_000000_create_products_table.php',
        ],
        'fields' => [],
        'validation' => [
            'store' => [],
            'update' => [],
        ],
    ]);
}

test('route inserter injects resource route before dashboard route', function (): void {
    $routes = <<<'PHP'
<?php

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
PHP;

    $inserter = new RouteInserter();
    $result = $inserter->insert($routes, sampleCompiledSpec());

    expect($result)->toContain("Route::resource('products', \\App\\Http\\Controllers\\ProductController::class);");
    expect(substr_count($result, "Route::resource('products'"))->toBe(1);
});

test('build changes throws when artifact path is empty', function (): void {
    $specCompiler = Mockery::mock(SpecCompiler::class);
    $generator = Mockery::mock(CrudBlueprintGenerator::class);
    $validator = Mockery::mock(PipelineValidator::class);
    $fileWriter = Mockery::mock(FileWriter::class);

    $service = new GenerationPipelineService(
        $specCompiler,
        $generator,
        $validator,
        $fileWriter,
        new RouteInserter(),
    );

    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('buildChanges');
    $method->setAccessible(true);

    $method->invoke($service, [['path' => '']], sampleCompiledSpec(), 'http_layer');
})->throws(RuntimeException::class, 'empty path');

test('assert owned fails when conversation relation is missing', function (): void {
    $specCompiler = Mockery::mock(SpecCompiler::class);
    $generator = Mockery::mock(CrudBlueprintGenerator::class);
    $validator = Mockery::mock(PipelineValidator::class);
    $fileWriter = Mockery::mock(FileWriter::class);

    $service = new GenerationPipelineService(
        $specCompiler,
        $generator,
        $validator,
        $fileWriter,
        new RouteInserter(),
    );

    $user = new User();
    $user->id = 10;

    $run = new AiTaskRun();
    $run->setRelation('conversation', null);

    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('assertOwned');
    $method->setAccessible(true);

    $method->invoke($service, $user, $run);
})->throws(RuntimeException::class, 'no associated conversation');

test('approve current step rejects paused pipeline run', function (): void {
    $specCompiler = Mockery::mock(SpecCompiler::class);
    $generator = Mockery::mock(CrudBlueprintGenerator::class);
    $validator = Mockery::mock(PipelineValidator::class);
    $fileWriter = Mockery::mock(FileWriter::class);

    $service = new GenerationPipelineService(
        $specCompiler,
        $generator,
        $validator,
        $fileWriter,
        new RouteInserter(),
    );

    $user = new User();
    $user->id = 10;

    $conversation = new AiConversation();
    $conversation->user_id = 10;

    $run = new AiTaskRun();
    $run->status = 'paused';
    $run->current_step_index = 0;
    $run->plan = [[
        'status' => 'preview_ready',
        'changes' => [],
    ]];
    $run->setRelation('conversation', $conversation);

    $service->approveCurrentStep($user, $run);
})->throws(RuntimeException::class, "Expected 'needs_approval'");
