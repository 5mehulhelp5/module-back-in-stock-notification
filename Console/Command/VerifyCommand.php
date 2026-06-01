<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Console\Command;

use ETechFlow\BackInStockNotification\Api\Data\SubscriptionInterface;
use ETechFlow\BackInStockNotification\Api\SubscriptionRepositoryInterface;
use ETechFlow\BackInStockNotification\Model\Adapter\BedEtaAdapter;
use ETechFlow\BackInStockNotification\Model\Adapter\IspStoreAdapter;
use ETechFlow\BackInStockNotification\Model\Adapter\NdeEligibilityAdapter;
use ETechFlow\BackInStockNotification\Model\Config;
use ETechFlow\BackInStockNotification\Model\LicenseValidator;
use ETechFlow\BackInStockNotification\Cron\LifetimeExpiryCron;
use ETechFlow\BackInStockNotification\Cron\QueueConsumer;
use ETechFlow\BackInStockNotification\Model\NotificationQueueRepository;
use ETechFlow\BackInStockNotification\Model\Notification\BackInStockSender;
use ETechFlow\BackInStockNotification\Model\Notification\ConfirmSender;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:bisn:verify` — module smoke test.
 *
 * 12 PASS lines = green-light go-live. Exit 0 on full pass, 1 on any
 * failure. Use in deploy pipelines as a post-install smoke check.
 *
 * Same shape as etechflow:isp:verify, etechflow:ddp:verify.
 */
class VerifyCommand extends Command
{
    private int $checksRun = 0;
    private int $checksFailed = 0;

    public function __construct(
        private readonly AppState $appState,
        private readonly LicenseValidator $licenseValidator,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly NotificationQueueRepository $queueRepository,
        private readonly BackInStockSender $sender,
        private readonly ConfirmSender $confirmSender,
        private readonly QueueConsumer $queueConsumer,
        private readonly LifetimeExpiryCron $lifetimeExpiry,
        private readonly NdeEligibilityAdapter $ndeAdapter,
        private readonly BedEtaAdapter $bedAdapter,
        private readonly IspStoreAdapter $ispAdapter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:bisn:verify')
            ->setDescription('Smoke-test ETechFlow Back-in-Stock Notification (license, DB, DI wiring).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // already set — ignore
        }

        $output->writeln('=== ETechFlow Back-in-Stock Notification verify ===');
        $output->writeln('');

        $this->check($output, 'LicenseValidator evaluates without throwing', function () {
            $host = $this->licenseValidator->getCurrentHost();
            $isDev = $this->licenseValidator->isDevHost($host);
            $isValid = $this->licenseValidator->isValid();
            return sprintf('host=%s; dev_host=%s; valid=%s',
                $host ?: '(none)',
                $isDev ? 'yes' : 'no',
                $isValid ? 'yes' : 'no');
        });

        $this->check($output, 'Config.isEnabled() returns a boolean', function () {
            $enabled = $this->config->isEnabled();
            return 'enabled=' . ($enabled ? 'yes' : 'no');
        });

        $this->check($output, 'Email settings reachable', function () {
            $rate = $this->config->getEmailRateLimit();
            $lifetime = $this->config->getSubscriptionLifetimeDays();
            $tpl = $this->config->getEmailTemplate();
            $sender = $this->config->getEmailSender();
            return sprintf('rate=%d/min; lifetime=%dd; template=%s; sender=%s', $rate, $lifetime, $tpl, $sender);
        });

        $this->check($output, 'Integration detection (NDE / BED / ISP) works without crashing', function () {
            $nde = $this->config->isNdeEligibilityEnabled();
            $bed = $this->config->isBedEtaEnabled();
            $isp = $this->config->isIspPerStoreEnabled();
            return sprintf(
                'NDE=%s; BED=%s; ISP=%s',
                $nde ? 'on' : 'off (or not installed)',
                $bed ? 'on' : 'off (or not installed)',
                $isp ? 'on' : 'off (or not installed)'
            );
        });

        $this->check($output, 'etechflow_bisn_subscription table exists', function () {
            $conn = $this->resourceConnection->getConnection();
            $name = $this->resourceConnection->getTableName('etechflow_bisn_subscription');
            if (!$conn->isTableExists($name)) {
                throw new \RuntimeException("Missing table '$name' — run bin/magento setup:upgrade");
            }
            return 'OK';
        });

        $this->check($output, 'etechflow_bisn_notification_queue table exists', function () {
            $conn = $this->resourceConnection->getConnection();
            $name = $this->resourceConnection->getTableName('etechflow_bisn_notification_queue');
            if (!$conn->isTableExists($name)) {
                throw new \RuntimeException("Missing table '$name' — run bin/magento setup:upgrade");
            }
            return 'OK';
        });

        $this->check($output, 'SubscriptionRepository resolves via DI', function () {
            return get_class($this->subscriptionRepository);
        });

        $this->check($output, 'NotificationQueueRepository resolves via DI', function () {
            return get_class($this->queueRepository);
        });

        $this->check($output, 'BackInStockSender resolves via DI', function () {
            return get_class($this->sender);
        });

        $this->check($output, 'ConfirmSender resolves via DI', function () {
            return get_class($this->confirmSender);
        });

        $this->check($output, 'QueueConsumer cron resolves via DI', function () {
            return get_class($this->queueConsumer);
        });

        $this->check($output, 'LifetimeExpiryCron resolves via DI', function () {
            return get_class($this->lifetimeExpiry);
        });

        $this->check($output, 'NdeEligibilityAdapter resolves via DI', function () {
            return get_class($this->ndeAdapter);
        });

        $this->check($output, 'BedEtaAdapter resolves via DI', function () {
            return get_class($this->bedAdapter);
        });

        $this->check($output, 'IspStoreAdapter resolves via DI', function () {
            return get_class($this->ispAdapter);
        });

        $output->writeln('');
        if ($this->checksFailed === 0) {
            $output->writeln(sprintf('<info>All %d checks passed.</info>', $this->checksRun));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>%d of %d checks FAILED.</error>', $this->checksFailed, $this->checksRun));
        return Command::FAILURE;
    }

    private function check(OutputInterface $output, string $name, callable $fn): void
    {
        $this->checksRun++;
        $idx = $this->checksRun;
        $output->write(sprintf('%2d. %s ... ', $idx, $name));
        try {
            $detail = $fn();
            $output->writeln(sprintf('<info>OK</info> (%s)', $detail));
        } catch (\Throwable $e) {
            $this->checksFailed++;
            $output->writeln(sprintf('<error>FAIL: %s</error>', $e->getMessage()));
        }
    }
}