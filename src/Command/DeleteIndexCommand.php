<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Command;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Erikwang2013\WebmanScout\Concerns\ResolvesScoutModel;
use Erikwang2013\WebmanScout\EngineManager;

#[AsCommand(name: 'scout:delete-index', description: 'Delete an index')]
class DeleteIndexCommand extends Command
{
    use ResolvesScoutModel;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected static $defaultName = 'scout:delete-index';

    /**
     * The console command description.
     *
     * @var string
     */
    protected static $defaultDescription = 'Delete an index';

    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The name of the index');
    }
    /**
     * Execute the console command.
     *
     * @param  \Erikwang2013\WebmanScout\EngineManager  $manager
     * @return void
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $name = $input->getArgument('name');
            $manager = app(EngineManager::class);
            $manager->engine()->deleteIndex($name = $this->indexName($name));

            $output->writeln('Index "' . $name . '" deleted.');
            return Command::SUCCESS;
        } catch (Exception $exception) {
            $output->writeln($exception->getMessage());
            return Command::FAILURE;
        }
    }

}
