<?php

namespace Assistant\Module\Track\Task;

use Assistant\Module\Common;
use Assistant\Module\Common\Task\AbstractTask;
use Assistant\Module\Common\Extension\GetId3\Exception\WriterException;
use Assistant\Module\File\Extension\PathFilterIterator;
use Assistant\Module\File\Extension\RecursiveDirectoryIterator;
use Assistant\Module\File\Extension\SplFileInfo;
use Assistant\Module\Track\Extension\Exception\BackendAudioDataCalculatorException;

use Curl\Curl;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Task mający za zadanie obliczenie tonacji oraz liczby uderzeń na mintutę
 * w zadanym pliku (utworze muzycznym)
 */
class AudioDataCalculatorTask extends AbstractTask
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
            ->setName('track:calculate-audio-data')
            ->setDescription('Calculates BPM and initial key for track(s)')
            ->addArgument(
                'pathname',
                InputArgument::OPTIONAL,
                'Pathname to search tracks to calculate BPM and initial key',
                $this->parameters['root_dir']
            )->addOption(
                'skip-calculated',
                's',
                InputOption::VALUE_NONE,
                'Skip tracks with calculated BPM and initial key field'
            )->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Process directories recursively')
            ->addOption('write-data', 'w', InputOption::VALUE_NONE, 'Write BPM and initial key to track metadata');
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->stats = [
            'processed' => [ 'file' => 0, 'dir' => 0 ],
            'updated' => 0,
            'mismatch' => [ 'initial_key' => 0, 'bpm' => 0 ],
            'skipped' => [ 'too_long' => 0,  'already_calculated' => 0, 'same_data' => 0 ],
            'error' => [ 'backend' => 0, 'tags' => 0 ],
        ];
    }

    /**
     * Rozpoczyna proces indeksowania kolekcji
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->app->log->info('Task executed', array_merge($input->getArguments(), $input->getOptions()));

        $id3 = new Common\Extension\GetId3\Adapter();

        $skipCalculated = $input->getOption('skip-calculated');
        $writeData = $input->getOption('write-data');

        $iterator = $this->getIterator($input->getArgument('pathname'), $input->getOption('recursive'));

        foreach ($iterator as $node) {
            if ($node->isDir() === true) {
                $this->stats['processed']['dir']++;

                unset($node);

                continue;
            }

            $this->app->log->info('Processing track', [ 'pathname' => $node->getPathname() ]);

            $this->stats['processed']['file']++;

            try {
                $metadata = $id3
                    ->setFile($node)
                    ->readId3v2Metadata();

                if ($id3->getTrackLength() / 60 > 20) {
                    $this->stats['skipped']['too_long']++;

                    $this->app->log->info(
                        'Track is too long, skipping...',
                        [ 'length' => $id3->getTrackLength() / 60 ]
                    );

                    unset($node, $metadata);

                    continue;
                }

                $isCalculated = isset($metadata['bpm']) === true && isset($metadata['initial_key']) === true;

                if ($skipCalculated === true && $isCalculated === true) {
                    $this->stats['skipped']['already_calculated']++;

                    $this->app->log->info(
                        'Track is already calculated (bpm and initial_key exists), skipping',
                        [ 'bpm' => $metadata['bpm'], 'initial_key' => $metadata['initial_key'] ]
                    );

                    unset($node, $metadata);

                    continue;
                }

                $audioData = $this->calculateAudioData($node);

                if ($this->isTrackHasSameData($metadata, $audioData) === true) {
                    $this->stats['skipped']['same_data']++;

                    $this->app->log->info('Track has the same audio data, update is not necessary', $audioData);

                    unset($node, $metadata, $audioData);

                    continue;
                }

                if ($metadata['initial_key'] !== $audioData['initial_key']) {
                    $this->stats['mismatch']['initial_key']++;
                }
                if ($metadata['bpm'] !== $audioData['bpm']) {
                    $this->stats['mismatch']['bpm']++;
                }

                $this->app->log->info(
                    sprintf('%s track audio data', ($writeData === true) ? 'Updating' : 'Calculated'),
                    [
                        'audioData' => $audioData,
                        'metadata' => [ 'bpm' => $metadata['bpm'], 'initial_key' => $metadata['initial_key'] ],
                    ]
                );

                if ($writeData === true) {
                    $id3->writeId3v2Metadata($audioData);

                    if ($id3->getWriterWarnings()) {
                        $this->app->log->warning('Track metadata saved with warnings', $id3->getWriterWarnings());
                    }

                    $this->stats['updated']++;
                }

                $this->app->log->info('Track processing completed successfully');
            } catch (BackendAudioDataCalculatorException $e) {
                $this->stats['error']['backend']++;

                $this->app->log->error(
                    $e->getMessage(),
                    [ 'pathname' => $node->getPathname(), 'metadata' => $metadata ]
                );
            } catch (WriterException $e) {
                $this->stats['error']['tags']++;

                $this->app->log->error(
                    $e->getMessage(),
                    [
                        'pathname' => $node->getPathname(),
                        'metadata' => $metadata,
                        'audioData' => $audioData,
                        'id3WriterErrors' => $id3->getWriterErrors(),
                        'id3WriterWarnings' => $id3->getWriterWarnings(),
                    ]
                );
            } finally {
                unset($node, $metadata, $audioData);
            }
        }

        $this->app->log->info('Task finished', $this->stats);

        unset($input, $output, $id3, $iterator);
    }

    /**
     * @param string $pathname
     * @param bool $recursive
     * @return PathFilterIterator|\RecursiveIteratorIterator|\ArrayIterator
     */
    private function getIterator($pathname, $recursive)
    {
        if (is_file($pathname)) {
            $relativePathname = str_replace(sprintf('%s/', $this->parameters['root_dir']), '', $pathname);

            return new \ArrayIterator([ new SplFileInfo($pathname, $relativePathname) ]);
        }

        $iterator = new PathFilterIterator(
            new RecursiveDirectoryIterator($pathname),
            $this->parameters['root_dir'],
            [ ]
        );

        if ($recursive === true) {
            $iterator = new \RecursiveIteratorIterator(
                $iterator,
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        }

        return $iterator;
    }

    /**
     * @param SplFileInfo $node
     * @return array
     * @throws \RuntimeException
     */
    private function calculateAudioData(SplFileInfo $node)
    {
        $curl = new Curl();
        $curl->setTimeout(600);

        $response = (array) $curl->get(
            sprintf(
                '%s/track/%s',
                'http://assistant-backend',
                rawurlencode(ltrim($node->getRelativePathname(), DIRECTORY_SEPARATOR))
            )
        );

        if ($curl->error === true) {
            $message = '';

            if (isset($response['command']) === true) {
                $message .= sprintf('%s: ', $response['command']);
            }
            if (isset($response['message']) === true) {
                $message .= $response['message'];
            }

            throw new BackendAudioDataCalculatorException($message ?: $curl->errorMessage, $curl->errorCode ?: 500);
        }

        $curl->close();

        unset($curl);

        return $response;
    }

    /**
     * Określa, czy dane zawarte w metadanych pliku są takie same jak te obliczone przez backend
     *
     * @param array $metadata
     * @param array $audioData
     * @return bool
     */
    private function isTrackHasSameData(array $metadata, array $audioData)
    {
        if (isset($metadata['bpm']) === false || isset($metadata['initial_key']) === false) {
            return false;
        }

        return (string) $metadata['bpm'] === (string) $audioData['bpm'] && $metadata['initial_key'] === $audioData['initial_key'];
    }
}