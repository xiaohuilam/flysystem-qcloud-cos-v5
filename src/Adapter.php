<?php

namespace Freyo\Flysystem\QcloudCOSv5;

use Carbon\Carbon;
use DateTimeInterface;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;
use Zend\Validator\File\FilesSize;

/**
 * Class Adapter.
 */
class Adapter implements FilesystemAdapter
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var array
     */
    protected $regionMap = [
        'cn-east'      => 'ap-shanghai',
        'cn-sorth'     => 'ap-guangzhou',
        'cn-north'     => 'ap-beijing-1',
        'cn-south-2'   => 'ap-guangzhou-2',
        'cn-southwest' => 'ap-chengdu',
        'sg'           => 'ap-singapore',
        'tj'           => 'ap-beijing-1',
        'bj'           => 'ap-beijing',
        'sh'           => 'ap-shanghai',
        'gz'           => 'ap-guangzhou',
        'cd'           => 'ap-chengdu',
        'sgp'          => 'ap-singapore',
    ];

    /**
     * Adapter constructor.
     *
     * @param Client $client
     * @param array  $config
     */
    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getBucketWithAppId()
    {
        return $this->getBucket() . '-' . $this->getAppId();
    }

    /**
     * @return string
     */
    public function getBucket()
    {
        return preg_replace(
            "/-{$this->getAppId()}$/",
            '',
            $this->config['bucket']
        );
    }

    /**
     * @return string
     */
    public function getAppId()
    {
        return $this->config['credentials']['appId'];
    }

    /**
     * @return string
     */
    public function getRegion()
    {
        return array_key_exists($this->config['region'], $this->regionMap)
            ? $this->regionMap[$this->config['region']] : $this->config['region'];
    }

    /**
     * @param $path
     *
     * @return string
     */
    public function getSourcePath($path)
    {
        return sprintf(
            '%s.cos.%s.myqcloud.com/%s',
            $this->getBucketWithAppId(),
            $this->getRegion(),
            $path
        );
    }

    /**
     * @param $path
     *
     * @return string
     */
    public function getPicturePath($path)
    {
        return sprintf(
            '%s.pic.%s.myqcloud.com/%s',
            $this->getBucketWithAppId(),
            $this->getRegion(),
            $path
        );
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $options = [
            'Scheme' => isset($this->config['scheme']) ? $this->config['scheme'] : 'http',
        ];

        $objectUrl = $this->client->getObjectUrl(
            $this->getBucketWithAppId(),
            $path,
            "+30 minutes",
            $options
        );

        if (isset($this->config['cdn']) && $this->config['cdn']) {
            $parser = new Uri($objectUrl);
            $cdnParser = new Uri($this->config['cdn']);

            $objectUrl = $parser
                ->withHost($cdnParser->getHost())
                ->withScheme($cdnParser->getScheme())
                ->__toString();
        }

        return $objectUrl;
    }

    /**
     * @param string             $path
     * @param \DateTimeInterface $expiration
     * @param array              $options
     *
     * @return string
     */
    public function getTemporaryUrl($path, DateTimeInterface $expiration, array $options = [])
    {
        $options = array_merge(
            $options,
            ['Scheme' => isset($this->config['scheme']) ? $this->config['scheme'] : 'http']
        );

        $objectUrl = $this->client->getObjectUrl(
            $this->getBucketWithAppId(),
            $path,
            $expiration->format('c'),
            $options
        );

        if (isset($this->config['cdn']) && $this->config['cdn']) {
            $parser = new Uri($objectUrl);
            $cdnParser = new Uri($this->config['cdn']);

            $objectUrl = $parser
                ->withHost($cdnParser->getHost())
                ->withScheme($cdnParser->getScheme())
                ->__toString();
        }

        return (string) $objectUrl;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->client->upload(
            $this->getBucketWithAppId(),
            $path,
            $contents,
            $this->prepareUploadConfig($config)
        );
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->client->upload(
            $this->getBucketWithAppId(),
            $path,
            stream_get_contents($contents, -1, 0),
            $this->prepareUploadConfig($config)
        );
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function move(string $source, string $destination, Config $config): void
    {
        if ($this->copy($source, $destination, $config)) {
            $this->delete($source);
        }
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->client->copyObject([
            'Bucket'     => $this->getBucketWithAppId(),
            'Key'        => $destination,
            'CopySource' => $this->getSourcePath($source),
        ]);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path,
        ]);
    }

    /**
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDirectory(string $path): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path . '/',
        ]);
    }

    /**
     * @param string $dirname
     * @param Config $config
     *
     * @return array|false
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->client->putObject([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path . '/',
            'Body'   => '',
        ]);
    }

    /**
     * @param string $path
     * @param string $visibility
     *
     * @return bool
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $this->client->putObjectAcl([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path,
            'ACL'    => $this->normalizeVisibility($visibility),
        ]);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        try {
            return (bool) $this->getMetadata($path);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function read(string $path): string
    {
        try {
            $response = $this->readFromSource($path);

            return ['contents' => (string) $response];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param $path
     *
     * @return string
     */
    protected function readFromSource($path)
    {
        try {
            $response = $this->client->getObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);

            return $response['Body'];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient()
    {
        return new \GuzzleHttp\Client([
            'timeout'         => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
        ]);
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function readStream($path)
    {
        try {
            $temporaryUrl = $this->getTemporaryUrl($path, Carbon::now()->addMinutes(5));

            $stream = $this->getHttpClient()
                ->get($temporaryUrl, ['stream' => true])
                ->getBody()
                ->detach();

            return ['stream' => $stream];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array|bool
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $list = [];

        $marker = '';
        while (true) {
            $response = $this->listObjects($path, $deep, $marker);

            foreach ((array) $response['Contents'] as $content) {
                $list[] = $this->normalizeFileInfo($content);
            }

            if (!$response['IsTruncated']) {
                break;
            }
            $marker = $response['NextMarker'] ?: '';
        }

        return $list;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMetadata($path)
    {
        try {
            return $this->client->headObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        $size = isset($meta['ContentLength'])
            ? ['size' => $meta['ContentLength']] : false;

        return new FileAttributes($path, $size);
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        return new FileAttributes($path, null, null, null, $meta['mimetype']);
    }

    /**
     * @param array $content
     *
     * @return array
     */
    private function normalizeFileInfo(array $content)
    {
        $path = pathinfo($content['Key']);

        return [
            'type'      => substr($content['Key'], -1) === '/' ? 'dir' : 'file',
            'path'      => $content['Key'],
            'timestamp' => Carbon::parse($content['LastModified'])->getTimestamp(),
            'size'      => (int) $content['Size'],
            'dirname'   => $path['dirname'] === '.' ? '' : (string) $path['dirname'],
            'basename'  => (string) $path['basename'],
            'extension' => isset($path['extension']) ? $path['extension'] : '',
            'filename'  => (string) $path['filename'],
        ];
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     * @param string $marker    max return 1000 record, if record greater than 1000
     *                          you should set the next marker to get the full list
     *
     * @return \GuzzleHttp\Command\Result|array
     */
    private function listObjects($directory = '', $recursive = false, $marker = '')
    {
        try {
            return $this->client->listObjects([
                'Bucket'    => $this->getBucketWithAppId(),
                'Prefix'    => ((string) $directory === '') ? '' : ($directory . '/'),
                'Delimiter' => $recursive ? '' : '/',
                'Marker'    => $marker,
                'MaxKeys'   => 1000,
            ]);
        } catch (ServiceResponseException $e) {
            return [
                'Contents'    => [],
                'IsTruncated' => false,
                'NextMarker'  => '',
            ];
        }
    }

    /**
     * @param Config $config
     *
     * @return array
     */
    private function prepareUploadConfig($config)
    {
        $options = [];

        if (isset($this->config['encrypt']) && $this->config['encrypt']) {
            $options['ServerSideEncryption'] = 'AES256';
        }

        if ($config->get('params')) {
            $options = array_merge($options, $config->get('params'));
        }

        if ($config->get('visibility')) {
            $options['ACL'] = $this->normalizeVisibility($config->get('visibility'));
        }

        return $options;
    }

    /**
     * @param $visibility
     *
     * @return string
     */
    private function normalizeVisibility($visibility)
    {
        switch ($visibility) {
            case Visibility::PUBLIC:
                $visibility = 'public-read';
                break;
        }

        return $visibility;
    }

    /**
     * @return Client
     */
    public function getCOSClient()
    {
        return $this->client;
    }

    /**
     * @param $method
     * @param $url
     *
     * @return string
     */
    public function getAuthorization($method, $url)
    {
        $cosRequest = new \GuzzleHttp\Psr7\Request($method, $url);

        $parser = new \GuzzleHttp\Psr7\Uri($url);
        $signature = new \Qcloud\Cos\Signature(
            $this->config['credentials']['secretId'],
            $this->config['credentials']['secretKey'],
            $parser->getHost()
        );

        return $signature->createAuthorization($cosRequest);
    }


    /**
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function fileExists(string $path): bool
    {
        return true;
    }

    /**
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function directoryExists(string $path): bool
    {
        return !!$this->client->HeadObject([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path . '/',
        ]);;
    }

    public function visibility(string $path): FileAttributes
    {
        $meta = $this->client->getObjectAcl([
            'Bucket' => $this->getBucketWithAppId(),
            'Key'    => $path,
        ]);

        foreach ($meta['Grants'] as $grant) {
            if (
                isset($grant['Grantee']['URI'])
                && $grant['Permission'] === 'READ'
                && strpos($grant['Grantee']['URI'], 'global/AllUsers') !== false
            ) {
                $visibility = Visibility::PUBLIC;
            }
        }

        $visibility = Visibility::PRIVATE;

        return new FileAttributes(
            $path,
            null,
            $visibility
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        $lastModifiedTime = isset($meta['LastModified'])
            ? ['timestamp' => strtotime($meta['LastModified'])] : false;

        return new FileAttributes($path, null, null, $lastModifiedTime);
    }
}
