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

            $controllerPath = $namespace ? "{$namespace}\\{$model}Controller" : "{$model}Controller";

            // 1. Generate the resource controller
            Artisan::call('make:controller', [
                'name' => $controllerPath,
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
        $path = app_path("Http/Controllers/" . str_replace('\\', '/', $controllerPath) . ".php");

        if (!File::exists($path)) {
            $this->error("Controller not found: {$controllerPath}");
            return;
        }

        $content = File::get($path);
        $storeMethod = <<<PHP

    public function store(\\App\\Http\\Requests\\Store{$model}Request \$request)
    {
        \$validated = \$request->validated();
        // TODO: Store logic here
    }
PHP;

        $updateMethod = <<<PHP

    public function update(\\App\\Http\\Requests\\Update{$model}Request \$request, \\App\\Models\\{$model} \${$model})
    {
        \$validated = \$request->validated();
        // TODO: Update logic here
    }
PHP;

        // Append if not already there
        if (!Str::contains($content, 'function store(')) {
            $content = str_replace('public function index(', $storeMethod . "\n\n    public function index(", $content);
        }

        if (!Str::contains($content, 'function update(')) {
            $content = str_replace('public function destroy(', $updateMethod . "\n\n    public function destroy(", $content);
        }

        File::put($path, $content);
        $this->info("Injected store/update requests into: {$controllerPath}");
        // 
    }

    private function registerResourceRoute(string $model, string $controllerPath, ?string $prefix = null, bool $isApi = false)
    {
        $routeFile = $isApi ? base_path('routes/api.php') : base_path('routes/web.php');
        $controllerClass = "App\\Http\\Controllers\\" . str_replace('/', '\\', $controllerPath);
        $uri = $prefix ?: Str::kebab(Str::plural($model));
        $routeNamePrefix = $prefix
            ? str_replace('/', '.', trim($prefix, '/'))
            : Str::kebab(Str::plural($model));

        $routeLine = "Route::resource('{$uri}', {$controllerClass}::class)->names('{$routeNamePrefix}');";

        if (!Str::contains(file_get_contents($routeFile), $routeLine)) {
            file_put_contents($routeFile, "\n{$routeLine}\n", FILE_APPEND);
            $this->info("Route added: {$routeLine}");
        } else {
            $this->info("Route already exists: {$routeLine}");
        }
    }

}