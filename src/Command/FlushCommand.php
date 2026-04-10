<?php

namespace Erikwang2013\WebmanScout\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Erikwang2013\WebmanScout\Concerns\ResolvesScoutModel;
use Erikwang2013\WebmanScout\Exceptions\ScoutException;

class FlushCommand extends Command
{
    use ResolvesScoutModel;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected static $defaultName = 'scout:flush';

    /**
     * The console command description.
     *
     * @var string
     */
    protected static $defaultDescription = "Flush all of the model's records from the index";

    protected function configure()
    {
        $this->addArgument('model', InputArgument::OPTIONAL, '模型');
    }
    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws ScoutException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $this->resolveModelClass((string) $input->getArgument('model'));

        $model = new $class;

        $model::removeAllFromSearch();

        $output->writeln('All [' . $class . '] records have been flushed.');
        return Command::SUCCESS;
    }
}
