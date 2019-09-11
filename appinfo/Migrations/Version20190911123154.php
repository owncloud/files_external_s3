<?php
/**
 * @author Sujith Haridasan <sharidasan@owncloud.com>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\files_external_s3\Migrations;

use OCP\Migration\ISimpleMigration;
use OCP\Migration\IOutput;

/**
 * Update amazons3 in the appconfig to files_external_s3
 */
class Version20190911123154 implements ISimpleMigration {
	/**
	 * @param IOutput $out
	 */
	public function run(IOutput $out) {
		$userMountBackends = \OC::$server->getConfig()->getAppValue('files_external', 'user_mounting_backends', '');
		if ($userMountBackends !== '') {
			$mountBackendArray = \explode(',', $userMountBackends);
			/**
			 * Search for amazons3, and replace it to files_external_s3.
			 */
			foreach ($mountBackendArray as $key => $mountBackend) {
				if ($mountBackend === 'amazons3') {
					$mountBackendArray[$key] = 'files_external_s3';
					break;
				}
			}
			$userMountBackends = \implode(',', $mountBackendArray);
			\OC::$server->getConfig()->setAppValue('files_external', 'user_mounting_backends', $userMountBackends);
		}
	}
}
