<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Dao;

use App\Model\MobileSegment;

class MobileSegmentDao extends AbstractDao
{
    protected string $model = MobileSegment::class;

    public function findBySegment(string $segment): ?MobileSegment
    {
        return $this->newQuery()->where('segment', $segment)->first();
    }
}
