<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;

#[AsCommand(name: 'scout:about', description: 'Show the Scout mascot, the resolved configuration and the available engines')]
class AboutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected static $defaultName = 'scout:about';

    /**
     * The console command description.
     *
     * @var string
     */
    protected static $defaultDescription = 'Show the Scout mascot, the resolved configuration and the available engines';

    /**
     * 吉祥物「探探」：一只拿放大镜的侦察犬 —— 负责嗅出你要的数据。
     * docs/images/pet.svg 是同一形象的主视觉版本。
     */
    public const MASCOT = <<<'ASCII'
   __        __      ___
  /  \______/  \    /   \
  |            |   | --  |
  |   o    o   |    \   /
  |     __     |       |
  \    \__/    /       |
   \__________/
ASCII;

    /**
     * 外部引擎 => 运行时需要的客户端类，全部存在才算可用。
     */
    private const CLIENTS = [
        'opensearch' => ['OpenSearch\\Client', 'OpenSearch\\GuzzleClientFactory'],
        'elasticsearch' => ['Elastic\\Elasticsearch\\ClientBuilder'],
        'meilisearch' => ['Meilisearch\\Meilisearch'],
        'typesense' => ['Typesense\\Client'],
        'algolia' => ['Algolia\\AlgoliaSearch\\Algolia'],
        'xunsearch' => ['XS'],
    ];

    /**
     * 无需外部客户端的引擎，始终可用。
     */
    private const BUILTIN = ['database', 'collection', 'null'];

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>' . self::MASCOT . '</>');
        $output->writeln('');
        $output->writeln('  <options=bold>Scout</> · webman-scout <comment>v' . Scout::VERSION . '</comment>  '
            . '<fg=gray>sniff out your data · 嗅出你的数据</>');
        $output->writeln('');

        $driver = scout_config('driver');
        $queue = (bool) scout_config('queue');
        $rows = [
            'config root 配置根' => ScoutConfig::baseKey(),
            'driver 引擎' => $driver ?: '<fg=yellow>未配置，回退到 null 引擎</>',
            'prefix 索引前缀' => (string) scout_config('prefix', ''),
            'queue 队列' => $queue
                ? 'on（Webman Redis Queue ' . (class_exists('Webman\\RedisQueue\\Redis') ? '已安装' : '未安装，自动降级为同步') . '）'
                : 'off（请求内同步索引）',
            'soft delete 软删除' => scout_config('soft_delete', false) ? 'on（保留 __soft_deleted 文档）' : 'off',
            'after commit 事务后提交' => scout_config('after_commit', false) ? 'on' : 'off',
            'chunk 分块' => 'searchable=' . scout_config('chunk.searchable', 500)
                . ' / unsearchable=' . scout_config('chunk.unsearchable', 500),
        ];
        foreach ($rows as $label => $value) {
            $output->writeln('  <comment>' . $this->pad($label, 26) . '</comment>' . $value);
        }

        $output->writeln('');
        $output->writeln('  <options=bold>engines 引擎可用性</> <fg=gray>（composer require 对应客户端后即可用）</>');
        $engines = [];
        foreach (self::CLIENTS as $name => $classes) {
            $engines[$name] = $this->hasClasses($classes);
        }
        foreach (self::BUILTIN as $name) {
            $engines[$name] = true;
        }
        ksort($engines);

        $line = [];
        foreach ($engines as $name => $available) {
            $line[] = str_pad($name, 15) . ($available ? '<info>✔</info>' : '<fg=gray>✘</>');
            if (count($line) === 3) {
                $output->writeln('  ' . implode('  ', $line));
                $line = [];
            }
        }
        if ($line) {
            $output->writeln('  ' . implode('  ', $line));
        }

        $output->writeln('');
        return Command::SUCCESS;
    }

    /**
     * 按终端显示宽度补齐（中文占两列），保证多语言的标签左对齐。
     */
    private function pad(string $label, int $width): string
    {
        $current = function_exists('mb_strwidth') ? mb_strwidth($label) : strlen($label);

        return $label . str_repeat(' ', max(1, $width - $current));
    }

    /**
     * @param  string[]  $classes
     */
    private function hasClasses(array $classes): bool
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                return false;
            }
        }

        return true;
    }
}
