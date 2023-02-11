<?php
/**
 * @author André Gaul <gaul@web-yard.de>
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Christian Berendt <berendt@b1-systems.de>
 * @author Christopher T. Johnson <ctjctj@gmail.com>
 * @author Johan Björk <johanimon@gmail.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Martin Mattel <martin.mattel@diemattels.at>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Philipp Kapfer <philipp.kapfer@gmx.at>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH.
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */

namespace OCA\FilesExternalS3\Storage;

use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Middleware;
use Icewind\Streams\IteratorDirectory;
use OCP\Files\Storage\StorageAdapter;
use OCP\Files\StorageNotAvailableException;
use OCP\ILogger;
use OCP\ITempManager;
use Psr\Http\Message\RequestInterface;

class AmazonS3 extends StorageAdapter {

	/**
	 * @var \Aws\S3\S3Client
	 */
	private $connection;
	/**
	 * @var string
	 */
	private $bucket;
	/**
	 * @var array
	 */
	private static $tmpFiles = [];
	/**
	 * @var array
	 */
	private $params;
	/**
	 * @var bool
	 */
	private $test = false;
	/**
	 * @var int
	 */
	private $timeout = 15;
	/**
	 * @var int in seconds
	 */
	private $rescanDelay = 10;

	/** @var string */
	private $id;

	/** @var ILogger */
	private $logger;

	/**
	 * @var ITempManager
	 */
	private $tempManager;

	/**
	 * @param string $path
	 * @return string correctly encoded path
	 */
	private function normalizePath($path): string {
		$path = \trim($path, '/');

		if (!$path) {
			$path = '.';
		}

		return $path;
	}

	/**
	 * when running the tests wait to let the buckets catch up
	 */
	private function testTimeout(): void {
		if ($this->test) {
			\sleep($this->timeout);
		}
	}

	private function isRoot($path): bool {
		return $path === '.';
	}

	private function cleanKey($path): string {
		if ($this->isRoot($path)) {
			return '/';
		}
		return $path;
	}

	/**
	 * AmazonS3 constructor.
	 *
	 * @param array $params
	 *
	 * @throws \Exception
	 */
	public function __construct($params) {
		if (empty($params['key']) || empty($params['secret']) || empty($params['bucket'])) {
			throw new \Exception('Access Key, Secret and Bucket have to be configured.');
		}

		$this->id = 'amazon::' . $params['bucket'];

		$this->bucket = $params['bucket'];
		$this->test = isset($params['test']);
		$this->timeout = $params['timeout'] ?? 15;
		$this->rescanDelay = $params['rescanDelay'] ?? 10;
		$params['region'] = empty($params['region']) ? 'eu-west-1' : $params['region'];
		$params['hostname'] = empty($params['hostname']) ? 's3.amazonaws.com' : $params['hostname'];
		if (!isset($params['port']) || $params['port'] === '') {
			$params['port'] = ($params['use_ssl'] === false) ? 80 : 443;
		}
		$this->params = $params;
		$this->logger = \OC::$server->getLogger();
		$this->tempManager = \OC::$server->getTempManager();
	}

	/**
	 * Remove a file or folder
	 *
	 * @param string $path
	 * @return bool
	 * @throws StorageNotAvailableException
	 */
	protected function remove($path) {
		// remember fileType to reduce http calls
		$fileType = $this->filetype($path);
		if ($fileType === 'dir') {
			return $this->rmdir($path);
		}

		if ($fileType === 'file') {
			return $this->unlink($path);
		}

		return false;
	}

	public function mkdir($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return false;
		}

		try {
			$this->getConnection()->putObject([
				'Bucket' => $this->bucket,
				'Key' => $path . '/',
				'Body' => '',
				'ContentType' => 'httpd/unix-directory'
			]);
			$this->testTimeout();
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}

