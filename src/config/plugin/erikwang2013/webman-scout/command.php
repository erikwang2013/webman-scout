<?php
/**
 *-------------------------------------------------------------------------p*
 *
 *-------------------------------------------------------------------------h*
 * @copyright  Copyright (c) 2015-2022 Shopwwi Inc. (http://www.shopwwi.com)
 *-------------------------------------------------------------------------c*
 * @license    http://www.shopwwi.com        s h o p w w i . c o m
 *-------------------------------------------------------------------------e*
 * @link       http://www.shopwwi.com by 无锡豚豹科技
 *-------------------------------------------------------------------------n*
 * @since      shopwwi豚豹·PHP商城系统
 *-------------------------------------------------------------------------t*
 */

use Erikwang2013\WebmanScout\Command\DeleteIndexCommand;
use Erikwang2013\WebmanScout\Command\FlushCommand;
use Erikwang2013\WebmanScout\Command\ImportCommand;
use Erikwang2013\WebmanScout\Command\IndexCommand;
use Erikwang2013\WebmanScout\Command\DeleteAllIndexesCommand;
use Erikwang2013\WebmanScout\Command\QueueImportCommand;
use Erikwang2013\WebmanScout\Command\SyncIndexSettingsCommand;

return [
    IndexCommand::class,
    ImportCommand::class,
    FlushCommand::class,
    DeleteIndexCommand::class,
    DeleteAllIndexesCommand::class,
    QueueImportCommand::class,
    SyncIndexSettingsCommand::class
];
