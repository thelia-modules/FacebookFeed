<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacebookFeed\Command;

use FacebookFeed\Exception\FeedGenerationException;
use FacebookFeed\Service\FacebookFeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;
use Thelia\Model\LangQuery;

#[AsCommand(name: 'facebook:feed:generate', description: 'Generates the Facebook feed of every active language')]
class FacebookFeedCommand extends ContainerAwareCommand
{
    public function __construct(private readonly FacebookFeedService $facebookFeedService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, '[TESTING] Number of lines written for each language')
            ->addArgument('offset', InputArgument::OPTIONAL, '[TESTING] Number of lines skipped for each language');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->positiveInteger($input->getArgument('limit'));
        $offset = $this->positiveInteger($input->getArgument('offset'));
        $failed = false;

        $lock = $this->facebookFeedService->tryLock();
        if (null === $lock) {
            $output->writeln('<comment>Another generation is running: nothing done.</comment>');

            return self::SUCCESS;
        }

        foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
            $locale = (string) $lang->getLocale();
            $this->initRequest($lang);

            $output->writeln(\sprintf('Feed of %s', $locale));
            $progressBar = new ProgressBar($output);
            $progressBar->start();

            try {
                $path = $this->facebookFeedService->generate($locale, null, $limit, $offset, static function (int $lines) use ($progressBar): void {
                    $progressBar->advance($lines);
                });
            } catch (FeedGenerationException $exception) {
                $progressBar->clear();
                $output->writeln(\sprintf('<error>%s</error>', $exception->getMessage()));
                $failed = true;

                continue;
            }

            $progressBar->finish();
            $output->writeln(\sprintf(' %s', $path));
        }

        fclose($lock);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var($value, \FILTER_VALIDATE_INT);

        return false !== $integer && $integer > 0 ? $integer : null;
    }
}
