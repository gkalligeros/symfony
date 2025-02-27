<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Command;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionInterface;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Output\BashCompletionOutput;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Responsible for providing the values to the shell completion.
 *
 * @author Wouter de Jong <wouter@wouterj.nl>
 */
final class CompleteCommand extends Command
{
    protected static $defaultName = '|_complete';
    protected static $defaultDescription = 'Internal command to provide shell completion suggestions';

    private static $completionOutputs = [
        'bash' => BashCompletionOutput::class,
    ];

    private $isDebug = false;

    protected function configure(): void
    {
        $this
            ->addOption('shell', 's', InputOption::VALUE_REQUIRED, 'The shell type (e.g. "bash")')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An array of input tokens (e.g. COMP_WORDS or argv)')
            ->addOption('current', 'c', InputOption::VALUE_REQUIRED, 'The index of the "input" array that the cursor is in (e.g. COMP_CWORD)')
            ->addOption('symfony', 'S', InputOption::VALUE_REQUIRED, 'The version of the completion script')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->isDebug = filter_var(getenv('SYMFONY_COMPLETION_DEBUG'), \FILTER_VALIDATE_BOOLEAN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // uncomment when a bugfix or BC break has been introduced in the shell completion scripts
            //$version = $input->getOption('symfony');
            //if ($version && version_compare($version, 'x.y', '>=')) {
            //    $message = sprintf('Completion script version is not supported ("%s" given, ">=x.y" required).', $version);
            //    $this->log($message);

            //    $output->writeln($message.' Install the Symfony completion script again by using the "completion" command.');

            //    return 126;
            //}

            $shell = $input->getOption('shell');
            if (!$shell) {
                throw new \RuntimeException('The "--shell" option must be set.');
            }

            if (!$completionOutput = self::$completionOutputs[$shell] ?? false) {
                throw new \RuntimeException(sprintf('Shell completion is not supported for your shell: "%s" (supported: "%s").', $shell, implode('", "', array_keys(self::$completionOutputs))));
            }

            $completionInput = $this->createCompletionInput($input);
            $suggestions = new CompletionSuggestions();

            $this->log([
                '',
                '<comment>'.date('Y-m-d H:i:s').'</>',
                '<info>Input:</> <comment>("|" indicates the cursor position)</>',
                '  '.(string) $completionInput,
                '<info>Messages:</>',
            ]);

            $command = $this->findCommand($completionInput, $output);
            if (null === $command) {
                $this->log('  No command found, completing using the Application class.');

                $this->getApplication()->complete($completionInput, $suggestions);
            } elseif (
                $completionInput->mustSuggestArgumentValuesFor('command')
                && $command->getName() !== $completionInput->getCompletionValue()
            ) {
                $this->log('  No command found, completing using the Application class.');

                // expand shortcut names ("cache:cl<TAB>") into their full name ("cache:clear")
                $suggestions->suggestValue($command->getName());
            } else {
                $command->mergeApplicationDefinition();
                $completionInput->bind($command->getDefinition());

                if (CompletionInput::TYPE_OPTION_NAME === $completionInput->getCompletionType()) {
                    $this->log('  Completing option names for the <comment>'.\get_class($command instanceof LazyCommand ? $command->getCommand() : $command).'</> command.');

                    $suggestions->suggestOptions($command->getDefinition()->getOptions());
                } elseif ($command instanceof CompletionInterface) {
                    $this->log([
                        '  Completing using the <comment>'.\get_class($command).'</> class.',
                        '  Completing <comment>'.$completionInput->getCompletionType().'</> for <comment>'.$completionInput->getCompletionName().'</>',
                    ]);
                    if (null !== $compval = $completionInput->getCompletionValue()) {
                        $this->log('  Current value: <comment>'.$compval.'</>');
                    }

                    $command->complete($completionInput, $suggestions);
                }
            }

            $completionOutput = new $completionOutput();

            $this->log('<info>Suggestions:</>');
            if ($options = $suggestions->getOptionSuggestions()) {
                $this->log('  --'.implode(' --', array_map(function ($o) { return $o->getName(); }, $options)));
            } elseif ($values = $suggestions->getValueSuggestions()) {
                $this->log('  '.implode(' ', $values));
            } else {
                $this->log('  <comment>No suggestions were provided</>');
            }

            $completionOutput->write($suggestions, $output);
        } catch (\Throwable $e) {
            $this->log([
                '<error>Error!</error>',
                (string) $e,
            ]);

            if ($output->isDebug()) {
                throw $e;
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createCompletionInput(InputInterface $input): CompletionInput
    {
        $currentIndex = $input->getOption('current');
        if (!$currentIndex || !ctype_digit($currentIndex)) {
            throw new \RuntimeException('The "--current" option must be set and it must be an integer.');
        }

        $completionInput = CompletionInput::fromTokens(array_map(
            function (string $i): string { return trim($i, "'"); },
            $input->getOption('input')
        ), (int) $currentIndex);

        try {
            $completionInput->bind($this->getApplication()->getDefinition());
        } catch (ExceptionInterface $e) {
        }

        return $completionInput;
    }

    private function findCommand(CompletionInput $completionInput, OutputInterface $output): ?Command
    {
        try {
            $inputName = $completionInput->getFirstArgument();
            if (null === $inputName) {
                return null;
            }

            return $this->getApplication()->find($inputName);
        } catch (CommandNotFoundException $e) {
        }

        return null;
    }

    private function log($messages): void
    {
        if (!$this->isDebug) {
            return;
        }

        $commandName = basename($_SERVER['argv'][0]);
        file_put_contents(sys_get_temp_dir().'/sf_'.$commandName.'.log', implode(\PHP_EOL, (array) $messages).\PHP_EOL, \FILE_APPEND);
    }
}
