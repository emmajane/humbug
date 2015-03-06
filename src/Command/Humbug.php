<?php
/**
 * Humbug
 *
 * @category   Humbug
 * @package    Humbug
 * @copyright  Copyright (c) 2015 Pádraic Brady (http://blog.astrumfutura.com)
 * @license    https://github.com/padraic/humbug/blob/master/LICENSE New BSD License
 */

namespace Humbug\Command;

use Humbug\Config;
use Humbug\Container;
use Humbug\Adapter\Phpunit;
use Humbug\Config\JsonParser;
use Humbug\Exception\InvalidArgumentException;
use Humbug\Renderer\Text;
use Humbug\TestSuite\Mutant\Builder as MutantBuilder;
use Humbug\TestSuite\Unit\Observers\LoggingObserver;
use Humbug\TestSuite\Unit\Observers\ProgressBarObserver;
use Humbug\TestSuite\Unit\Runner as UnitTestRunner;
use Humbug\Utility\Performance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\FormatterHelper;

class Humbug extends Command
{
    protected $container;

    /**
     * @var MutantTestSuiteBuilder
     */
    protected $builder;

    private $jsonLogFile;

    private $textLogFile;

    /**
     * Execute the command.
     * The text output, other than some newline management, is held within
     * Humbug\Renderer\Text.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Performance::upMemProfiler();
        $output->writeln($this->getApplication()->getLongVersion() . PHP_EOL);

        $this->validate($input);
        $container = $this->container = new Container($input->getOptions());

        $this->doConfiguration($input);

        if ($this->isLoggingEnabled()) {
            $this->removeOldLogFiles();
        } else {
            $output->writeln('<error>No log file is specified. Detailed results will not be available.</error>');
        }

        if ($this->textLogFile) {
            $renderer = new Text($output, true);
        } else {
            $renderer = new Text($output);
        }

        /**
         * Make initial test run to ensure tests are in a starting passing state
         * and also log the results so test runs during the mutation phase can
         * be optimised.
         */
        $testSuiteRunner = new UnitTestRunner(
            $container->getAdapter(),
            $container->getAdapter()->getProcess($container, true),
            $container->getCacheDirectory() . '/coverage.humbug.txt'
        );

        $testSuiteRunner->addObserver(new LoggingObserver(
            $renderer,
            $output,
            new ProgressBarObserver($output)
        ));

        $result = $testSuiteRunner->run($container);

        /**
         * Check if the initial test run ended with a fatal error
         */
        if (! $result->isSuccess()) {
            return 1;
        }

        $output->write(PHP_EOL);

        /**
         * Message re Static Analysis
         */
        $renderer->renderStaticAnalysisStart();
        $output->write(PHP_EOL);

        $testSuite = $this->builder->build($container, $renderer, $output);
        $testSuite->run($container, $result->getCoverage());


        if ($this->isLoggingEnabled()) {
            $output->write(PHP_EOL);
        }
    }

    protected function doConfiguration()
    {
        $this->container->setBaseDirectory(getcwd());

        $config = (new JsonParser())->parseFile('humbug.json');

        $newConfig = new Config($config);

        $source = $newConfig->getSource();
        
        $this->container->setSourceList($source);

        $timeout = $newConfig->getTimeout();

        if ($timeout !== null) {
            $this->container->setTimeout((int) $timeout);
        }

        $chDir = $newConfig->getChDir();

        if ($chDir !== null) {
            $this->container->setTestRunDirectory($chDir);
        }

        $this->jsonLogFile = $newConfig->getLogsJson();
        $this->textLogFile = $newConfig->getLogsText();

        $this->builder = new MutantBuilder(
            isset($source->directories)? $source->directories : null,
            isset($source->excludes)? $source->excludes : null
        );

        $this->builder->setLogFiles($this->textLogFile, $this->jsonLogFile);
    }

    protected function prepareFinder($directories, $excludes, array $names = null)
    {
        $finder = new Finder;
        $finder->files();

        if (!is_null($names) && count($names) > 0) {
            foreach ($names as $name) {
                $finder->name($name);
            }
        } else {
            $finder->name('*.php');
        }

        if ($directories) {
            foreach ($directories as $directory) {
                $finder->in($directory);
            }
        } else {
            $finder->in('.');
        }

        if (isset($excludes)) {
            foreach ($excludes as $exclude) {
                $finder->exclude($exclude);
            }
        }

        return $finder;
    }

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDescription('Run Humbug for target tests')
            ->addOption(
               'adapter',
               'a',
               InputOption::VALUE_REQUIRED,
               'Set name of the test adapter to use.',
                'phpunit'
            )
            ->addOption(
               'options',
               'o',
               InputOption::VALUE_REQUIRED,
               'Set command line options string to pass to test adapter. '
                    . 'Default is dictated dynamically by '.'Humbug'.'.'
            )
            ->addOption(
               'file',
               'f',
               InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
               'String representing file to mutate, comprising either a glob, '
                    . 'regular expression or simple name. This will not restrict '
                    . 'the initial checking of the test suite status. You can set '
                    . 'any number of these for multiple file patterns.'
            )
            ->addOption(
               'timeout',
               't',
               InputOption::VALUE_REQUIRED,
               'Sets a timeout applied for each test run to combat infinite loop mutations.',
                10
            )
            // Preferably this should go away...
            ->addOption(
                'constraints',
                'c',
                InputOption::VALUE_REQUIRED,
                'Options set on adapter to constrain which tests are run. '
                    . 'Applies only to the very first initialising test run.'
            );
    }

    private function validate(InputInterface $input)
    {
        /**
         * Adapter
         */
        if ($input->getOption('adapter') !== 'phpunit') {
            throw new InvalidArgumentException(
                'Only a PHPUnit adapter is supported at this time. Sorry!'
            );
        }
        /**
         * Timeout
         */
        if (!is_numeric($input->getOption('timeout')) || $input->getOption('timeout') <= 0) {
            throw new InvalidArgumentException(
                'The timeout must be an integer specifying a number of seconds. '
                . 'A number greater than zero is expected, and greater than maximum '
                . 'test suite execution time under any given constraint option is '
                . 'highly recommended.'
            );
        }
    }

    private function removeOldLogFiles()
    {
        if (file_exists($this->jsonLogFile)) {
            unlink($this->jsonLogFile);
        }

        if (file_exists($this->textLogFile)) {
            unlink($this->textLogFile);
        }
    }

    private function isLoggingEnabled()
    {
        return $this->jsonLogFile !== null || $this->textLogFile !== null;
    }
}
