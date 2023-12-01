<?php

namespace FacebookFeed\Command;

use FacebookFeed\FacebookFeed;
use FacebookFeed\Service\FacebookFeedService;
use GoogleShoppingXml\Service\GenerateXmlService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Serializer\SerializerManager;
use Thelia\Handler\ExportHandler;
use Thelia\Model\ExportQuery;

class FacebookFeedCommand extends ContainerAwareCommand
{
    public function __construct(
        protected  FacebookFeedService $facebookFeedService,
        protected SerializerManager $serializerManager,
    )
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->setName("facebook:feed:generate")
            ->setDescription("Export de flux pour facebook")
            ->addArgument(
                'limit',
                InputArgument::OPTIONAL,
                '[TESTING] SQL Limit'
            )
            ->addArgument(
                'offset',
                InputArgument::OPTIONAL,
                '[TESTING] SQL Offset'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();
        $limit = (int)$input->getArgument('limit');
        $offset = (int)$input->getArgument('offset');

        $this->facebookFeedService->exportFacebookFeed($limit,$offset,$output);
        return 1;
    }
}
