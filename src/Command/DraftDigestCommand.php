<?php

namespace Base\Newsletter\Command;

use Base\Newsletter\Service\DigestDrafter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The monthly digest, drafted for the coming month (a cron on the 20th, say):
 * a DRAFT campaign in the back office, to read and send. Nothing is sent.
 *
 *     bin/console newsletter:draft-digest
 *     bin/console newsletter:draft-digest --from=2026-11-01 --to=2026-12-01 --locale=de
 */
#[AsCommand(name: 'newsletter:draft-digest', description: 'Drafts the digest of the coming month from the digest sources (a DRAFT campaign; nothing is sent).')]
final class DraftDigestCommand extends Command
{
    public function __construct(
        private readonly DigestDrafter $drafter,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%newsletter.digest.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale = 'en',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day (Y-m-d); default: the first of next month.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Day after the last (Y-m-d); default: a month after --from.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The subscribers of one language; default: everyone, in the site\'s language.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->enabled) {
            $io->note('The digest is off (newsletter.digest.enabled).');

            return Command::SUCCESS;
        }

        try {
            [$from, $to] = DigestDrafter::nextMonth();
            if ($input->getOption('from')) {
                $from = new \DateTimeImmutable($input->getOption('from'));
                $to = $from->modify('+1 month');
            }
            if ($input->getOption('to')) {
                $to = new \DateTimeImmutable($input->getOption('to'));
            }
        } catch (\Exception $e) {
            $io->error('A date it cannot read: '.$e->getMessage());

            return Command::INVALID;
        }
        if ($to <= $from) {
            $io->error('--to must come after --from.');

            return Command::INVALID;
        }

        $locale = $input->getOption('locale') ?: null;
        $campaign = $this->drafter->draft($from, $to, $locale, $this->defaultLocale);
        if (!$campaign) {
            $io->note(sprintf('Nothing to announce between %s and %s: no draft.', $from->format('Y-m-d'), $to->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $this->entityManager->persist($campaign);
        $this->entityManager->flush();
        $io->success(sprintf('Drafted "%s" (#%d): read and send it from the back office.', $campaign->getSubject(), $campaign->getId()));

        return Command::SUCCESS;
    }
}
