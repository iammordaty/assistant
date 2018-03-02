<?php

namespace Assistant\Module\Collection\Task;

use Assistant\Module\Common\Task\AbstractTask;
use Assistant\Module\Common\Repository\AbstractObjectRepository;
use Assistant\Module\Directory\Repository\DirectoryRepository;
use Assistant\Module\Track\Repository\TrackRepository;
use Assistant\Module\File\Extension\SplFileInfo;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task przenoszący gotowe (otagowane) utwory do odpowiednich katalogów
 */
class MoverTask extends AbstractTask
{
    /**
     * Tablica asocjacyjna zawierająca statystyki zadania
     *
     * @var array
     */
    private $stats;

    /**
     * @var array
     */
    private $parameters;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->parameters = $this->app->container->parameters['collection'];

        $this
            ->setName('collection:move')
            ->setDescription('Move new and tagged tracks to target directories')
            ->addArgument(
                'pathname',
                InputArgument::REQUIRED,
                'Pathname to move'
            )->addArgument(
                'targetPathname',
                InputArgument::REQUIRED,
                'Target pathname'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->stats = [ ];
    }

    /**
     * Rozpoczyna proces usuwania przenoszenia podanego elementu
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->app->log->info('Task executed', array_merge($input->getArguments(), $input->getOptions()));

        $rootDir = $this->app->container->parameters['collection']['root_dir'];

        $element = new SplFileInfo(
            $input->getArgument('pathname'),
            str_replace(sprintf('%s/', $rootDir), '', $input->getArgument('pathname'))
        );

        if (file_exists($element->getPathname()) === false) {
            throw new \Exception("Element {$target->getPathname()} does not exists!");
        }

        $target = new SplFileInfo(
            $input->getArgument('targetPathname'),
            str_replace(sprintf('%s/', $rootDir), '', $input->getArgument('targetPathname'))
        );

        if ($target->isFile() === true && file_exists($target->getPathname()) === true) {
    		throw new \Exception("Target {$target->getPathname()} already exists!");
    	}

        if (file_exists($target->getPath()) === false && mkdir($target->getPath(), 0777, true) === false) {
			throw new \Exception("Can\'t create directory {$target->getPath()}.");
		}

    	if (rename($element->getPathname(), $target->getPathname()) === false) {
    		throw new Exception("Can\'t move {$element->getPathname()} to {$target->getPathname()}.");
    	}

        $this->app->log->info('Task finished', $this->stats);

        unset($input, $output);
    }
}
