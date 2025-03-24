<?php

namespace Harithkhairol\ExcelMigration\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateMigrationFromExcel extends Command
{
    /**
     * Run: php artisan make:migration-from-excel
     */

    protected $signature = 'make:migration-from-excel {path=storage/spreadsheets/migration_data.xlsx : The path to the Excel file}';
    protected $description = 'Generate migration and model files from an Excel file';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $excelFile = $this->argument('path');

        if (!file_exists($excelFile)) {
            $this->error("File not found: $excelFile");
            return 1;
        }

        $spreadsheet = IOFactory::load($excelFile);
        $sheet = $spreadsheet->getActiveSheet();

        $tableData = [];
        $inverseRelationships = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $table = trim($sheet->getCell("A{$row}")->getValue());
            $column = trim($sheet->getCell("B{$row}")->getValue());
            $type = trim($sheet->getCell("C{$row}")->getValue());
            $scale = trim($sheet->getCell("D{$row}")->getValue());
            $defaultValue = trim($sheet->getCell("E{$row}")->getValue());
            $isNullable = trim(strtolower($sheet->getCell("F{$row}")->getValue())) === 'yes';
            $isUnique = trim(strtolower($sheet->getCell("G{$row}")->getValue())) === 'yes';
            $foreignKeyColumn = trim($sheet->getCell("H{$row}")->getValue());
            $foreignKeyName = trim($sheet->getCell("I{$row}")->getValue());
            $references = trim($sheet->getCell("J{$row}")->getValue());
            $onTable = trim($sheet->getCell("K{$row}")->getValue());
            $onDelete = trim(strtolower($sheet->getCell("L{$row}")->getValue()));
            $inverseRelationshipHasMany = trim(strtolower($sheet->getCell("M{$row}")->getValue())) === 'yes';
            $isIndexed = trim(strtolower($sheet->getCell("N{$row}")->getValue())) === 'yes';
            $isPolymorphic = trim(strtolower($sheet->getCell("O{$row}")->getValue())) === 'yes';
            $morphCustomIndex = trim($sheet->getCell("P{$row}")->getValue());
            $isUUID = trim(strtolower($sheet->getCell("Q{$row}")->getValue())) === 'yes'; 
            $isFile = trim(strtolower($sheet->getCell("R{$row}")->getValue())) === 'yes';

            if (!isset($tableData[$table])) {
                $tableData[$table] = [
                    'columns' => [],
                    'foreignKeys' => [],
                    'polymorphic' => [],
                    'fillable' => [],
                    'relationships' => [],
                    'indexes' => []
                ];
            }

            $tableData[$table]['columns'][] = compact('column', 'type', 'defaultValue', 'isNullable', 'isUnique', 'scale', 'isIndexed', 'morphCustomIndex');

            if ($foreignKeyColumn && $references && $onTable) {
                $tableData[$table]['foreignKeys'][] = compact('foreignKeyColumn', 'references', 'onTable', 'onDelete', 'foreignKeyName');
                $tableData[$table]['relationships'][] = [
                    'type' => 'belongsTo',
                    'name' => $onTable,
                    'column' => $foreignKeyColumn
                ];

                if ($inverseRelationshipHasMany) {

                    // Inverse relationship
                    if (!isset($inverseRelationships[$onTable])) {
                        $inverseRelationships[$onTable] = [];
                    }

                    $inverseRelationships[$onTable][] = [
                        'type' => 'hasMany',
                        'name' => $table,
                        'column' => $foreignKeyColumn
                    ];
                }
            }

            if ($isPolymorphic) {
                $tableData[$table]['polymorphic'][] = $column;
            }

            if ($column !== 'id' && !$isPolymorphic) {
                $tableData[$table]['fillable'][] = $column;
            }

            if ($isIndexed) {
                $tableData[$table]['indexes'][] = $column;
            }

