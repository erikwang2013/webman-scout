<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Yii;

use Erikwang2013\WebmanScout\Command\DeleteAllIndexesCommand;
use Erikwang2013\WebmanScout\Command\DeleteIndexCommand;
use Erikwang2013\WebmanScout\Command\FlushCommand;
use Erikwang2013\WebmanScout\Command\ImportCommand;
use Erikwang2013\WebmanScout\Command\IndexCommand;
use Erikwang2013\WebmanScout\Command\QueueImportCommand;
use Erikwang2013\WebmanScout\Command\SyncIndexSettingsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Yii2 console bridge to the package's Symfony commands.
 *
 * Register in config/console.php:
 *   'controllerMap' => ['scout' => \Erikwang2013\WebmanScout\Yii\ScoutController::class]
 *
 * Usage: `yii scout/import`, `yii scout/index --name=posts` etc.
 */
class ScoutController extends \yii\console\Controller
{
    public function actionImport($model = null, $chunk = null, $fresh = false)
    {
        $options = [];
        if ($chunk !== null) {
            $options['--chunk'] = $chunk;
        }
        if ($fresh) {
            $options['--fresh'] = true;
        }

        return $this->runSymfony(ImportCommand::class, $options, ['model' => $model]);
    }

    public function actionQueueImport($model = null, $chunk = null, $min = null, $max = null)
    {
        $options = [];
        if ($chunk !== null) {
            $options['--chunk'] = $chunk;
        }
        if ($min !== null) {
            $options['--min'] = $min;
        }
        if ($max !== null) {
            $options['--max'] = $max;
        }

        return $this->runSymfony(QueueImportCommand::class, $options, ['model' => $model]);
    }

    public function actionIndex($name, $key = null)
    {
        $options = [];
        if ($key !== null) {
            $options['--key'] = $key;
        }

        return $this->runSymfony(IndexCommand::class, $options, ['name' => $name]);
    }

    public function actionSyncIndexSettings($driver = null)
    {
        $options = [];
        if ($driver !== null) {
            $options['--driver'] = $driver;
        }

        return $this->runSymfony(SyncIndexSettingsCommand::class, $options);
    }

    public function actionFlush($model = null)
    {
        return $this->runSymfony(FlushCommand::class, [], ['model' => $model]);
    }

    public function actionDeleteIndex($name)
    {
        return $this->runSymfony(DeleteIndexCommand::class, [], ['name' => $name]);
    }

    public function actionDeleteAllIndexes()
    {
        return $this->runSymfony(DeleteAllIndexesCommand::class);
    }

    protected function runSymfony(string $commandClass, array $options = [], array $arguments = []): int
    {
        $command = new $commandClass();

        $application = new Application();
        $application->add($command);
        $application->setAutoExit(false);

        $input = new ArrayInput(array_merge(['command' => $command->getName()], $options, $arguments));

        return $application->run($input, new ConsoleOutput());
    }
}
