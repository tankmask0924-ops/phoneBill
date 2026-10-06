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

namespace HyperfTest\Cases\Controller;

use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class IndexControllerTest extends HttpTestCase
{
    public function testHealthCheck()
    {
        $response = $this->client->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['app' => 'phoneBill', 'status' => 'ok'], json_decode((string) $response->getBody(), true));
    }
}
