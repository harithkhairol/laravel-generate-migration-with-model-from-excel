How to setup:

1) Put this inside composer.json "require":
   
           "require": {
                "harithkhairol/excel-migration": "dev-main"
            },
   
2) Put this inside composer.json "repositories":

        "repositories": [
                {
                    "type": "vcs",
                    "url": "https://github.com/harithkhairol/laravel-generate-migration-with-model-from-excel"
                }
            ]

If repositories doesn't exist, just add it at the root level (same level as require).
   
3) run:composer require harithkhairol/excel-migration:dev-main
4) php artisan vendor:publish --tag=harithkhairol-spreadsheets

How to use:

1) Open storage/spreadsheets/migration_data.xlsx
2) Explanation:

        i) Basically, this spreadsheets have 3 main columns: Table, Column, Type.
        ii) When we enter this 3 field for example:
                Table: homestays
                Column: name
                Type: string

           It will generate migration files for table homestay and the column name after running generate excel command.
   
        iii) There will be no problem if we want to add another column for the same table. Just add another row with the same table name like below:

           Table: homestays
           Column: about_us
           Type: text

           It will be generate inside the same migration files with the previous column for the table homestay .

        iv) There are multiple column next to the 3 main column that can customized your column for further needs.

   
3) Run command to generate migration files in the spreadsheets: php artisan make:migration-from-excel
        

                
   

