<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Cache\CacheItemPoolInterface;

#[AsCommand(
    name: 'app:cache-test',
    description: 'Test PSR-6 cache (FilesystemAdapter)'
)]
class CacheTestCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = 'telegram_product_state_test';

        $io->title('Cache test (PSR-6)');

        // 1. save
        $item = $this->cache->getItem($key);
        $item->set([
            'step' => 1,
            'name' => 'Test product',
        ]);
        $item->expiresAfter(600);
        $this->cache->save($item);

        $io->success('State saved');

        // 2. read
        $readItem = $this->cache->getItem($key);

        $io->section('Value from cache');
        if ($readItem->isHit()) {
            $io->writeln(var_export($readItem->get(), true));
        } else {
            $io->error('Cache MISS');
        }

        // 3. delete
        $this->cache->deleteItem($key);
        $io->success('Cache key deleted');

        return Command::SUCCESS;
    }
}

