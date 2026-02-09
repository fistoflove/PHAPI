<?php

declare(strict_types=1);

namespace PHAPI\Database;

use Hyperf\DbConnection\Model\Model;

abstract class PhapiModel extends Model
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->guard([]);
    }

    public bool $timestamps = true;
}
