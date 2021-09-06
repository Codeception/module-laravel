<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use RuntimeException;

trait InteractsWithEloquent
{

    /**
     * Checks that record does not exist in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ```php
     * <?php
     * $I->dontSeeRecord('users', ['name' => 'davert']);
     * $I->dontSeeRecord('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function dontSeeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if ($foundMatchingRecord = (bool)$this->findModel($table, $attributes)) {
                $this->fail("Unexpectedly found matching $table with " . json_encode($attributes));
            }
        } elseif ($foundMatchingRecord = (bool)$this->findRecord($table, $attributes)) {
            $this->fail("Unexpectedly found matching record in table '$table'");
        }

        $this->assertFalse($foundMatchingRecord);
    }

    /**
     * Retrieves number of records from database
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->grabNumRecords('users', ['name' => 'davert']);
     * $I->grabNumRecords('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return int
     * @part orm
     */
    public function grabNumRecords(string $table, array $attributes = []): int
    {
        return class_exists($table) ? $this->countModels($table, $attributes) : $this->countRecords($table, $attributes);
    }

    /**
     * Retrieves record from database
     * If you pass the name of a database table as the first argument, this method returns an array.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ``` php
     * <?php
     * $record = $I->grabRecord('users', ['name' => 'davert']); // returns array
     * $record = $I->grabRecord('App\Models\User', ['name' => 'davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return array|EloquentModel
     * @part orm
     */
    public function grabRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            if (! $model = $this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }

            return $model;
        }

        if (! $record = $this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        return $record;
    }

    /**
     * Use Laravel model factory to create a model.
     *
     * ``` php
     * <?php
     * $I->have('App\Models\User');
     * $I->have('App\Models\User', ['name' => 'John Doe']);
     * $I->have('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function have(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            $model = $this->modelFactory($model, $name)->create($attributes);

            // In Laravel 6 the model factory returns a collection instead of a single object
            if ($model instanceof Collection) {
                $model = $model[0];
            }

            return $model;
        } catch (Exception $e) {
            $this->fail('Could not create model: \n\n' . get_class($e) . '\n\n' . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to create multiple models.
     *
     * ``` php
     * <?php
     * $I->haveMultiple('App\Models\User', 10);
     * $I->haveMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->haveMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function haveMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->create($attributes);
        } catch (Exception $e) {
            $this->fail("Could not create model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Inserts record into the database.
     * If you pass the name of a database table as the first argument, this method returns an integer ID.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ```php
     * <?php
     * $user_id = $I->haveRecord('users', ['name' => 'Davert']); // returns integer
     * $user = $I->haveRecord('App\Models\User', ['name' => 'Davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array  $attributes
     * @return EloquentModel|int
     * @throws RuntimeException
     * @part orm
     */
    public function haveRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            $model = new $table;

            if (! $model instanceof EloquentModel) {
                throw new RuntimeException("Class $table is not an Eloquent model");
            }

            $model->fill($attributes)->save();

            return $model;
        }

        try {
            /** @var DatabaseManager $dbManager */
            $dbManager = $this->app['db'];
            return $dbManager->table($table)->insertGetId($attributes);
        } catch (Exception $e) {
            $this->fail("Could not insert record into table '$table':\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to make a model instance.
     *
     * ``` php
     * <?php
     * $I->make('App\Models\User');
     * $I->make('App\Models\User', ['name' => 'John Doe']);
     * $I->make('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function make(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to make multiple model instances.
     *
     * ``` php
     * <?php
     * $I->makeMultiple('App\Models\User', 10);
     * $I->makeMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->makeMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function makeMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Checks that number of given records were found in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->seeNumRecords(1, 'users', ['name' => 'davert']);
     * $I->seeNumRecords(1, 'App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param int $expectedNum
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeNumRecords(int $expectedNum, string $table, array $attributes = []): void
    {
        if (class_exists($table)) {
            $currentNum = $this->countModels($table, $attributes);
            $this->assertEquals(
                $expectedNum,
                $currentNum,
                "The number of found {$table} ({$currentNum}) does not match expected number {$expectedNum} with " . json_encode($attributes)
            );
        } else {
            $currentNum = $this->countRecords($table, $attributes);
            $this->assertEquals(
                $expectedNum,
                $currentNum,
                "The number of found records in table {$table} ({$currentNum}) does not match expected number $expectedNum with " . json_encode($attributes)
            );
        }
    }

    /**
     * Checks that record exists in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->seeRecord('users', ['name' => 'davert']);
     * $I->seeRecord('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if (! $foundMatchingRecord = (bool)$this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }
        } elseif (! $foundMatchingRecord = (bool)$this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        $this->assertTrue($foundMatchingRecord);
    }

    protected function countModels(string $modelClass, array $attributes = []): int
    {
        $query = $this->buildQuery($modelClass, $attributes);
        return $query->count();
    }

    protected function countRecords(string $table, array $attributes = []): int
    {
        $query = $this->buildQuery($table, $attributes);
        return $query->count();
    }

    /**
     * @param string $modelClass
     * @param array $attributes
     *
     * @return EloquentModel
     */
    protected function findModel(string $modelClass, array $attributes = [])
    {
        $query = $this->buildQuery($modelClass, $attributes);

        return $query->first();
    }

    protected function findRecord(string $table, array $attributes = []): array
    {
        $query = $this->buildQuery($table, $attributes);
        return (array) $query->first();
    }

    /**
     * @param string $model
     * @param string $name
     * @param int $times
     * @return FactoryBuilder|\Illuminate\Database\Eloquent\Factories\Factory
     */
    protected function modelFactory(string $model, string $name, $times = 1)
    {
        if (version_compare(Application::VERSION, '7.0.0', '<')) {
            return factory($model, $name, $times);
        }

        return $model::factory()->count($times);
    }

    /**
     * Build Eloquent query with attributes
     *
     * @param string $table
     * @param array $attributes
     * @return EloquentModel
     * @part orm
     */
    private function buildQuery(string $table, $attributes = [])
    {
        if (class_exists($table)) {
            $query = $this->getQueryBuilderFromModel($table);
        } else {
            $query = $this->getQueryBuilderFromTable($table);
        }

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                call_user_func_array(array($query, 'where'), $value);
            } elseif (is_null($value)) {
                $query->whereNull($key);
            } else {
                $query->where($key, $value);
            }
        }
        return $query;
    }

    /**
     * @param string $modelClass
     *
     * @return EloquentModel
     * @throws RuntimeException
     */
    protected function getQueryBuilderFromModel(string $modelClass)
    {
        $model = new $modelClass;

        if (!$model instanceof EloquentModel) {
            throw new RuntimeException("Class $modelClass is not an Eloquent model");
        }

        return $model->newQuery();
    }

    /**
     * @param string $table
     *
     * @return EloquentModel
     */
    protected function getQueryBuilderFromTable(string $table)
    {
        return $this->app['db']->table($table);
    }
}
