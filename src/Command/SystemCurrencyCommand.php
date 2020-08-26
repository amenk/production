<?php declare(strict_types=1);

namespace Shopware\Production\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemCurrencyCommand extends Command
{
    public static $defaultName = 'system:currency-destructive';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var Connection
     */
    private $connection;

    private $activated = false;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    public function activateCommand(): void
    {
        $this->activated = true;
    }

    protected function configure(): void
    {
        $this->addArgument('currency', InputArgument::REQUIRED, 'Defauly currency');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);
        $output->section('Default currency');

        if (!$this->activated) {
            $output->error('The command has not been activated by the runtime and therefore cannot be executed. It is intended to be used with system:install --locale');

            return 1;
        }

        $locale = strtoupper($input->getArgument('currency'));
        $this->setDefaultCurrency($locale);

        $output->success(sprintf('Successfully changed shop default locale to %s', $locale));

        return 0;
    }

    public function setDefaultCurrency(string $currency): void
    {
        $stmt = $this->connection->prepare('SELECT iso_code FROM currency WHERE id = ?');
        $stmt->execute([Uuid::fromHexToBytes(Defaults::CURRENCY)]);
        $currentCurrencyIso = $stmt->fetchColumn();

        if (!$currentCurrencyIso) {
            throw new \RuntimeException('Default currency not found');
        }

        if (mb_strtoupper($currentCurrencyIso) === mb_strtoupper($currency)) {
            return;
        }

        $newDefaultCurrencyId = $this->getCurrencyId($currency);
        if (!$newDefaultCurrencyId) {
            $newDefaultCurrencyId = $this->createCurrency($currency);
        }

        $stmt = $this->connection->prepare('UPDATE currency SET id = :newId WHERE id = :oldId');

        // assign new uuid to old DEFAULT
        $stmt->execute([
            'newId' => Uuid::randomBytes(),
            'oldId' => Uuid::fromHexToBytes(Defaults::CURRENCY),
        ]);

        // change id to DEFAULT
        $stmt->execute([
            'newId' => Uuid::fromHexToBytes(Defaults::CURRENCY),
            'oldId' => $newDefaultCurrencyId,
        ]);

        $stmt = $this->connection->prepare(
            'SET @fixFactor = (SELECT 1/factor FROM currency WHERE iso_code = :newDefault);
             UPDATE currency
             SET factor = IF(iso_code = :newDefault, 1, factor * @fixFactor);'
        );
        $stmt->execute(['newDefault' => $currency]);
    }

    private function getCurrencyId(string $currencyName): ?string
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM currency WHERE LOWER(iso_code) = LOWER(?)'
        );
        $stmt->execute([$currencyName]);
        $currencyId = $stmt->fetchColumn();

        return $currencyId === false ? null : $currencyId;
    }

    private function createCurrency(string $iso): string
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config/currencies.json'), true);

        if (!isset($data[$iso])) {
            throw new \RuntimeException(sprintf('Cannot find currency %s in data set', $iso));
        }

        $currency = $data[$iso];
        $currencyId = Uuid::randomBytes();

        $this->connection->insert('currency', [
            'id' => $currencyId,
            'iso_code' => $iso,
            'factor' => 1,
            'symbol' => $currency['symbol'],
            'position' => 1,
            'decimal_precision' => $currency['decimal_digits'],
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
        ]);

        $this->connection->insert('currency_translation', [
            'currency_id' => $currencyId,
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'short_name' => $currency['code'],
            'name' => $currency['name'],
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
        ]);

        return $currencyId;
    }
}
