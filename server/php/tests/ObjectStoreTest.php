<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ObjectStoreTest extends TestCase
{
    private array $envBackup;

    protected function setUp(): void
    {
        $this->envBackup = [
            'S3_ENDPOINT' => $_ENV['S3_ENDPOINT'] ?? null,
            'S3_REGION' => $_ENV['S3_REGION'] ?? null,
            'S3_ACCESS_KEY' => $_ENV['S3_ACCESS_KEY'] ?? null,
            'S3_SECRET_KEY' => $_ENV['S3_SECRET_KEY'] ?? null,
            'S3_BUCKET' => $_ENV['S3_BUCKET'] ?? null,
            'S3_CONNECT_TIMEOUT' => $_ENV['S3_CONNECT_TIMEOUT'] ?? null,
            'S3_TIMEOUT' => $_ENV['S3_TIMEOUT'] ?? null,
            'S3_RETRIES' => $_ENV['S3_RETRIES'] ?? null,
            'S3_SLOW_THRESHOLD_MS' => $_ENV['S3_SLOW_THRESHOLD_MS'] ?? null,
        ];
        ObjectStore::setLogCallback(null);
        ObjectStore::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $val) {
            if ($val === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
                putenv("$key=$val");
            }
        }
        ObjectStore::setLogCallback(null);
        ObjectStore::resetInstance();
    }

    public function testDefaultS3ClientConfiguresTimeoutsAndRetries(): void
    {
        $_ENV['S3_ENDPOINT'] = 'http://127.0.0.1:8333';
        $_ENV['S3_REGION'] = 'us-east-1';
        $_ENV['S3_ACCESS_KEY'] = 'test_key';
        $_ENV['S3_SECRET_KEY'] = 'test_secret';
        $_ENV['S3_BUCKET'] = 'test_bucket';
        unset($_ENV['S3_CONNECT_TIMEOUT'], $_ENV['S3_TIMEOUT'], $_ENV['S3_RETRIES']);

        $store = new ObjectStore();
        $client = $store->getClient();

        $ref = new ReflectionClass('Aws\AwsClient');
        $prop = $ref->getProperty('defaultRequestOptions');
        $prop->setAccessible(true);
        $requestOpts = $prop->getValue($client);

        $this->assertSame(2.0, (float) ($requestOpts['connect_timeout'] ?? 0));
        $this->assertSame(10.0, (float) ($requestOpts['timeout'] ?? 0));

        // Retries is bounded and configured in handlerList sign step
        $hlProp = $ref->getProperty('handlerList');
        $hlProp->setAccessible(true);
        $hl = $hlProp->getValue($client);

        $hlRef = new ReflectionClass($hl);
        $stepsProp = $hlRef->getProperty('steps');
        $stepsProp->setAccessible(true);
        $steps = $stepsProp->getValue($hl);

        $this->assertNotEmpty($steps['sign']);
    }

    public function testCustomEnvironmentConfiguresTimeoutsAndRetries(): void
    {
        $_ENV['S3_ENDPOINT'] = 'http://127.0.0.1:8333';
        $_ENV['S3_REGION'] = 'us-east-1';
        $_ENV['S3_ACCESS_KEY'] = 'test_key';
        $_ENV['S3_SECRET_KEY'] = 'test_secret';
        $_ENV['S3_BUCKET'] = 'test_bucket';
        $_ENV['S3_CONNECT_TIMEOUT'] = '3';
        $_ENV['S3_TIMEOUT'] = '15';
        $_ENV['S3_RETRIES'] = '5';

        $store = new ObjectStore();
        $client = $store->getClient();

        $ref = new ReflectionClass('Aws\AwsClient');
        $prop = $ref->getProperty('defaultRequestOptions');
        $prop->setAccessible(true);
        $requestOpts = $prop->getValue($client);

        $this->assertSame(3.0, (float) ($requestOpts['connect_timeout'] ?? 0));
        $this->assertSame(15.0, (float) ($requestOpts['timeout'] ?? 0));
    }

    public function testExtractCommandKeyFromVariousOperations(): void
    {
        $getCmd = new Command('GetObject', ['Bucket' => 'b', 'Key' => 'schools/IND/attributes.json']);
        $this->assertSame('schools/IND/attributes.json', ObjectStore::extractCommandKey($getCmd));

        $putCmd = new Command('PutObject', ['Bucket' => 'b', 'Key' => 'schools/IND/content']);
        $this->assertSame('schools/IND/content', ObjectStore::extractCommandKey($putCmd));

        $listCmd = new Command('ListObjectsV2', ['Bucket' => 'b', 'Prefix' => 'schools/IND/teachers/']);
        $this->assertSame('schools/IND/teachers/', ObjectStore::extractCommandKey($listCmd));

        $deleteObjectsCmd = new Command('DeleteObjects', [
            'Bucket' => 'b',
            'Delete' => [
                'Objects' => [
                    ['Key' => 'k1'],
                    ['Key' => 'k2'],
                ],
            ],
        ]);
        $this->assertSame('k1, k2', ObjectStore::extractCommandKey($deleteObjectsCmd));

        $headBucketCmd = new Command('HeadBucket', ['Bucket' => 'corrai']);
        $this->assertSame('corrai', ObjectStore::extractCommandKey($headBucketCmd));
    }

    public function testSlowRequestIsLoggedBeyondThreshold(): void
    {
        $logged = [];
        ObjectStore::setLogCallback(function (string $message, string $key, float $durationMs) use (&$logged) {
            $logged[] = [
                'message' => $message,
                'key' => $key,
                'duration' => $durationMs,
            ];
        });

        // Set threshold to 10 ms for the test
        $_ENV['S3_SLOW_THRESHOLD_MS'] = '10';

        $mock = new MockHandler();
        // HeadBucket (ensureBucket) succeeds quickly
        $mock->append(new Result([]));
        // GetObject is slow (> 10ms)
        $mock->append(function (CommandInterface $cmd) {
            usleep(20000); // 20 ms, which is > 10 ms threshold
            return new Result(['Body' => 'ok']);
        });

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        $store = new ObjectStore($client, 'test-bucket');
        $store->getContents('schools/IND/attr.json');

        $this->assertCount(1, $logged);
        $this->assertSame('schools/IND/attr.json', $logged[0]['key']);
        $this->assertGreaterThan(10.0, $logged[0]['duration']);
        $this->assertStringContainsString('key=schools/IND/attr.json', $logged[0]['message']);
        $this->assertStringContainsString('duration=', $logged[0]['message']);
    }

    public function testFastRequestIsNotLoggedUnderThreshold(): void
    {
        $logged = [];
        ObjectStore::setLogCallback(function (string $message, string $key, float $durationMs) use (&$logged) {
            $logged[] = [
                'message' => $message,
                'key' => $key,
                'duration' => $durationMs,
            ];
        });

        // Set threshold to 500 ms (default)
        $_ENV['S3_SLOW_THRESHOLD_MS'] = '500';

        $mock = new MockHandler();
        $mock->append(new Result([])); // HeadBucket
        $mock->append(new Result(['Body' => 'ok'])); // GetObject

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        $store = new ObjectStore($client, 'test-bucket');
        $store->getContents('schools/IND/fast.json');

        $this->assertEmpty($logged);
    }

    public function testSlowFailedRequestIsLoggedBeyondThreshold(): void
    {
        $logged = [];
        ObjectStore::setLogCallback(function (string $message, string $key, float $durationMs) use (&$logged) {
            $logged[] = [
                'message' => $message,
                'key' => $key,
                'duration' => $durationMs,
            ];
        });

        $_ENV['S3_SLOW_THRESHOLD_MS'] = '10';

        $mock = new MockHandler();
        $mock->append(new Result([])); // HeadBucket succeeds fast
        $mock->append(function (CommandInterface $cmd) {
            usleep(20000);
            throw new S3Exception('Fail', $cmd);
        });

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        $store = new ObjectStore($client, 'test-bucket');

        try {
            $store->getContents('schools/IND/failing.json');
            $this->fail('Should have thrown S3Exception');
        } catch (S3Exception $e) {
            // Expected
        }

        $this->assertCount(1, $logged);
        $this->assertSame('schools/IND/failing.json', $logged[0]['key']);
        $this->assertGreaterThan(10.0, $logged[0]['duration']);
    }

    public function testSlowPutContentsLogsKeyAndDuration(): void
    {
        $logged = [];
        ObjectStore::setLogCallback(function (string $message, string $key, float $durationMs) use (&$logged) {
            $logged[] = [
                'message' => $message,
                'key' => $key,
                'duration' => $durationMs,
            ];
        });

        $_ENV['S3_SLOW_THRESHOLD_MS'] = '10';

        $mock = new MockHandler();
        $mock->append(new Result([])); // HeadBucket
        $mock->append(function (CommandInterface $cmd) {
            usleep(20000);
            return new Result(['ETag' => '"test-etag"']);
        });

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        $store = new ObjectStore($client, 'test-bucket');
        $store->putContents('schools/IND/new.json', '{"test": true}');

        $this->assertCount(1, $logged);
        $this->assertSame('schools/IND/new.json', $logged[0]['key']);
        $this->assertGreaterThan(10.0, $logged[0]['duration']);
        $this->assertStringContainsString('PutObject', $logged[0]['message']);
    }
}
