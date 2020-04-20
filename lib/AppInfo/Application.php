<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OCA\FilesExternalS3\AppInfo;

use OCA\FilesExternalS3\Auth\AccessKey;
use OCA\FilesExternalS3\Backend\AmazonS3;
use OCP\AppFramework\App;
use OCP\Files\External\Config\IAuthMechanismProvider;
use OCP\Files\External\Config\IBackendProvider;

/**
 * @package OCA\FilesExternalS3\AppInfo
 */
class Application extends App implements IBackendProvider, IAuthMechanismProvider {
	public function __construct(array $urlParams = []) {
		parent::__construct('files_external_s3', $urlParams);

		$container = $this->getContainer();

		/** @var \OC\Server $server */
		$server= $container->getServer();
		/* @phan-suppress-next-line PhanUndeclaredMethod */
		$backendService = $server->getStoragesBackendService();
		$backendService->registerBackendProvider($this);
		$backendService->registerAuthMechanismProvider($this);
	}

	/**
	 * @{inheritdoc}
	 */
	public function getBackends() {
		$container = $this->getContainer();

		$backends = [
			$container->query(AmazonS3::class)
		];
		return $backends;
	}

	/**
	 * @{inheritdoc}
	 */
	public function getAuthMechanisms() {
		$container = $this->getContainer();
		return [
			$container->query(AccessKey::class)
		];
	}
}
