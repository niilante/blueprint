<?php

namespace Blueprint\Generators;

use Blueprint\Contracts\Generator;
use Blueprint\Models\Column;
use Blueprint\Models\Model;
use Illuminate\Support\Str;

class FactoryGenerator implements Generator
{
    const INDENT = '        ';

    /** @var \Illuminate\Contracts\Filesystem\Filesystem */
    private $files;

    public function __construct($files)
    {
        $this->files = $files;
    }

    public function output(array $tree): array
    {
        $output = [];

        $stub = $this->files->stub('factory.stub');

        /** @var \Blueprint\Models\Model $model */
        foreach ($tree['models'] as $model) {
            $path = $this->getPath($model);

            if (!$this->files->exists(dirname($path))) {
                $this->files->makeDirectory(dirname($path), 0755, true);
            }

            $this->files->put($path, $this->populateStub($stub, $model));

            $output['created'][] = $path;
        }

        return $output;
    }

    protected function getPath(Model $model)
    {
        $path = $model->name();
        if ($model->namespace()) {
            $path = str_replace('\\', '/', $model->namespace()) . '/' . $path;
        }

        return 'database/factories/' . $path . 'Factory.php';
    }

    protected function populateStub(string $stub, Model $model)
    {
        $stub = str_replace('DummyModel', $model->fullyQualifiedClassName(), $stub);
        $stub = str_replace('DummyClass', $model->name(), $stub);
        $stub = str_replace('// definition...', $this->buildDefinition($model), $stub);

        return $stub;
    }

    protected function buildDefinition(Model $model)
    {
        $definition = '';

        $fillable = $this->fillableColumns($model->columns());

        /** @var \Blueprint\Models\Column $column */
        foreach ($fillable as $column) {
            if ($column->name() === 'id') {
                continue;
            }

            if (in_array($column->dataType(), ['id', 'uuid'])) {
                $name = Str::beforeLast($column->name(), '_id');
                $class = Str::studly($column->attributes()[0] ?? $name);

                $definition .= self::INDENT . "'{$column->name()}' => ";
                $definition .= sprintf('factory(%s::class)', '\\' . $model->fullyQualifiedNamespace() . '\\' . $class);
                $definition .= ',' . PHP_EOL;
            } elseif (in_array($column->dataType(), ['enum', 'set']) && !empty($column->attributes())) {
                $definition .= self::INDENT . "'{$column->name()}' => ";
                $faker = $this->fakerData($column->name()) ?? $this->fakerDataType($column->dataType());
                $definition .= '$faker->' . $faker;
                $definition .= ',' . PHP_EOL;
                $definition = str_replace(
                    "/** {$column->dataType()}_attributes **/",
                    json_encode($column->attributes()),
                    $definition
                );
            } elseif (in_array($column->dataType(), ['decimal', 'float'])) {
                $definition .= self::INDENT . "'{$column->name()}' => ";
                $faker = $this->fakerData($column->name()) ?? $this->fakerDataType($column->dataType());
                $definition .= '$faker->' . $faker;
                $definition .= ',' . PHP_EOL;

                $precision = min([65, intval($column->attributes()[0] ?? 10)]);
                $scale = min([30, max([0, intval($column->attributes()[1] ?? 0)])]);

                $definition = str_replace(
                    "/** {$column->dataType()}_attributes **/",
                    implode(', ', [$scale, 0, (str_repeat(9, $precision - $scale) . '.' . str_repeat(9, $scale))]),
                    $definition
                );
            } elseif ($column->dataType() === 'json') {
                $default = $column->defaultValue() ?? "'{}'";
                $definition .= self::INDENT . "'{$column->name()}' => {$default}," . PHP_EOL;
            } elseif ($column->dataType() === 'morphs') {
                if ($column->isNullable()) {
                    continue;
                }
                $definition .= sprintf('%s%s => $faker->%s,%s', self::INDENT, "'{$column->name()}_id'", self::fakerDataType('id'), PHP_EOL);
                $definition .= sprintf('%s%s => $faker->%s,%s', self::INDENT, "'{$column->name()}_type'", self::fakerDataType('string'), PHP_EOL);
            } else {
                $definition .= self::INDENT . "'{$column->name()}' => ";
                $faker = self::fakerData($column->name()) ?? self::fakerDataType($column->dataType());
                $definition .= '$faker->' . $faker;
                $definition .= ',' . PHP_EOL;
            }
        }

        return trim($definition);
    }

    private function fillableColumns(array $columns): array
    {
        if (config('blueprint.fake_nullables')) {
            return $columns;
        }

        return array_filter($columns, function (Column $column) {
            return !in_array('nullable', $column->modifiers());
        });
    }

    public static function fakerData(string $name)
    {
        static $fakeableNames = [
            'address1' => 'streetAddress',
            'address2' => 'secondaryAddress',
            'city' => 'city',
            'company' => 'company',
            'content' => 'paragraphs(3, true)',
            'country' => 'country',
            'description' => 'text',
            'email' => 'safeEmail',
            'first_name' => 'firstName',
            'firstname' => 'firstName',
            'guid' => 'uuid',
            'last_name' => 'lastName',
            'lastname' => 'lastName',
            'lat' => 'latitude',
            'latitude' => 'latitude',
            'lng' => 'longitude',
            'longitude' => 'longitude',
            'name' => 'name',
            'password' => 'password',
            'phone' => 'phoneNumber',
            'phone_number' => 'phoneNumber',
            'postal_code' => 'postcode',
            'postcode' => 'postcode',
            'slug' => 'slug',
            'ssn' => 'ssn',
            'street' => 'streetName',
            'summary' => 'text',
            'title' => 'sentence(4)',
            'url' => 'url',
            'user_name' => 'userName',
            'username' => 'userName',
            'uuid' => 'uuid',
            'zip' => 'postcode',
        ];

        return $fakeableNames[$name] ?? null;
    }

    public static function fakerDataType(string $type)
    {
        $fakeableTypes = [
            'id' => 'randomDigitNotNull',
            'string' => 'word',
            'text' => 'text',
            'date' => 'date()',
            'time' => 'time()',
            'guid' => 'uuid',
            'uuid' => 'uuid',
            'datetimetz' => 'dateTime()',
            'datetime' => 'dateTime()',
            'timestamp' => 'dateTime()',
            'integer' => 'randomNumber()',
            'unsignedsmallinteger' => 'randomDigitNotNull',
            'bigint' => 'randomNumber()',
            'smallint' => 'randomNumber()',
            'decimal' => 'randomFloat(/** decimal_attributes **/)',
            'float' => 'randomFloat(/** float_attributes **/)',
            'longtext' => 'text',
            'boolean' => 'boolean',
            'set' => 'randomElement(/** set_attributes **/)',
            'enum' => 'randomElement(/** enum_attributes **/)',
        ];

        return $fakeableTypes[strtolower($type)] ?? null;
    }
}
