<?php

namespace DeliciousBrains\WPMDB\Common\MigrationState\Migrations;

use DeliciousBrains\WPMDB\Common\MigrationState\ApplicationStateAbstract;

class RemoteSiteState extends ApplicationStateAbstract
{
    protected $state_identifier = "remote_site";

    public function set($property, $value, $safe = true)
    {
        parent::set($property, $value, false);
    }
}