		return true;
	}

	public function file_exists($path) {
		return $this->filetype($path) !== false;
	}

	public function rmdir($path) {
		$path = $this->normalizePath($path);

		if ($this->isRoot($path)) {
			return $this->clearBucket();
		}

		if (!$this->file_exists($path)) {
			return false;
		}

		return $this->batchDelete($path);
	}

	protected function clearBucket() {
		try {
			$this->getConnection()->deleteMatchingObjects($this->bucket);
			return true;
			// clearBucket() is not working with Ceph, so if it fails we try the slower approach
		} catch (\Exception $e) {
			return $this->batchDelete();
		}
	}

	/**
	 * @throws \Exception
	 */
	private function batchDelete($path = null) {
		$params = [
			'Bucket' => $this->bucket
		];
		if ($path !== null) {
			$params['Prefix'] = $path . '/';
		}
		try {
			// Since there are no real directories on S3, we need
			// to delete all objects prefixed with the path.
			do {
				// instead of the iterator, manually loop over the list ...
				$objects = $this->getConnection()->listObjects($params);
				/** @phan-suppress-next-line PhanDeprecatedFunction */
				$keys = $objects->getPath('Contents[].Key');
				if (empty($keys)) {
					continue;
				}
				$keys = \array_map(static function ($key) {
					return ['Key' => $key];
				}, $keys);
				// ... so we can delete the files in batches
				$this->getConnection()->deleteObjects([
					'Bucket' => $this->bucket,
					'Delete' => [
						'Objects' => $keys
					]
				]);
				$this->testTimeout();
				// we reached the end when the list is no longer truncated
			} while ($objects['IsTruncated']);
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}
		return true;
	}

	public function opendir($path) {
		$path = $this->normalizePath($path);

		if ($this->isRoot($path)) {
			$path = '';
		} else {
			$path .= '/';
		}

		try {
			$files = [];
			$result = $this->getConnection()->getPaginator('ListObjects', [
				'Bucket' => $this->bucket,
				'Delimiter' => '/',
				'Prefix' => $path
			]);

			foreach ($result->search('[CommonPrefixes[].Prefix, Contents[].Key][]') as $object) {
				if (\is_string($object)) {
					if ($object === $path) {
						// it's the directory itself, skip
						continue;
					}
					$file = \basename($object);
					$files[] = $file;
				}
			}

			return IteratorDirectory::wrap($files);
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}
	}

	public function stat($path) {
		$path = $this->normalizePath($path);

		try {
			$stat = [];
			if ($this->is_dir($path)) {
				//folders don't really exist
				$stat['size'] = -1; //unknown
				$stat['mtime'] = \time() - $this->rescanDelay * 1000;
			} else {
				$result = $this->getConnection()->headObject([
					'Bucket' => $this->bucket,
					'Key' => $path
				]);

				$stat['size'] = $result['ContentLength'] ?: 0;
				if (isset($result['Metadata']['lastmodified'])) {
					// The underlying type doc for headObject says that it is an \Aws\Result
					// And phan understands that Result should have only 1 level
					// of array keys that are strings. But actually it can have 2 levels.
					/** @phan-suppress-next-line PhanTypeMismatchDimFetch */
					$stat['mtime'] = \strtotime($result['Metadata']['lastmodified']);
				} else {
					$stat['mtime'] = \strtotime($result['LastModified']);
				}
			}
			$stat['atime'] = \time();

			return $stat;
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}
	}

	public function filetype($path) {
		$path = $this->normalizePath($path);

		if ($this->isRoot($path)) {
			return 'dir';
		}

		try {
			if ($this->getConnection()->doesObjectExist($this->bucket, $path)) {
				return 'file';
			}
			if ($this->getConnection()->doesObjectExist($this->bucket, $path.'/')) {
				return 'dir';
			}
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}

		return false;
	}

	/**
	 * @throws StorageNotAvailableException
	 */
	public function unlink($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return $this->rmdir($path);
		}

		try {
			$this->getConnection()->deleteObject([
				'Bucket' => $this->bucket,
				'Key' => $path
			]);
			$this->testTimeout();
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}

		return true;
	}

	public function fopen($path, $mode) {
		$path = $this->normalizePath($path);

		switch ($mode) {
			case 'r':
			case 'rb':
				$tmpFile = $this->tempManager->getTemporaryFile();
				self::$tmpFiles[$tmpFile] = $path;

				try {
					$this->getConnection()->getObject([
						'Bucket' => $this->bucket,
						'Key' => $path,
						'SaveAs' => $tmpFile
					]);
				} catch (S3Exception $e) {
					$this->logger->logException($e, ['app'=>'files_external']);
					return false;
				}

				return \fopen($tmpFile, 'rb');
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				if (\strrpos($path, '.') !== false) {
					$ext = \substr($path, \strrpos($path, '.'));
				} else {
					$ext = '';
				}
				$tmpFile = $this->tempManager->getTemporaryFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, [$this, 'writeBack']);
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'r');
					\file_put_contents($tmpFile, $source);
				}
				self::$tmpFiles[$tmpFile] = $path;

				return \fopen('close://' . $tmpFile, $mode);
		}
		return false;
	}

	public function touch($path, $mtime = null) {
		$path = $this->normalizePath($path);

		if ($mtime === null) {
			$mtime = \time();
		}
		$metadata = [
			'lastmodified' => \gmdate(DateTimeInterface::RFC1123, $mtime)
		];

		$fileType = $this->filetype($path);
		try {
			if ($fileType !== false) {
				if ($fileType === 'dir' && ! $this->isRoot($path)) {
					$path .= '/';
				}
				$this->getConnection()->copyObject([
					'Bucket' => $this->bucket,
					'Key' => $this->cleanKey($path),
					'Metadata' => $metadata,
					'CopySource' => $this->bucket . '/' . $path,
					'MetadataDirective' => 'REPLACE',
				]);
				$this->testTimeout();
			} else {
				$mimeType = \OC::$server->getMimeTypeDetector()->detectPath($path);
				$this->getConnection()->putObject([
					'Bucket' => $this->bucket,
					'Key' => $this->cleanKey($path),
					'Metadata' => $metadata,
					'Body' => '',
					'ContentType' => $mimeType,
					'MetadataDirective' => 'REPLACE',
				]);
				$this->testTimeout();
			}
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}

		return true;
	}

	public function copy($path1, $path2) {
		$path1 = $this->normalizePath($path1);
		$path2 = $this->normalizePath($path2);

		if ($this->is_file($path1)) {
			try {
				$this->getConnection()->copyObject([
					'Bucket' => $this->bucket,
					'Key' => $this->cleanKey($path2),
					'CopySource' => S3Client::encodeKey($this->bucket . '/' . $path1)
				]);
				$this->testTimeout();
			} catch (S3Exception $e) {
				$this->logger->logException($e, ['app'=>'files_external']);
				return false;
			}
		} else {
			$this->remove($path2);

			try {
				$this->getConnection()->copyObject([
					'Bucket' => $this->bucket,
					'Key' => $path2 . '/',
					'CopySource' => S3Client::encodeKey($this->bucket . '/' . $path1 . '/')
				]);
				$this->testTimeout();
			} catch (S3Exception $e) {
				$this->logger->logException($e, ['app'=>'files_external']);
				return false;
			}

			$dh = $this->opendir($path1);
			if (\is_resource($dh)) {
				while (($file = \readdir($dh)) !== false) {
					if (\OC\Files\Filesystem::isIgnoredDir($file)) {
						continue;
					}

					$source = $path1 . '/' . $file;
					$target = $path2 . '/' . $file;
					$this->copy($source, $target);
				}
			}
		}

		return true;
	}

	public function rename($path1, $path2) {
		$path1 = $this->normalizePath($path1);
		$path2 = $this->normalizePath($path2);

		if ($this->is_file($path1)) {
			if ($this->copy($path1, $path2) === false) {
				return false;
			}

			if ($this->unlink($path1) === false) {
				$this->unlink($path2);
				return false;
			}
		} else {
			if ($this->copy($path1, $path2) === false) {
				return false;
			}

			if ($this->rmdir($path1) === false) {
				$this->rmdir($path2);
				return false;
			}
		}

		return true;
	}

	/**
	 * @throws \Exception
	 */
	public function test() {
		if ($this->getConnection()->getApi()->hasOperation('GetBucketAcl')) {
			$test = $this->getConnection()->getBucketAcl([
				'Bucket' => $this->bucket,
			]);
			/** @phan-suppress-next-line PhanDeprecatedFunction */
			if ($test !== null && $test->getPath('Owner/ID') !== null) {
				return true;
			}
			return false;
		}

		$buckets = $this->getConnection()->listBuckets();
		/** @phan-suppress-next-line PhanDeprecatedFunction */
		if ($buckets->getPath('Owner/ID') === null) {
			return false;
		}
		/** @phan-suppress-next-line PhanDeprecatedFunction */
		$bucketExists = !empty(\array_filter($buckets->getPath('Buckets'), function ($k) {
			return $k['Name'] === $this->bucket;
		}));
		return $bucketExists;
	}

	public function getId() {
		return $this->id;
	}

	/**
	 * Returns the connection
	 *
	 * @return S3Client connected client
	 * @throws \Exception if connection could not be made
	 */
	public function getConnection(): S3Client {
		if ($this->connection !== null) {
			return $this->connection;
		}

		$scheme = ($this->params['use_ssl'] === false) ? 'http' : 'https';
		$base_url = $scheme . '://' . $this->params['hostname'] . ':' . $this->params['port'] . '/';

		$config = [
			'version' => '2006-03-01',
			'region' => $this->params['region'],
//			'signature_version' => 'v4',
			'credentials' => [
				'key' => $this->params['key'],
				'secret' => $this->params['secret'],
			],
			'endpoint' => $base_url,
			'use_path_style_endpoint' => $this->params['use_path_style'],
		];
		// Create a handler stack that has all the default middlewares attached
		$handler = \GuzzleHttp\HandlerStack::create(new CurlMultiHandler());
		// Push the handler onto the handler stack
		$handler->push(Middleware::mapRequest(static function (RequestInterface $request) {
			if ($request->getMethod() !== 'PUT') {
				return $request;
			}
			$body = $request->getBody();
			if ($body !== null && $body->getSize() !== 0) {
				return $request;
			}
			if ($request->hasHeader('Content-Length')) {
				return $request;
			}
			// force content length header on empty body
			return $request->withHeader('Content-Length', '0');
		}));
		// Inject the handler into the client
		$client = new \GuzzleHttp\Client(['handler' => $handler]);
		$h = new GuzzleHandler($client);
		$config['http_handler'] = $h;

		$this->connection = new S3Client($config);

		if (!$this->connection->doesBucketExist($this->bucket)) {
			try {
				$this->connection->createBucket([
					'Bucket' => $this->bucket
				]);
				/* @phan-suppress-next-line PhanUndeclaredFunctionInCallable */
				$this->connection->waitUntil('BucketExists', [
					'Bucket' => $this->bucket,
					'waiter.interval' => 1,
					'waiter.max_attempts' => 15
				]);
				$this->testTimeout();
			} catch (S3Exception $e) {
				$this->logger->logException($e, ['app'=>'files_external']);
				throw new \Exception('Creation of bucket failed. '.$e->getMessage());
			}
		}

		return $this->connection;
	}

	/**
	 * @throws \Exception
	 */
	public function writeBack($tmpFile) {
		if (!isset(self::$tmpFiles[$tmpFile])) {
			return false;
		}

		try {
			$this->getConnection()->putObject([
				'Bucket' => $this->bucket,
				'Key' => $this->cleanKey(self::$tmpFiles[$tmpFile]),
				'SourceFile' => $tmpFile,
				'ContentType' => \OC::$server->getMimeTypeDetector()->detect($tmpFile),
				'ContentLength' => \filesize($tmpFile)
			]);
			$this->testTimeout();

			\unlink($tmpFile);
		} catch (S3Exception $e) {
			$this->logger->logException($e, ['app'=>'files_external']);
			return false;
		}
	}

	/**
	 * check if curl is installed
	 */
	public static function checkDependencies() {
		return true;
	}

	public function usePartFile() {
		return false;
	}
}
