<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout;

use Erikwang2013\WebmanScout\Exceptions\ScoutException;

class XunSearchClient
{
    protected string $configPath;

    protected array $projects = [];

    protected ?\XS $current = null;

    public function __construct()
    {
        $config = scout_config('xunsearch', []);
        $this->configPath = rtrim((string) ($config['config_path'] ?? ''), '/');
    }

    public function task(string $name): \XS
    {
        if (! isset($this->projects[$name])) {
            $this->projects[$name] = new \XS($this->resolveIni($name));
        }

        return $this->current = $this->projects[$name];
    }

    public function refresh(string $name): \XS
    {
        return $this->task($name);
    }

    public function getSearch(): \XSSearch
    {
        if ($this->current === null) {
            $this->task(scout_config('xunsearch.default_index', 'default'));
        }

        return $this->current->getSearch();
    }

    public function createIndex(string $name, array $options = [])
    {
        return $this->task($name)->getIndex();
    }

    protected function resolveIni(string $name): string
    {
        if ($this->configPath !== '' && is_file($ini = $this->configPath . '/' . $name . '.ini')) {
            return $ini;
        }

        $shipped = __DIR__ . '/config/plugin/erikwang2013/webman-scout/ini/' . $name . '.ini';
        if (is_file($shipped)) {
            return $shipped;
        }

        throw new ScoutException("XunSearch project config not found: {$name}.ini");
    }
}
