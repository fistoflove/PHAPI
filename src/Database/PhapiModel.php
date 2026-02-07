<?php

declare(strict_types=1);

namespace PHAPI\Database;

use Hyperf\DbConnection\Model\Model;

abstract class PhapiModel extends Model
{
    /**
     * @var array<int, string>
     */
    protected array $guarded = [];

    public bool $timestamps = true;
}
