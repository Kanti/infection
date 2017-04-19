<?php

declare(strict_types=1);

namespace Infection\Command;

use Infection\EventDispatcher\EventDispatcher;
use Infection\Process\Builder\ProcessBuilder;
use Infection\Process\Listener\MutationConsoleLoggerSubscriber;
use Infection\Process\Listener\InitialTestsConsoleLoggerSubscriber;
use Infection\Process\Runner\InitialTestsRunner;
use Infection\Process\Runner\MutationTestingRunner;
use Pimple\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InfectionCommand extends Command
{
    /**
     * @var Container
     */
    private $container;

    public function __construct(Container $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var EventDispatcher $eventDispatcher */
        $eventDispatcher = $this->get('dispatcher');

        $initialTestsProgressBar = new ProgressBar($output);
        $initialTestsProgressBar->setFormat('verbose');

        $eventDispatcher->addSubscriber(new InitialTestsConsoleLoggerSubscriber($output, $initialTestsProgressBar));
        $eventDispatcher->addSubscriber(new MutationConsoleLoggerSubscriber($output, new ProgressBar($output)));

        $adapter = $this->get('test.framework.factory')->create($input->getOption('test-framework'));
        $processBuilder = new ProcessBuilder($adapter);

        // TODO add setFormatter
        $initialTestsRunner = new InitialTestsRunner($processBuilder, $eventDispatcher);
        $result = $initialTestsRunner->run();

        if (! $result->isSuccessful()) {
            $output->writeln(
                sprintf(
                    '<error>Tests do not pass. Error code %d. "%s". STDERR: %s</error>',
                    $result->getExitCode(),
                    $result->getExitCodeText(),
                    $result->getErrorOutput()
                )
            );
            return 1;
        }

        $output->writeln('Start mutation testing...');

        // generate mutation
        $mutations = $this->get('mutations.generator')->generate();

        $threadCount = (int) $input->getOption('threads');
        $parallelProcessManager = $this->get('parallel.process.runner');
        $mutantCreator = $this->get('mutant.creator');

        $mutationTestingRunner = new MutationTestingRunner($processBuilder, $parallelProcessManager, $mutantCreator, $eventDispatcher, $mutations);
        $mutationTestingRunner->run($threadCount);
    }

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDescription('Runs the mutation testing.')
            ->addOption(
                'test-framework',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the Test framework to use (phpunit, phpspec)',
                'phpunit'
            )
            ->addOption(
                'threads',
                null,
                InputOption::VALUE_REQUIRED,
                'Threads count',
                1
            )
        ;
    }

    private function get($name)
    {
        return $this->container[$name];
    }
}