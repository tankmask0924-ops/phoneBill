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

namespace HyperfTest\Cases\Storage;

use App\Storage\CosClient;
use HyperfTest\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 腾讯云 COS 签名：用官方「请求签名」文档里的示例数据核对。
 *
 * @internal
 * @coversNothing
 */
class CosClientTest extends TestCase
{
    public function testSignatureMatchesDocumentExample()
    {
        $client = new CosClient(new FakeHttpClient(), 'DOC_EXAMPLE_SECRET_ID', 'BQYIM75p8x0iWVFSIgqEKwFprpRSVHlz', 'examplebucket-1250000000', 'ap-beijing');
        $auth = $client->authorization('PUT', '/exampleobject(腾讯云)', [
            'Date' => 'Thu, 16 May 2019 06:45:51 GMT',
            'Host' => 'examplebucket-1250000000.cos.ap-beijing.myqcloud.com',
            'Content-Type' => 'text/plain',
            'Content-Length' => '13',
            'Content-MD5' => 'mQ/fVh815F3k6TAUm8m0eg==',
            'x-cos-acl' => 'private',
            'x-cos-grant-read' => 'uin="100000000011"',
        ], [], 1557989151, 7200);

        // SecretKey、请求、时间取自文档示例；SecretId 不参与签名，换成占位值（文档里的示例 ID 会被 GitHub 当成密钥拦下推送）。
        // 文档的中间结果：SignKey = eb2519b4...，sha1(HttpString) = 8b2751e7...，据此算出的签名
        $this->assertSame(
            'q-sign-algorithm=sha1&q-ak=DOC_EXAMPLE_SECRET_ID&q-sign-time=1557989151;1557996351&q-key-time=1557989151;1557996351'
            . '&q-header-list=content-length;content-md5;content-type;date;host;x-cos-acl;x-cos-grant-read&q-url-param-list='
            . '&q-signature=3b8851a11a569213c17ba8fa7dcf2abec6935172',
            $auth
        );
    }

    public function testPutDeleteAndPresignedUrl()
    {
        $http = new FakeHttpClient();
        $client = new CosClient($http, 'AKID', 'SK', 'bucket-1250000000', 'ap-guangzhou');

        $http->responses = [['status' => 200, 'body' => '']];
        $client->put('pb/vouchers/1/a b.png', 'PNG', 'image/png');
        $sent = $http->requests[0];
        $this->assertSame('PUT', $sent['method']);
        $this->assertSame('https://bucket-1250000000.cos.ap-guangzhou.myqcloud.com/pb/vouchers/1/a%20b.png', $sent['url']);
        $this->assertSame('PNG', $sent['payload']['body']);
        $this->assertSame('image/png', $sent['payload']['headers']['Content-Type']);
        $this->assertStringStartsWith('q-sign-algorithm=sha1&q-ak=AKID&', $sent['payload']['headers']['Authorization']);
        $this->assertStringContainsString('q-header-list=host&', $sent['payload']['headers']['Authorization']);

        $http->responses = [['status' => 204, 'body' => '']];
        $client->delete('pb/x.png');
        $this->assertSame('DELETE', $http->requests[1]['method']);

        $url = $client->presignedUrl('pb/x.png', 600, 1000);
        $this->assertStringStartsWith('https://bucket-1250000000.cos.ap-guangzhou.myqcloud.com/pb/x.png?q-sign-algorithm=sha1&q-ak=AKID&q-sign-time=1000%3B1600&q-key-time=1000%3B1600&q-header-list=host&q-url-param-list=&q-signature=', $url);

        // 出错时带上 COS 的错误码
        $http->responses = [['status' => 403, 'body' => '<Error><Code>AccessDenied</Code><Message>Access Denied.</Message></Error>']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AccessDenied Access Denied.');
        $client->put('pb/y.png', 'PNG', 'image/png');
    }
}