            if ($isUUID) {
                $tableData[$table]['isUUID'] = true; // Track UUID requirement
            }
        }

        foreach ($tableData as $tableName => $data) {
            // Create Migration
            sleep(1);
            $timestamp = date('Y_m_d_His');
            $migrationName = "create_{$tableName}_table";
            $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");

            // $migrationClassName = Str::studly(Str::singular($tableName));

            $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
PHP;

            foreach ($data['columns'] as $col) {
                $columnString = "\$table->{$col['type']}('{$col['column']}'";

                if (!empty($col['scale'])) {
                    $columnString .= ", {$col['scale']}";
                } else if (!empty($col['morphCustomIndex'])) {
                    $columnString .= ", '{$col['morphCustomIndex']}'";
                }

                $columnString .= ")";

                if ($col['isNullable']) {
                    $columnString .= "->nullable()";
                }

                if (!empty($col['defaultValue'])) {
                    $columnString .= "->default('{$col['defaultValue']}')";
                }

                if ($col['isUnique']) {
                    $columnString .= "->unique()";
                }

                $migrationContent .= "\n            {$columnString};";
            }

            foreach ($data['foreignKeys'] as $fk) {
                $foreignKeyString = "\$table->foreign('{$fk['foreignKeyColumn']}'";

                if (!empty($fk['foreignKeyName'])) {
                    $foreignKeyString .= ", '{$fk['foreignKeyName']}'";
                }

                $foreignKeyString .= ")->references('{$fk['references']}')->on('{$fk['onTable']}')";

                if (!empty($fk['onDelete'])) {
                    $foreignKeyString .= "->onDelete('{$fk['onDelete']}')";
                }

                $migrationContent .= "\n            {$foreignKeyString};";
            }

            foreach ($data['indexes'] as $index) {
                $migrationContent .= "\n            \$table->index('{$index}');";
            }

            // foreach ($data['polymorphic'] as $polymorphicColumn) {
            //     $migrationContent .= "\n            \$table->morphs('{$polymorphicColumn}');";
            // }

            $migrationContent .= <<<PHP
\n            \$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

            file_put_contents($migrationFile, $migrationContent);
            $this->info("Migration created at: {$migrationFile}");

            // Create Model
            $modelName = Str::studly(Str::singular($tableName));
            $modelFile = app_path("Models/{$modelName}.php");

            $fillableColumns = implode("', '", $data['fillable']);
            $relationshipsContent = '';

            foreach ($data['relationships'] as $relation) {
                $relatedModel = Str::studly(Str::singular($relation['name']));
                $methodName = Str::camel(Str::singular($relation['name']));
                $relationshipsContent .= <<<PHP

    public function {$methodName}()
    {
        return \$this->{$relation['type']}({$relatedModel}::class);
    }

PHP;
            }

            foreach ($data['polymorphic'] as $polymorphicColumn) {
                $methodName = Str::camel(Str::singular($polymorphicColumn));
                $relationshipsContent .= <<<PHP

    public function {$methodName}()
    {
        return \$this->morphTo();
    }

PHP;
            }

            // Adding hasMany relationships
            if (isset($inverseRelationships[$tableName])) {
                foreach ($inverseRelationships[$tableName] as $inverseRelation) {
                    $relatedModel = Str::studly(Str::singular($inverseRelation['name']));
                    $methodName = Str::camel(Str::plural($inverseRelation['name']));
                    $relationshipsContent .= <<<PHP

    public function {$methodName}()
    {
        return \$this->{$inverseRelation['type']}({$relatedModel}::class, '{$inverseRelation['column']}');
    }

PHP;
                }
            }

            // In the model creation loop, add a boot method for UUID if required
            if (!empty($data['isUUID'])) {
                $relationshipsContent .= <<<PHP

    protected static function boot()
    {
        parent::boot();

        static::creating(function (\$model) {
            if (empty(\$model->uuid)) {
                \$model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

PHP;
            }


            $modelContent = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {$modelName} extends Model
{
    use HasFactory;

    protected \$fillable = ['{$fillableColumns}'];
    {$relationshipsContent}
}

PHP;

            file_put_contents($modelFile, $modelContent);
            $this->info("Model created at: {$modelFile}");
        }


        // generate Controller Excel Files
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue("A1", "Model");
        $sheet->setCellValue("B1", 'GenerateController (Y/N)');
        $sheet->setCellValue("C1", "FormRequest (Y/N)");
        $sheet->setCellValue("D1", "Namespace (Optional)");
        $sheet->setCellValue("E1", "Route Prefix (Optional)");
        $sheet->setCellValue("F1", "API Mode (Y/N)");

        $row = 2;
        foreach ($tableData as $tableName => $data) {
                $modelName = Str::studly(Str::singular($tableName));
                $sheet->setCellValue("A{$row}", $modelName);
                $sheet->setCellValue("B{$row}", '');
                $sheet->setCellValue("C{$row}", '');
                $sheet->setCellValue("D{$row}", '');
                $sheet->setCellValue("E{$row}", '');
                $sheet->setCellValue("F{$row}", '');
                $row++;
        }

        // Save the Excel file
        $excelPath = storage_path('spreadsheets/controller_generator.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($excelPath);

        // // this is for future enhancement
        // $this->info("Excel file created at: {$excelPath}");

        $this->info("Migration-from-excel generate successfully!");

        return 0;
    }

}
