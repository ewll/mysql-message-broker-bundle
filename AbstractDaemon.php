<?php namespace Ewll\MysqlMessageBrokerBundle;

use Ewll\DBBundle\Repository\RepositoryProvider;
use Ewll\LogExtraDataBundle\LogExtraDataKeeper;
use Exception;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Wrep\Daemonizable\Command\EndlessCommand;

abstract class AbstractDaemon extends EndlessCommand
{
    /** @var Logger */
    protected $logger;
    /** @var RepositoryProvider */
    protected $repositoryProvider;
    /** @var LogExtraDataKeeper */
    protected $logExtraDataKeeper;

    public function setLogExtraDataKeeper(LogExtraDataKeeper $logExtraDataKeeper): void
    {
        $this->logExtraDataKeeper = $logExtraDataKeeper;
    }

    public function setRepositoryProvider(RepositoryProvider $repositoryProvider)
    {
        $this->repositoryProvider = $repositoryProvider;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logExtraDataKeeper->setData([
            'daemonKey' => uniqid(),
            'name' => $this->getName(),
        ]);
        $this->logger->info("Start {$this->getName()}");

        if (!$this->isMultithread()) {
            $store = new FlockStore();
            $factory = new LockFactory($store);
            $lock = $factory->createLock(md5(serialize($input->getArguments())));

            if (!$lock->acquire()) {
                $this->logger->critical('Sorry, cannot lock file');

                return;
            }
        }

        try {
            while (1) {
                $this->logExtraDataKeeper->setParam('iterationKey', uniqid());
                $this->do($input, $output);
                $this->repositoryProvider->clear();
            }
        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
            /*foreach ($this->dbClientProvider->getClients() as $dbClient) {
                $dbClient->close();
            }*/
            // @TODO CHOICE: CLOSE CONNECTIONS OR SHUTDOWN
            $this->logger->critical('Shutdown daemon');
            $this->shutdown();
        }

        $this->logger->debug("Done {$this->getName()}");
    }

    protected function isMultithread(): bool
    {
        return false;
    }

    /** @throws Exception */
    abstract protected function do(InputInterface $input, OutputInterface $output);
}
