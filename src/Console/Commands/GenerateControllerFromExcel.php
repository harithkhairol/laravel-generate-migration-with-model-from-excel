<?php

namespace Harithkhairol\ExcelMigration\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class GenerateControllerFromExcel extends Command
{
    protected $signature = 'make:controller-from-excel 
        {path=storage/spreadsheets/controller_generator.xlsx : The path to the controller planner Excel file}';

    protected $description = 'Generate resource controllers and form requests from Excel definition file';

    public function handle()
    {
        $excelFile = $this->argument('path');

        if (!file_exists($excelFile)) {
            $this->error("File not found: $excelFile");
            return 1;
        }

        $spreadsheet = IOFactory::load($excelFile);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $model = trim($sheet->getCell("A{$row}")->getValue());
            $generateController = strtolower(trim($sheet->getCell("B{$row}")->getValue()));
            $generateFormRequest = strtolower(trim($sheet->getCell("C{$row}")->getValue()));
            $namespace = trim($sheet->getCell("D{$row}")->getValue()); // optional
            $routePrefix = trim($sheet->getCell("E{$row}")->getValue());
            $isApi = strtolower(trim($sheet->getCell("F{$row}")->getValue())) === 'y';

            if ($generateController !== 'y') {
                continue;
            }

            $controllerPath = $namespace ? "{$namespace}/{$model}Controller" : "{$model}Controller";

            // 1. Generate the resource controller
            Artisan::call('make:controller', [
                'name' => str_replace('\\', '/', $controllerPath), // Convert namespace to folder path
                '--model' => $model,
                '--resource' => true,
                '--api' => $isApi,
            ]);

            $this->info("Controller created: app/Http/Controllers/{$controllerPath}.php");

            // 2. Generate Store/Update Form Requests if required
            if ($generateFormRequest === 'y') {
                $this->generateFormRequest("Store{$model}Request", $model);
                $this->generateFormRequest("Update{$model}Request", $model);
            }

            // 3. Inject request into controller
            $this->injectRequestsIntoController($controllerPath, $model);

            // 4. Add route
            $this->registerResourceRoute($model, $controllerPath, $routePrefix, $isApi);
        }

        $this->info('Controller and FormRequest generation completed successfully.');
        return 0;

    }

    private function generateFormRequest(string $requestClass, string $model)
    {
        $namespace = "App\\Http\\Requests";
        $classPath = app_path("Http/Requests/{$requestClass}.php");

        if (!File::exists(dirname($classPath))) {
            File::makeDirectory(dirname($classPath), 0755, true);
        }

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Foundation\Http\FormRequest;

class {$requestClass} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // TODO: Add validation rules for {$model}
        ];
    }
}
PHP;

        file_put_contents($classPath, $content);
        $this->info("FormRequest created: {$classPath}");
    }

    private function injectRequestsIntoController(string $controllerPath, string $model)
    {
        $controllerFilePath = app_path("Http/Controllers/" . str_replace('\\', '/', $controllerPath) . ".php");

        if (!File::exists($controllerFilePath)) {
            $this->error("Controller not found: {$controllerPath}");
            return;
        }

        $content = File::get($controllerFilePath);

        $storeClass = "Store{$model}Request";
        $updateClass = "Update{$model}Request";

        // Inject `use` statements only if missing
        foreach (["App\\Http\\Requests\\{$storeClass}", "App\\Http\\Requests\\{$updateClass}"] as $useStatement) {
            if (!Str::contains($content, "use {$useStatement};")) {
                $content = preg_replace(
                    '/namespace\s+[^;]+;/',
                    "$0\n\nuse {$useStatement};",
                    $content,
                    1
                );
                
            }
        }

        // Replace store() method
        $content = preg_replace(
            '/public function store\([^\)]*\)\s*\{[^}]*\}/s',
            <<<PHP
    public function store({$storeClass} \$request)
        {
            \$validated = \$request->validated();
            // TODO: Store logic here
        }
    PHP,
            $content
        );

        // Replace update() method
        $content = preg_replace(
            '/public function update\([^\)]*\)\s*\{[^}]*\}/s',
            <<<PHP
    public function update({$updateClass} \$request, \\App\\Models\\{$model} \${$model})
        {
            \$validated = \$request->validated();
            // TODO: Update logic here
        }
    PHP,
            $content
        );

        File::put($controllerFilePath, $content);

        $this->info("Injected FormRequests into: {$controllerPath}");
    }


    private function registerResourceRoute(string $model, string $controllerPath, ?string $prefix = null, bool $isApi = false)
    {
        $routeFile = $isApi ? base_path('routes/api.php') : base_path('routes/web.php');
        $controllerClass = "App\\Http\\Controllers\\" . str_replace('/', '\\', $controllerPath);
        $shortController = class_basename($controllerClass);
        $uri = $prefix ?: Str::kebab(Str::plural($model));
        $routeNamePrefix = str_replace('/', '.', trim($prefix ?: $uri, '/'));

        $routeLine = "Route::resource('{$uri}', {$shortController}::class)->names('{$routeNamePrefix}');";

        $existingContent = file_get_contents($routeFile);

        // Add use statement if not already there
        if (!Str::contains($existingContent, "use {$controllerClass};")) {
            $existingContent = "<?php\n\nuse {$controllerClass};\n" . ltrim($existingContent, "<?php\n");
        }

        // Add route if not already there
        if (!Str::contains($existingContent, $routeLine)) {
            $existingContent .= "\n{$routeLine}\n";
            file_put_contents($routeFile, $existingContent);
            $this->info("Route + use added: {$routeLine}");
        } else {
            $this->info("Route already exists: {$routeLine}");
        }
    }

}